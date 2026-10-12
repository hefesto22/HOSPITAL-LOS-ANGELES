<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CajaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Abono;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\RangoCai;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Services\EmisorDeFactura;
use App\Services\ReceptorDeAbono;

/*
 * ANULAR LA FACTURA Y DEVOLVER LA PLATA (Mauricio, 11-oct-2026).
 *
 * «Facturé y luego la anulé, y dice que ya abonó.» Emitir con «Cobrar
 * ahora» deja un recibo; anular la factura no lo toca, porque la plata
 * entró y al volver a facturar ese recibo sigue pagando la cuenta.
 *
 * Pero si la plata se devolvió, el recibo tiene que caer con la
 * factura. Lo que este archivo cuida:
 *
 *   · sin marcar nada, el recibo sigue vivo y la cuenta saldada;
 *   · marcado, el recibo se anula junto con la factura y la cuenta
 *     vuelve a deber;
 *   · un recibo de otra cuenta no se anula por acá;
 *   · si el recibo no se puede anular —turno cerrado—, la factura
 *     tampoco: nadie queda con la mitad hecha.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

/**
 * Una cuenta de contado de L 1,000 cobrada en efectivo, todavía sin
 * facturar.
 *
 * @return array{cuenta: Cuenta, recibo: Abono, cajera: User, turno: TurnoDeCaja}
 */
function unReciboCobradoEnCaja(): array
{
    /*
     * El código del contado es único: la segunda cuenta del mismo test
     * usa el mismo convenio en vez de chocar contra el índice.
     */
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '1000.0000');

    $cajera = User::factory()->create();
    $turno = TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id, 'usuario_id' => $cajera->id]);

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta->refresh(),
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('1000.00'))],
        quienRecibe: $cajera,
        nota: 'Cobrado al emitir la factura.',
    );

    return ['cuenta' => $cuenta, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno];
}

/**
 * La misma cuenta, ya facturada: como la deja «Cobrar ahora».
 *
 * ⚠️ Una sola por test: el número de factura es único en toda la base y
 * dos rangos de prueba arrancan los dos en el 00000001.
 *
 * @return array{cuenta: Cuenta, factura: Factura, recibo: Abono, cajera: User, turno: TurnoDeCaja}
 */
function unaFacturaCobradaEnCaja(): array
{
    ['cuenta' => $cuenta, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno] = unReciboCobradoEnCaja();

    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: ClienteDeFactura::consumidorFinal(),
        quien: $cajera->id,
    );

    return ['cuenta' => $cuenta, 'factura' => $factura, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno];
}

it('anular sin marcar nada deja el recibo vivo y la cuenta saldada', function (): void {
    ['cuenta' => $cuenta, 'factura' => $factura, 'recibo' => $recibo, 'cajera' => $cajera] = unaFacturaCobradaEnCaja();

    app(EmisorDeFactura::class)->anular($factura, 'Salió con el nombre equivocado', $cajera->id);

    expect($recibo->refresh()->estado)->toBe(EstadoAbono::Aplicado)
        ->and($cuenta->refresh()->saldoPendienteDelPaciente()->redondeado(2))->toBe('0.00');
})->note('El caso del papel mal hecho: la plata se quedó, y al volver a facturar no se cobra dos veces.');

it('🔴 si se le devolvió la plata el recibo se anula junto con la factura', function (): void {
    ['cuenta' => $cuenta, 'factura' => $factura, 'recibo' => $recibo, 'cajera' => $cajera] = unaFacturaCobradaEnCaja();

    app(EmisorDeFactura::class)->anular(
        $factura,
        'El paciente no se llevó nada',
        $cajera->id,
        recibosDevueltos: [$recibo->id],
    );

    $recibo->refresh();

    expect($factura->refresh()->estado)->toBe(EstadoFactura::Anulada)
        ->and($recibo->estado)->toBe(EstadoAbono::Anulado)
        ->and($recibo->motivo_anulacion)->toContain($factura->numero)
        ->and($cuenta->refresh()->saldoPendienteDelPaciente()->redondeado(2))->toBe('1000.00');
})->note('Es el caso de Mauricio: «dice que ya abonó y no abonó nada».');

it('un recibo de otra cuenta no se anula por aca', function (): void {
    ['factura' => $factura, 'cajera' => $cajera] = unaFacturaCobradaEnCaja();
    ['recibo' => $deOtraCuenta] = unReciboCobradoEnCaja();

    app(EmisorDeFactura::class)->anular(
        $factura,
        'El paciente no se llevó nada',
        $cajera->id,
        recibosDevueltos: [$deOtraCuenta->id],
    );

    expect($deOtraCuenta->refresh()->estado)->toBe(EstadoAbono::Aplicado);
});

it('🔴 si el recibo no se puede anular la factura tampoco', function (): void {
    ['factura' => $factura, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno] = unaFacturaCobradaEnCaja();

    app(AbridorDeTurnoDeCaja::class)->cerrar(
        $turno,
        Decimal::de('1500.00'),
        notas: 'Cierre de prueba del turno de caja',
        cerradoPor: $cajera->id,
    );

    expect(fn () => app(EmisorDeFactura::class)->anular(
        $factura,
        'El paciente no se llevó nada',
        $cajera->id,
        recibosDevueltos: [$recibo->id],
    ))->toThrow(CajaException::class);

    expect($factura->refresh()->estado)->toBe(EstadoFactura::Emitida)
        ->and($recibo->refresh()->estado)->toBe(EstadoAbono::Aplicado);
})->note('Con el turno cerrado ese efectivo ya se contó y se entregó: devolverlo es otro hecho, y la factura no puede quedar anulada a medias.');

it('🔴 la bitacora de la cuenta muestra el recibo, la factura y sus anulaciones', function (): void {
    ['cuenta' => $cuenta, 'factura' => $factura, 'recibo' => $recibo, 'cajera' => $cajera] = unaFacturaCobradaEnCaja();

    app(EmisorDeFactura::class)->anular(
        $factura,
        'El paciente no se llevó nada',
        $cajera->id,
        recibosDevueltos: [$recibo->id],
    );

    $textos = array_column($cuenta->refresh()->bitacoraDeCaja(), 'texto');

    expect($textos)->toContain('Factura '.$factura->numero.' emitida')
        ->and($textos)->toContain('Factura '.$factura->numero.' anulada')
        ->and($textos)->toContain('Recibo '.$recibo->numero.' anulado')
        ->and(array_filter($textos, static fn (string $texto): bool => str_starts_with($texto, 'Recibo '.$recibo->numero.' · ')))->toHaveCount(1);
})->note('«Dice que abonó y no hay registro de ningún abono»: el pie decía «Abonado» y la bitácora solo tenía cargos.');
