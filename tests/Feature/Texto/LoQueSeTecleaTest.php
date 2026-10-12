<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\RangoCai;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\EmisorDeFactura;
use App\Services\ReceptorDeAbono;
use App\Support\TextoCanonico;

/*
 * «QUE CUANDO ESCRIBA TODO SEA EN MAYÚSCULAS» (Mauricio, 11-oct-2026).
 *
 * En la bitácora de una cuenta, «error al agregarlo» quedaba al lado de
 * «ACETAMINOFEN 500 MG TABLETA». Desde hoy lo que se teclea —motivos,
 * notas, comentarios, quién entrega la plata, la póliza, el cliente que
 * se escribe a mano— se guarda en mayúsculas.
 *
 * Lo que este archivo cuida es que se cumpla en el SERVICIO, no solo en
 * la pantalla: el motivo de quitar un cargo, el del descuento y el de
 * anular un recibo se escriben en campos sueltos de Livewire, que no
 * pasan por el `dehydrateStateUsing` de Filament.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

/**
 * Una cuenta de contado de L 1,000 sin cobrar, y una cajera con el turno
 * abierto.
 *
 * @return array{cuenta: Cuenta, cajera: User}
 */
function unaCuentaParaTeclear(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '1000.0000');

    $cajera = User::factory()->create();
    TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id, 'usuario_id' => $cajera->id]);

    return ['cuenta' => $cuenta->refresh(), 'cajera' => $cajera];
}

it('un parrafo sale en mayusculas sin perder sus renglones', function (): void {
    $tecleado = "  se le devolvió   la plata\r\n\r\n\r\n  doña maría   firmó  \n\n";

    expect(TextoCanonico::parrafoEnMayusculas($tecleado))
        ->toBe("SE LE DEVOLVIÓ LA PLATA\n\nDOÑA MARÍA FIRMÓ");
})->note('Con tilde y con eñe: mb_strtoupper, nunca strtoupper. Y nunca dos renglones en blanco seguidos.');

it('un parrafo sin nada escrito es nulo', function (string $tecleado): void {
    expect(TextoCanonico::parrafoEnMayusculas($tecleado))->toBeNull();
})->with([
    'nada'           => [''],
    'solo espacios'  => ['   '],
    'solo renglones' => ["\n \r\n  \n"],
]);

it('el recibo guarda en mayusculas quien entrega, la nota y el motivo de anularlo', function (): void {
    ['cuenta' => $cuenta, 'cajera' => $cajera] = unaCuentaParaTeclear();

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('200.00'))],
        quienRecibe: $cajera,
        entregadoPor: 'doña maría peña',
        nota: "primer abono\ndeja el resto el viernes",
    );

    expect($recibo->entregado_por)->toBe('DOÑA MARÍA PEÑA')
        ->and($recibo->nota)->toBe("PRIMER ABONO\nDEJA EL RESTO EL VIERNES");

    $anulado = app(ReceptorDeAbono::class)->anular($recibo, 'se digitó el monto equivocado', $cajera->id);

    expect($anulado->motivo_anulacion)->toBe('SE DIGITÓ EL MONTO EQUIVOCADO');
});

it('la factura guarda en mayusculas la nota, la poliza, el cliente escrito y el motivo de anularla', function (): void {
    ['cuenta' => $cuenta, 'cajera' => $cajera] = unaCuentaParaTeclear();

    app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('1000.00'))],
        quienRecibe: $cajera,
    );

    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura(nombre: 'empresa del paciente s.a.', direccion: 'barrio el centro, santa rosa'),
        quien: $cajera->id,
        nota: 'pagó con tarjeta de la empresa',
        poliza: 'pal-2026-90005',
    );

    expect($factura->cliente_nombre)->toBe('EMPRESA DEL PACIENTE S.A.')
        ->and($factura->cliente_direccion)->toBe('BARRIO EL CENTRO, SANTA ROSA')
        ->and($factura->nota)->toBe('PAGÓ CON TARJETA DE LA EMPRESA')
        ->and($factura->comentarios)->toBe('PAGÓ CON TARJETA DE LA EMPRESA')
        ->and($factura->paciente_poliza)->toBe('PAL-2026-90005');

    $anulada = app(EmisorDeFactura::class)->anular($factura, 'se facturó a la cuenta equivocada', $cajera->id);

    expect($anulada->motivo_anulacion)->toBe('SE FACTURÓ A LA CUENTA EQUIVOCADA');
})->note('Una sola factura por test: el número es único en toda la base y dos rangos de prueba arrancan en el mismo.');
