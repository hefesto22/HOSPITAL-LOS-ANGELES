<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
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
use App\Services\RegistradorDeCargo;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Support\Str;

/**
 * EL DESCUENTO QUE DECIDE EL HOSPITAL.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POLÍTICA APROBADA (Mauricio, 21-ago-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Cuarta edad  → 40 % de ley, y nada encima.
 *   · Tercera edad → 25 % de ley + hasta 10 % del hospital.
 *   · Sin derecho  → hasta 30 %, a criterio y caso por caso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 SON DOS LÍMITES, Y LOS DOS TIENEN QUE PASAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * **El de la dirección** (`PoliticaDeDescuentoComercial`) mira SOLO la
 * parte del hospital y depende de a quién se le da: 0 %, 10 % o 30 %.
 * Es política, vive en configuración, y se puede cambiar.
 *
 * **El de la ley** (`CalculadoraDeCargo::exigirQueRespeteElTope`) mira
 * el descuento TOTAL —ley más hospital— contra el descuento de ley más
 * alto de la categoría. Ese no se configura, y sostiene dos cosas:
 *
 *   · **Legal.** Si un paciente sin derecho recibiera más que el de
 *     cuarta edad, el beneficio del Art. 30 quedaría invertido y el que
 *     la ley protege pagaría más caro. Es el mismo resultado del «precio
 *     inflado» que se rechazó, alcanzado por otro camino.
 *   · **Económico.** El precio de lista se calcula dividiendo por ese
 *     mismo máximo (§4.5), así que respetar el tope ES respetar el piso
 *     de margen. La misma cuenta, vista al revés.
 *
 * Hoy la política es la más estricta de las dos y es la que salta
 * primero. El último test de este archivo afloja la política a propósito
 * para probar que abajo sigue estando el techo que no se configura.
 */

/**
 * Un ítem de categoría MEDICAMENTO pero de tipo servicio.
 *
 * El descuento de ley se resuelve por `categoria_legal_descuento` y no
 * por el tipo, así que esto prueba lo mismo sin arrastrar el almacén, la
 * unidad de dispensación ni la existencia — nada de lo cual está en
 * prueba acá.
 *
 * @param numeric-string $precio
 */
function unItemDeCategoriaMedicamento(string $precio): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

function cargarConDescuento(
    Cuenta $cuenta,
    Item $item,
    ?string $porcentaje,
    ?string $motivo = 'Paciente frecuente del hospital',
): Cargo {
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercialPorcentaje: $porcentaje === null ? null : Decimal::de($porcentaje),
        motivoDescuento: $motivo,
    ))->firstOrFail();
}

function elContadoDelHospital(): Convenio
{
    return Convenio::factory()->contado()->create([
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreElTotalFacturado,
    ]);
}

/*
|--------------------------------------------------------------------------
| Se aplica, y queda escrito
|--------------------------------------------------------------------------
*/

it('el hospital le hace 30 por ciento a un paciente sin descuento de ley', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 40);
    $item = unItemDeCategoriaMedicamento('100.0000');

    $cargo = cargarConDescuento($cuenta, $item, '0.30');

    expect($cargo->bruto)->toBe('100.00')
        ->and($cargo->descuento_legal)->toBe('0.00')
        ->and($cargo->descuento_comercial)->toBe('30.00')
        ->and($cargo->subtotal)->toBe('70.00')
        ->and($cargo->motivo_descuento)->toBe('Paciente frecuente del hospital');
})->note('El porcentaje se convierte a lempiras donde el bruto existe, no en la pantalla: la pantalla no resuelve el precio y hacerlo ahí obligaría a resolverlo dos veces.');

it('la tercera edad suma el 25 de ley y hasta 10 del hospital', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 65);
    $item = unItemDeCategoriaMedicamento('100.0000');

    $cargo = cargarConDescuento($cuenta, $item, '0.10');

    expect($cargo->descuento_legal)->toBe('25.00')
        ->and($cargo->descuento_comercial)->toBe('10.00')
        ->and($cargo->subtotal)->toBe('65.00');
})->note('35 % total, por debajo del 40 % de la cuarta edad: el paciente de 80 años sigue pagando menos que este. Ese orden es lo que hace legal a todo el esquema.');

/*
|--------------------------------------------------------------------------
| El tope de la dirección: cuánto, y a quién
|--------------------------------------------------------------------------
*/

it('no le hace mas del 30 a un paciente sin descuento de ley', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 40);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.35');
})->throws(CargoException::class, 'la dirección, no la ley')
    ->note('El 30 % no lo impone el Art. 30 —ahí el techo es 40 %—: lo puso la dirección. Por eso el mensaje dice de dónde viene, para que quien atiende sepa a quién preguntarle.');

it('a la tercera edad no le agrega mas del 10 del hospital', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 65);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.20');
})->throws(CargoException::class, 'la dirección, no la ley')
    ->note('Diez puntos y no treinta: el 25 % de ley ya se lo comió parte del margen. Que el tope del hospital dependa de lo que la línea YA lleva es lo que impide que los dos descuentos se sumen sin que nadie los mire juntos.');

it('🔴 a la cuarta edad no le cabe ningun descuento encima del de ley', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 85);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.05');
})->throws(CargoException::class, 'no se le agrega descuento del hospital')
    ->note('🔴 Ya recibe el 40 %, que ES el techo. Un punto más rompe el piso de margen del 120 %, porque el precio de lista se calculó dividiendo por ese mismo 40 %. No sale del precio: sale del margen.');

/*
|--------------------------------------------------------------------------
| 🔴 Debajo de la política, el techo que no se configura
|--------------------------------------------------------------------------
*/

it('🔴 el techo de ley aguanta aunque la direccion afloje su politica', function (): void {
    (new DescuentosLegalesSeeder)->run();

    config(['sihla.facturacion.descuento_comercial_por_rango.tercera' => '0.90']);

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 65);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.20');
})->throws(CargoException::class, 'descuento de ley más alto de esta categoría')
    ->note('🔴 Con la política abierta al 90 %, el 25 % de ley más el 20 % del hospital dan 45 % contra el 40 % de la cuarta edad. Lo que rechaza acá NO es la política —esa lo dejó pasar— sino el techo de ley, que es el que ninguna configuración puede subir.');

/*
|--------------------------------------------------------------------------
| Sin motivo no hay descuento
|--------------------------------------------------------------------------
*/

it('🔴 no acepta un descuento sin motivo escrito', function (): void {
    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 40);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.30', motivo: null);
})->throws(CargoException::class)
    ->note('🔴 §8.6.2-4: el descuento libre en el mostrador es la fuga de caja número uno de un hospital privado. Dentro de seis meses, «¿por qué esta línea salió más barata?» tiene que tener respuesta escrita.');

it('no acepta un motivo de dos palabras', function (): void {
    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 40);
    $item = unItemDeCategoriaMedicamento('100.0000');

    cargarConDescuento($cuenta, $item, '0.30', motivo: 'ok');
})->throws(CargoException::class)
    ->note('Diez caracteres mínimo. «ok» y «autorizado» no explican nada: son la forma de cumplir el requisito sin cumplir su propósito.');

/*
|--------------------------------------------------------------------------
| Una sola forma de decir cuánto
|--------------------------------------------------------------------------
*/

it('no acepta monto y porcentaje a la vez', function (): void {
    $item = unItemDeCategoriaMedicamento('100.0000');

    new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercial: Monto::de('10.00'),
        descuentoComercialPorcentaje: Decimal::de('0.30'),
        motivoDescuento: 'Paciente frecuente del hospital',
    );
})->throws(CargoException::class)
    ->note('Con los dos, cuál manda lo decidiría el orden del código — y esa respuesta cambia sin que nadie la haya decidido.');

it('no acepta un porcentaje mayor a cien', function (): void {
    $item = unItemDeCategoriaMedicamento('100.0000');

    new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercialPorcentaje: Decimal::de('1.5'),
        motivoDescuento: 'Paciente frecuente del hospital',
    );
})->throws(CargoException::class);

it('sin descuento nada cambia', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cuenta = unaCuentaCon(elContadoDelHospital(), edad: 40);
    $item = unItemDeCategoriaMedicamento('100.0000');

    $cargo = cargarConDescuento($cuenta, $item, null, motivo: null);

    expect($cargo->descuento_comercial)->toBe('0.00')
        ->and($cargo->subtotal)->toBe('100.00');
})->note('El campo es opcional de verdad: dejarlo vacío tiene que dejar el cargo exactamente como estaba antes de que este campo existiera.');
