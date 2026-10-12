<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CajaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Abono;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Services\ReceptorDeAbono;

/*
 * ─────────────────────────────────────────────────────────────────────
 * CORREGIR UN RECIBO (Mauricio, 12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Que haya historial de los abonos que se han hecho y poder anular algún
 * abono o editarlo.» Un recibo no se edita —el trigger no deja tocar el
 * monto ni el turno—: corregir es anular el malo y hacer uno bueno, en
 * UNA transacción. Lo que este archivo cuida:
 *
 *   · el viejo queda anulado con el motivo, y el nuevo dice a cuál corrige;
 *   · la cuenta queda con lo del recibo nuevo, ni más ni menos;
 *   · si una de las dos mitades no se puede, no queda ninguna.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

/**
 * Una cuenta de contado de L 1,000 con un recibo de L 500 en efectivo, y
 * la cajera con el turno abierto.
 *
 * @return array{cuenta: Cuenta, recibo: Abono, cajera: User, turno: TurnoDeCaja}
 */
function unReciboParaCorregir(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '1000.0000');

    $cajera = User::factory()->create();
    $turno = TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id, 'usuario_id' => $cajera->id]);

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta->refresh(),
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('500.00'))],
        quienRecibe: $cajera,
        nota: 'primer abono',
    );

    return ['cuenta' => $cuenta, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno];
}

it('🔴 corregir anula el recibo y emite uno nuevo con lo correcto', function (): void {
    ['cuenta' => $cuenta, 'recibo' => $recibo, 'cajera' => $cajera] = unReciboParaCorregir();

    $nuevo = app(ReceptorDeAbono::class)->corregir(
        original: $recibo,
        medios: [new MedioDePago(FormaDePago::Tarjeta, Decimal::de('450.00'))],
        quienCorrige: $cajera,
        entregadoPor: null,
        nota: 'primer abono',
        motivo: 'era tarjeta y eran 450',
    );

    $recibo->refresh();

    expect($recibo->estado)->toBe(EstadoAbono::Anulado)
        ->and($recibo->motivo_anulacion)->toBe('SE CORRIGE CON UN RECIBO NUEVO: ERA TARJETA Y ERAN 450')
        ->and($nuevo->estado)->toBe(EstadoAbono::Aplicado)
        ->and($nuevo->numero)->not->toBe($recibo->numero)
        ->and($nuevo->total)->toBe('450.00')
        ->and($nuevo->nota)->toBe("PRIMER ABONO\nCORRIGE EL RECIBO {$recibo->numero}.")
        ->and($nuevo->resumenDeMedios())->toContain('Tarjeta')
        ->and($cuenta->refresh()->abonado()->redondeado(2))->toBe('450.00');
})->note('Los dos recibos quedan: el viejo tachado con el porqué, el nuevo diciendo a cuál corrige. En papel sería lo mismo.');

it('🔴 si el turno del recibo ya cerro, no se corrige nada', function (): void {
    ['cuenta' => $cuenta, 'recibo' => $recibo, 'cajera' => $cajera, 'turno' => $turno] = unReciboParaCorregir();

    app(AbridorDeTurnoDeCaja::class)->cerrar(
        $turno,
        Decimal::de('500.00'),
        notas: 'Cierre de prueba del turno de caja',
        cerradoPor: $cajera->id,
    );

    expect(fn () => app(ReceptorDeAbono::class)->corregir(
        original: $recibo,
        medios: [new MedioDePago(FormaDePago::Tarjeta, Decimal::de('450.00'))],
        quienCorrige: $cajera,
        entregadoPor: null,
        nota: null,
        motivo: 'era tarjeta y eran 450',
    ))->toThrow(CajaException::class);

    expect($recibo->refresh()->estado)->toBe(EstadoAbono::Aplicado)
        ->and(Abono::query()->where('cuenta_id', $cuenta->id)->count())->toBe(1);
})->note('Con el turno cerrado la plata ya se contó: eso es una devolución. Ni el viejo se anula ni sale uno nuevo.');

it('si quien corrige no tiene turno abierto, el recibo viejo sigue vivo', function (): void {
    ['cuenta' => $cuenta, 'recibo' => $recibo] = unReciboParaCorregir();

    expect(fn () => app(ReceptorDeAbono::class)->corregir(
        original: $recibo,
        medios: [new MedioDePago(FormaDePago::Tarjeta, Decimal::de('450.00'))],
        quienCorrige: User::factory()->create(),
        entregadoPor: null,
        nota: null,
        motivo: 'era tarjeta y eran 450',
    ))->toThrow(CajaException::class);

    expect($recibo->refresh()->estado)->toBe(EstadoAbono::Aplicado)
        ->and(Abono::query()->where('cuenta_id', $cuenta->id)->count())->toBe(1);
})->note('La anulación se hizo adentro de la misma transacción y se deshace con ella: nunca queda un recibo anulado sin su reemplazo.');

it('corregir pide el motivo', function (): void {
    ['recibo' => $recibo, 'cajera' => $cajera] = unReciboParaCorregir();

    app(ReceptorDeAbono::class)->corregir(
        original: $recibo,
        medios: [new MedioDePago(FormaDePago::Tarjeta, Decimal::de('450.00'))],
        quienCorrige: $cajera,
        entregadoPor: null,
        nota: null,
        motivo: 'mal',
    );
})->throws(CajaException::class);
