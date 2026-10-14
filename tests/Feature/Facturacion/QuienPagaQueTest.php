<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Services\RegistradorDeAutorizacion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * ¿QUIÉN PAGA QUÉ? — EL SEGURO A CRÉDITO, EL PACIENTE HOY (13-oct-2026).
 *
 * Mauricio: «cuando escriban el monto que cubre el seguro, el resto lo
 * paga el cliente, y el resto es como crédito del seguro a 30, 60 o 90
 * días». Y: «si cobertura está vacío significa que es a elección al
 * momento de facturar, ya que no con todos es igual». La doctora lo
 * llama copago.
 *
 * Lo que este archivo cuida:
 *
 *   · que un convenio se pueda guardar con la cobertura vacía —daba 500—
 *     y que eso signifique «se decide al facturar»;
 *   · que mientras tanto la cuenta se cargue entera al paciente, sin que
 *     el renglón quede marcado como excluido de la póliza;
 *   · que lo que la caja ve mientras escribe sea EXACTAMENTE lo que deja
 *     la autorización anotada: el botón dice un número y el emisor exige
 *     ese mismo, al centavo;
 *   · que el porcentaje general del convenio, sin tocar, no se anote
 *     como autorización y respete el tope y las exclusiones;
 *   · y que cobrar de más o de menos no pase.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

function seguroQueDecideAlFacturar(): Convenio
{
    return Convenio::factory()->create([
        'tipo'                  => TipoConvenio::Institucional,
        'base_descuento_legal'  => BaseDelDescuentoLegal::SobreElTotalFacturado,
        'rtn'                   => '08019001234567',
        'cobertura_fraccion'    => null,
        'cubre_por_defecto'     => true,
        'requiere_autorizacion' => false,
        'dias_credito'          => 60,
    ]);
}

/**
 * Lo que la caja ve en el modal de factura con `$estado` escrito.
 *
 * Por reflexión porque es privado: es la cuenta de la pantalla, no una
 * API. Una página nueva cada vez, para no arrastrar lo leído antes.
 *
 * @param array<string, mixed> $estado
 *
 * @return array{total: Monto, seguro: Monto, paciente: Monto, abonado: Decimal, falta: Decimal, por_decidir: bool}
 */
function repartoQueVeLaCaja(Cuenta $cuenta, array $estado): array
{
    $pagina = app(CuentasAbiertas::class);
    $pagina->cuentaAFacturar = $cuenta->id;

    /** @var array{total: Monto, seguro: Monto, paciente: Monto, abonado: Decimal, falta: Decimal, por_decidir: bool} $reparto */
    $reparto = (new ReflectionMethod(CuentasAbiertas::class, 'repartoEnVivo'))->invoke($pagina, $cuenta->refresh(), $estado);

    return $reparto;
}

/**
 * @param array<string, mixed> $estado
 *
 * @return array{anotar: bool, fraccion: Decimal|null, monto: Monto|null}
 */
function autorizacionQueLeeLaCaja(Cuenta $cuenta, array $estado): array
{
    $pagina = app(CuentasAbiertas::class);
    $pagina->cuentaAFacturar = $cuenta->id;

    /** @var array{anotar: bool, fraccion: Decimal|null, monto: Monto|null} $autorizacion */
    $autorizacion = (new ReflectionMethod(CuentasAbiertas::class, 'autorizacionDelFormulario'))
        ->invoke($pagina, $cuenta->refresh(), $estado);

    return $autorizacion;
}

/**
 * @return array{titulo: string, detalle: string}|null
 */
function cuadreQueVeLaCaja(string $falta, string $cobrado): ?array
{
    /** @var array{titulo: string, detalle: string}|null $problema */
    $problema = (new ReflectionMethod(CuentasAbiertas::class, 'porQueNoCuadraElCobro'))
        ->invoke(null, Decimal::de($falta), Decimal::de($cobrado));

    return $problema;
}

it('🔴 un seguro se guarda con la cobertura vacia y eso es decidirla al facturar', function (): void {
    $militar = seguroQueDecideAlFacturar()->refresh();

    expect($militar->getAttribute('cobertura_fraccion'))->toBeNull()
        ->and($militar->coberturaGeneral())->toBeNull()
        ->and($militar->decideLaCoberturaAlFacturar())->toBeTrue();
})->note('Mauricio, 13-oct-2026: guardar el Hospital Militar con la cobertura vacía daba 500 (NOT NULL). Vacía es justo lo que quería decir.');

it('🔴 quien no paga un tercero no puede dejar la cobertura vacia', function (): void {
    $reembolso = Convenio::factory()->reembolso()->create();

    DB::table('convenios')->where('id', $reembolso->id)->update(['cobertura_fraccion' => null]);
})->throws(QueryException::class)
    ->note('En PostgreSQL un CHECK que da NULL no rechaza: sin el «IS NOT NULL» explícito, un contado con la cobertura vacía habría pasado.');

it('🔴 mientras se carga, la cuenta va entera al paciente y el renglon sigue cubierto', function (): void {
    $cuenta = unaCuentaCon(seguroQueDecideAlFacturar());

    conUnServicioDe($cuenta, '138.5000');

    $cuenta->refresh();

    /** @var Cargo $cargo */
    $cargo = $cuenta->cargos()->firstOrFail();

    expect($cuenta->total_paciente)->toBe('138.50')
        ->and($cuenta->total_aseguradora)->toBe('0.00')
        ->and((bool) $cargo->getAttribute('elegible'))->toBeTrue()
        ->and($cargo->cobertura_fraccion)->toBe('0.0000');
})->note('Elegible y en cero, no «ninguna»: lo excluido de la póliza no se reclama, y esto solo esperaba la autorización.');

it('🔴 lo que ve la caja es lo que deja la autorizacion, al centavo', function (?string $fraccion, ?string $monto): void {
    $cuenta = unaCuentaCon(seguroQueDecideAlFacturar());
    conUnServicioDe($cuenta, '138.5000');
    $cuenta->refresh();

    $vivo = $cuenta->repartoConLaAutorizacion(
        $fraccion === null ? null : Decimal::de($fraccion),
        $monto === null ? null : Monto::de($monto),
    );

    $anotada = app(RegistradorDeAutorizacion::class)->registrar(
        cuenta: $cuenta,
        fraccion: $fraccion === null ? null : Decimal::de($fraccion),
        monto: $monto === null ? null : Monto::de($monto),
    );

    expect($anotada->total_aseguradora)->toBe($vivo['aseguradora']->valor())
        ->and($anotada->total_paciente)->toBe($vivo['paciente']->valor());
})->with([
    'la mitad'                     => ['0.5', null],
    'un tercio, con más decimales' => ['0.33333', null],
    'todo'                         => ['1', null],
    'lo negó'                      => ['0', null],
    'un monto'                     => [null, '100.00'],
    'un monto mayor que la cuenta' => [null, '500.00'],
])->note('Si la pantalla y el emisor redondearan distinto, el botón pediría L 92.33 y el emisor L 92.34, y la factura no saldría por un centavo.');

it('🔴 con la cobertura vacia no hay copago hasta que se escribe lo autorizado', function (): void {
    $cuenta = unaCuentaCon(seguroQueDecideAlFacturar());
    conUnServicioDe($cuenta, '138.5000');

    $sinEscribir = repartoQueVeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => null]);

    expect($sinEscribir['por_decidir'])->toBeTrue();

    $mitad = repartoQueVeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => '50']);

    expect($mitad['por_decidir'])->toBeFalse()
        ->and($mitad['seguro']->valor())->toBe('69.25')
        ->and($mitad['paciente']->valor())->toBe('69.25')
        ->and($mitad['falta']->redondeado(2))->toBe('69.25');

    $unMonto = repartoQueVeLaCaja($cuenta, ['forma_autorizacion' => 'monto', 'monto_autorizado' => '100', 'cobertura_autorizada' => '50']);

    expect($unMonto['seguro']->valor())->toBe('100.00')
        ->and($unMonto['falta']->redondeado(2))->toBe('38.50');
})->note('Es el caso de la captura del 13-oct-2026: el seguro cubre la mitad y la caja seguía proponiendo L 138.50. Con «un monto fijo» elegido, el porcentaje que quedó escrito no cuenta.');

it('lo que ya abono se descuenta de lo que se cobra hoy', function (): void {
    $cuenta = unaCuentaCon(seguroQueDecideAlFacturar());
    conUnServicioDe($cuenta, '138.5000');
    abonarle($cuenta, '20.00');

    $reparto = repartoQueVeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => '50']);

    expect($reparto['paciente']->valor())->toBe('69.25')
        ->and($reparto['falta']->redondeado(2))->toBe('49.25');
});

it('🔴 el porcentaje general sin tocar no se anota y respeta el tope del evento', function (): void {
    $seguro = unSeguroQueCubre('0.8000');
    $seguro->update(['tope_por_evento' => '50.00']);

    $cuenta = unaCuentaCon($seguro);
    conUnServicioDe($cuenta, '138.5000');

    /* El renglón ya se partió con el tope: el seguro pone 50, no 110.80. */
    expect($cuenta->refresh()->total_aseguradora)->toBe('50.00');

    $sinTocar = autorizacionQueLeeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => '80']);
    $reparto = repartoQueVeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => '80']);

    expect($sinTocar['anotar'])->toBeFalse()
        ->and($reparto['seguro']->valor())->toBe('50.00')
        ->and($reparto['falta']->redondeado(2))->toBe('88.50');

    $otro = autorizacionQueLeeLaCaja($cuenta, ['forma_autorizacion' => 'porcentaje', 'cobertura_autorizada' => '70']);

    expect($otro['anotar'])->toBeTrue()
        ->and($otro['fraccion']?->redondeado(4))->toBe('0.7000');
})->note('Antes el 80 % que venía escrito se anotaba igual como autorización, y aplicado parejo sobre el total se llevaba puesto el tope: el seguro terminaba poniendo L 110.80 de un tope de L 50.');

it('volver a escribir lo que la cuenta ya tiene anotado no se anota de nuevo', function (): void {
    $cuenta = unaCuentaCon(seguroQueDecideAlFacturar());
    conUnServicioDe($cuenta, '138.5000');

    app(RegistradorDeAutorizacion::class)->registrar($cuenta, null, Monto::de('100.00'));

    expect(autorizacionQueLeeLaCaja($cuenta, ['forma_autorizacion' => 'monto', 'monto_autorizado' => '100.00'])['anotar'])->toBeFalse()
        ->and(autorizacionQueLeeLaCaja($cuenta, ['forma_autorizacion' => 'monto', 'monto_autorizado' => '90'])['anotar'])->toBeTrue();
})->note('Solo cambiaría quién figura como el que autorizó.');

it('🔴 el cobro tiene que ser lo del paciente, ni mas ni menos', function (): void {
    expect(cuadreQueVeLaCaja('69.25', '69.25'))->toBeNull()
        ->and(cuadreQueVeLaCaja('69.25', '39.25')['titulo'] ?? null)->toBe('Faltan L 30.00')
        ->and(cuadreQueVeLaCaja('69.25', '138.50')['titulo'] ?? null)->toBe('Sobran L 69.25');
})->note('Cobrar de menos dejaba un recibo hecho y la factura sin salir; cobrar de más metía en la gaveta la parte del seguro.');
