<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\PoliticaDeDescuentoComercial;
use App\Services\RegistradorDeAutorizacion;
use App\Services\RegistradorDeCargo;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Support\Str;

/**
 * EL DESCUENTO DE TERCERA Y CUARTA EDAD SEGÚN QUIÉN PAGA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DECISIÓN DEL HOSPITAL (9-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Hospital Militar → sobre el TOTAL de la factura: cada línea con el
 *     porcentaje del Art. 30 de su categoría, y lo que aprueba el
 *     Militar se resta después.
 *   · Aseguradoras privadas → NO aplican el descuento de ley.
 *   · Particular → como siempre.
 *
 * Cada pagador lo declara en su convenio; ninguna de estas pruebas
 * depende de un nombre de aseguradora. Es configuración, no código.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA LÍNEA ROJA QUE ESTE ARCHIVO CUIDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Con un pagador que no aplica el Art. 30, el adulto mayor recibe 0 %
 * de ley. Si el tope del descuento del HOSPITAL siguiera siendo el de
 * su edad —10 % la tercera, 0 % la cuarta—, quedaría pagando más que un
 * joven del mismo seguro, al que se le pueden dar 30 %. El techo total
 * es de la persona, lo pague quien lo pague: 30 % el joven, 35 % la
 * tercera edad, 40 % la cuarta, en medicamentos.
 */
beforeEach(function (): void {
    (new DescuentosLegalesSeeder)->run();
});

/**
 * Una aseguradora privada que no acepta el descuento de ley.
 */
function unaAseguradoraQueNoAplica(string $cobertura = '0.5000'): Convenio
{
    return Convenio::factory()
        ->de(TipoConvenio::AseguradoraPrivada)
        ->conBase(BaseDelDescuentoLegal::NoAplica)
        ->create([
            'cobertura_fraccion'    => $cobertura,
            'cubre_por_defecto'     => true,
            'requiere_autorizacion' => false,
        ]);
}

/**
 * Un pagador institucional que descuenta sobre el total, como el
 * Militar. Cobertura cero por defecto: el Militar aprueba un MONTO por
 * caso, no un porcentaje.
 */
function elMilitarSobreElTotal(string $cobertura = '0.0000'): Convenio
{
    return Convenio::factory()
        ->de(TipoConvenio::Institucional)
        ->conBase(BaseDelDescuentoLegal::SobreElTotalFacturado)
        ->create([
            'cobertura_fraccion'    => $cobertura,
            'cubre_por_defecto'     => true,
            'requiere_autorizacion' => false,
        ]);
}

/**
 * Un ítem exento con precio de lista, de la categoría del Art. 30 que
 * se pida.
 *
 * Tipo servicio siempre, a propósito: el descuento de ley se resuelve
 * por la CATEGORÍA y no por el tipo, así que esto prueba lo mismo sin
 * arrastrar almacén, existencia ni unidad de dispensación.
 *
 * @param numeric-string $precio
 */
function itemDeLaCategoria(CategoriaLegalDeDescuento $categoria, string $precio): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => $categoria,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * Carga una unidad, con o sin descuento del hospital.
 *
 * @param string|null $rebaja la fracción: «0.35» es 35 %
 */
function cargarConRebaja(Cuenta $cuenta, Item $item, ?string $rebaja = null): Cargo
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercialPorcentaje: $rebaja === null ? null : Decimal::de($rebaja),
        motivoDescuento: $rebaja === null ? null : 'Paciente frecuente del hospital',
    ))->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Hospital Militar — sobre el total
|--------------------------------------------------------------------------
*/

it('🔴 el militar descuenta sobre el total y lo aprobado se resta despues, al centavo', function (): void {
    $cuenta = unaCuentaCon(elMilitarSobreElTotal(), edad: 68);

    $cargo = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::ServicioHospitalario, '10000.0000'),
    );

    expect($cargo->bruto)->toBe('10000.00')
        ->and($cargo->descuento_legal)->toBe('2500.00')
        ->and($cargo->subtotal)->toBe('7500.00')
        ->and($cargo->total)->toBe('7500.00')
        ->and($cargo->base_descuento_legal)->toBe(BaseDelDescuentoLegal::SobreElTotalFacturado->value);

    /* El Militar aprobó L 6,000 de esta cuenta. */
    $cuenta = app(RegistradorDeAutorizacion::class)->registrar($cuenta->refresh(), null, Monto::de('6000.00'));

    expect($cuenta->total)->toBe('7500.00')
        ->and($cuenta->total_aseguradora)->toBe('6000.00')
        ->and($cuenta->total_paciente)->toBe('1500.00');
})->note('El ejemplo del hospital: L 10,000 al 25 % → L 2,500 de descuento → L 7,500 → el Militar L 6,000 y el paciente L 1,500. Si se calculara sobre lo que paga el paciente, pagaría L 3,000.');

it('sobre el total quiere decir linea por linea, cada una con su porcentaje del art 30', function (): void {
    $cuenta = unaCuentaCon(elMilitarSobreElTotal(), edad: 68);

    $estancia = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::ServicioHospitalario, '1000.0000'),
    );
    $examen = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '1000.0000'),
    );

    expect($estancia->descuento_legal)->toBe('250.00')
        ->and($examen->descuento_legal)->toBe('300.00')
        ->and($cuenta->refresh()->total_descuento)->toBe('550.00')
        ->and($cuenta->total)->toBe('1450.00');
})->note('Un 25 % parejo sobre los L 2,000 daría 500; la ley da 550, porque el laboratorio va al 30 %. Ni más ni menos de lo que manda cada numeral.');

it('el militar sigue descontando sobre el total aunque algun dia cubra un porcentaje', function (): void {
    $cuenta = unaCuentaCon(elMilitarSobreElTotal('0.5000'), edad: 68);

    $cargo = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::ServicioHospitalario, '10000.0000'),
    );

    expect($cargo->descuento_legal)->toBe('2500.00')
        ->and($cargo->total)->toBe('7500.00')
        ->and($cargo->porcion_aseguradora)->toBe('3750.00')
        ->and($cargo->porcion_paciente)->toBe('3750.00');
})->note('Con la base que tenía antes —sobre lo que paga el paciente— este mismo cargo daba L 1,250 de descuento, la mitad. Hoy no se nota porque la cobertura del Militar es 0 %; el día que alguien le cargara un porcentaje, el descuento se achicaba sin aviso.');

/*
|--------------------------------------------------------------------------
| Aseguradoras — no aplican el Art. 30
|--------------------------------------------------------------------------
*/

it('🔴 la aseguradora que no aplica el art 30 cobra sin descuento de tercera edad', function (): void {
    $cuenta = unaCuentaCon(unaAseguradoraQueNoAplica(), edad: 68);

    $cargo = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::ServicioHospitalario, '10000.0000'),
    );

    expect($cargo->descuento_legal)->toBe('0.00')
        ->and($cargo->total)->toBe('10000.00')
        ->and($cargo->porcion_aseguradora)->toBe('5000.00')
        ->and($cargo->porcion_paciente)->toBe('5000.00')
        ->and($cargo->base_descuento_legal)->toBe(BaseDelDescuentoLegal::NoAplica->value)
        ->and($cargo->categoria_legal)->toBe(RangoEdad::Tercera)
        ->and($cargo->descuento_legal_fraccion)->toBe('0.2500');
})->note('El cargo guarda lo que le TOCABA —25 %, tercera edad— y por qué no le llegó: no_aplica. Ante un reclamo, eso es lo que se muestra.');

/*
|--------------------------------------------------------------------------
| 🔴 El techo es de la persona, no del pagador
|--------------------------------------------------------------------------
*/

it('🔴 con un pagador que no aplica, el hospital puede darle al adulto mayor lo que la ley no le dio', function (int $edad, string $rebaja, string $esperado): void {
    $cuenta = unaCuentaCon(unaAseguradoraQueNoAplica(), edad: $edad);

    $cargo = cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, '100.0000'),
        $rebaja,
    );

    expect($cargo->descuento_legal)->toBe('0.00')
        ->and($cargo->descuento_comercial)->toBe($esperado);
})->with([
    'joven, hasta 30 %'        => [40, '0.30', '30.00'],
    'tercera edad, hasta 35 %' => [65, '0.35', '35.00'],
    'cuarta edad, hasta 40 %'  => [85, '0.40', '40.00'],
])->note('Antes la tercera edad topaba en 10 % y la cuarta en 0 %, porque el tope miraba la edad y no si la ley había llegado: el adulto mayor quedaba pagando más que el joven del mismo seguro.');

it('y ni un punto mas: el techo de cada edad sigue en pie', function (int $edad, string $deMas): void {
    $cuenta = unaCuentaCon(unaAseguradoraQueNoAplica(), edad: $edad);

    cargarConRebaja(
        $cuenta,
        itemDeLaCategoria(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, '100.0000'),
        $deMas,
    );
})->with([
    'joven, 31 %'        => [40, '0.31'],
    'tercera edad, 36 %' => [65, '0.36'],
    'cuarta edad, 41 %'  => [85, '0.41'],
])->throws(CargoException::class, 'la dirección, no la ley');

it('la banda de la pantalla ofrece el mismo tope que el cargo acepta', function (string $pagador, int $edad, string $tope): void {
    $convenio = match ($pagador) {
        'aseguradora' => unaAseguradoraQueNoAplica(),
        'militar'     => elMilitarSobreElTotal(),
        default       => Convenio::factory()->contado()->create(),
    };

    $cuenta = unaCuentaCon($convenio, edad: $edad);

    $calculado = app(PoliticaDeDescuentoComercial::class)->topeEnLaCuenta(
        $cuenta,
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        now(),
    );

    expect($calculado->redondeado(2))->toBe($tope);
})->with([
    'joven con aseguradora'        => ['aseguradora', 40, '0.30'],
    'tercera edad con aseguradora' => ['aseguradora', 65, '0.35'],
    'cuarta edad con aseguradora'  => ['aseguradora', 85, '0.40'],
    'tercera edad del Militar'     => ['militar', 65, '0.10'],
    'tercera edad de contado'      => ['contado', 65, '0.10'],
    'cuarta edad de contado'       => ['contado', 85, '0.00'],
])->note('Si la pantalla ofreciera más de lo que el cargo acepta, el descuento se teclearía y después reventaría al cargar. Por eso las dos salen de la misma clase.');
