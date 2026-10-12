<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Filament\Pages\Caja;
use App\Models\Abono;
use App\Models\Convenio;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\ReceptorDeAbono;

/*
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE «CAJA» LE DICE A LA CAJERA (rediseño del 12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La pantalla se armó para leerse de un vistazo: la gaveta como la suma
 * que es (fondo + efectivo = lo que tiene que haber), lo cobrado que no
 * va en la gaveta aparte, y los anulados fuera de la lista principal.
 * Lo que este archivo cuida es que esos números sean los del arqueo:
 *
 *   · la gaveta cuenta solo efectivo, y suma el fondo;
 *   · un recibo anulado no está en ningún total y va en su propio montón;
 *   · el nombre del paciente llega cargado (sin una consulta por recibo).
 *
 * ⚠️ Ayudante con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

/**
 * Turno con fondo de L 300 y tres recibos: uno mixto (L 250 efectivo +
 * L 100 tarjeta), uno de L 80 en efectivo que se anula, y uno de L 50 en
 * efectivo.
 *
 * @return array{cajera: User, turno: TurnoDeCaja, mixto: Abono, anulado: Abono, ultimo: Abono}
 */
function unaGavetaConTresRecibos(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '2000.0000');

    $cajera = User::factory()->create();
    $turno = TurnoDeCaja::factory()->create([
        'sede_id'       => $cuenta->sede_id,
        'usuario_id'    => $cajera->id,
        'fondo_inicial' => '300.00',
    ]);

    $receptor = app(ReceptorDeAbono::class);

    $mixto = $receptor->recibir(
        cuenta: $cuenta->refresh(),
        medios: [
            new MedioDePago(FormaDePago::Efectivo, Decimal::de('250.00')),
            new MedioDePago(FormaDePago::Tarjeta, Decimal::de('100.00')),
        ],
        quienRecibe: $cajera,
    );

    $anulado = $receptor->recibir(
        cuenta: $cuenta->refresh(),
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('80.00'))],
        quienRecibe: $cajera,
    );
    $receptor->anular($anulado, 'se digito el monto equivocado', $cajera->id);

    $ultimo = $receptor->recibir(
        cuenta: $cuenta->refresh(),
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('50.00'))],
        quienRecibe: $cajera,
    );

    test()->actingAs($cajera);

    return ['cajera' => $cajera, 'turno' => $turno, 'mixto' => $mixto, 'anulado' => $anulado, 'ultimo' => $ultimo];
}

it('🔴 la gaveta es el fondo más el efectivo vivo, y nada más', function (): void {
    unaGavetaConTresRecibos();

    $resumen = app(Caja::class)->resumen();
    assert(is_array($resumen));

    $fuera = [];

    foreach ($resumen['fueraDeLaGaveta'] as $linea) {
        $fuera[$linea['etiqueta']] = $linea['monto']->redondeado(2);
    }

    expect($resumen['fondo']->redondeado(2))->toBe('300.00')
        ->and($resumen['enLaGaveta'])->toHaveCount(1)
        ->and($resumen['enLaGaveta'][0]['monto']->redondeado(2))->toBe('300.00')
        ->and($resumen['gaveta']->redondeado(2))->toBe('600.00')
        ->and($fuera)->toBe([
            FormaDePago::Tarjeta->etiqueta()       => '100.00',
            FormaDePago::Transferencia->etiqueta() => '0.00',
        ])
        ->and($resumen['cobrado']->redondeado(2))->toBe('400.00');
})->note('300 de fondo + 250 + 50 en efectivo = 600 en la gaveta. Los 80 anulados no están en ningún lado, y los 100 de tarjeta van aparte: los liquida el POS.');

it('los anulados van en su propio montón, y los vigentes del último al primero', function (): void {
    ['mixto' => $mixto, 'anulado' => $anulado, 'ultimo' => $ultimo] = unaGavetaConTresRecibos();

    $resumen = app(Caja::class)->resumen();
    assert(is_array($resumen));

    expect(array_map(fn (Abono $abono): string => $abono->numero, $resumen['vigentes']))
        ->toBe([$ultimo->numero, $mixto->numero])
        ->and(array_map(fn (Abono $abono): string => $abono->numero, $resumen['anulados']))
        ->toBe([$anulado->numero]);
});

it('el nombre del paciente llega cargado con el recibo', function (): void {
    unaGavetaConTresRecibos();

    $resumen = app(Caja::class)->resumen();
    assert(is_array($resumen));

    foreach ([...$resumen['vigentes'], ...$resumen['anulados']] as $abono) {
        expect($abono->relationLoaded('medios'))->toBeTrue()
            ->and($abono->cuenta->relationLoaded('encuentro'))->toBeTrue()
            ->and($abono->cuenta->encuentro->relationLoaded('persona'))->toBeTrue();
    }
})->note('Si una de estas relaciones no viene cargada, cada fila de la lista hace su propia consulta (§13.2).');

it('avisa cuando el turno lleva abierto más de lo configurado', function (): void {
    ['turno' => $turno] = unaGavetaConTresRecibos();

    expect(app(Caja::class)->resumen()['abiertoHaceMucho'] ?? null)->toBeFalse();

    $turno->forceFill(['abierto_en' => now()->subHours(20)])->save();

    expect(app(Caja::class)->resumen()['abiertoHaceMucho'] ?? null)->toBeTrue();

    config()->set('sihla.caja.horas_para_avisar_turno_largo', 0);

    expect(app(Caja::class)->resumen()['abiertoHaceMucho'] ?? null)->toBeFalse();
})->note('El aviso no cierra nada ni bloquea el cobro: solo pone a la vista el turno que se olvidó. En 0 se apaga.');

it('sin turno abierto no hay resumen', function (): void {
    test()->actingAs(User::factory()->create());

    expect(app(Caja::class)->resumen())->toBeNull();
});
