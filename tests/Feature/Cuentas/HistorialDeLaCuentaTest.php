<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoDeMovimiento;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AnuladorDeCargo;
use App\Services\ReceptorDeAbono;
use App\Services\RegistradorDeCargo;
use App\Support\HistorialDeLaCuenta;
use App\Support\MovimientoDelHistorial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL HISTORIAL DE LA CUENTA (rediseño de la bitácora, 12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La bitácora pintaba las filas crudas de `cargos`: un cambio de
 * descuento en el paquete eran cuatro renglones con «· -1 · Reversa».
 * El historial los cuenta como pasaron. Lo que este archivo cuida:
 *
 *   · quitar se lee «quitó», con su motivo, y lo agregado queda tachado
 *     con la nota de cuándo se quitó;
 *   · quitar y volver a asentar en el mismo momento (lo que hace
 *     «rehacer») se lee como UN cambio, sin el renglón nuevo repetido;
 *   · quitar y volver a escanear un rato después NO es un cambio: son
 *     dos cosas que pasaron y se ven las dos;
 *   · la caja va en el mismo hilo.
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

const MOTIVO_DEL_HISTORIAL = 'El paciente pidió traslado y no se le administró.';

/**
 * @return array{cuenta: Cuenta, quien: User, item: Item}
 */
function unaCuentaParaElHistorial(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $quien = User::factory()->create(['name' => 'Enfermera de prueba']);
    test()->actingAs($quien);

    return ['cuenta' => unaCuentaCon($contado), 'quien' => $quien, 'item' => unServicioDe('120.0000')];
}

function asentarEnElHistorial(Cuenta $cuenta, Item $item): Cargo
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ))->firstOrFail();
}

/**
 * Un cargo asentado hace diez minutos: lo que se le haga ahora ya no cae
 * en la ventana de un «rehacer».
 */
function asentadoHaceUnRato(Cuenta $cuenta, Item $item): Cargo
{
    Carbon::setTestNow(now()->subMinutes(10));
    $cargo = asentarEnElHistorial($cuenta, $item);
    Carbon::setTestNow();

    return $cargo;
}

/**
 * @return list<MovimientoDelHistorial>
 */
function elHistorialDe(Cuenta $cuenta): array
{
    return app(HistorialDeLaCuenta::class)->de($cuenta->refresh());
}

it('🔴 quitar se lee como quitó, y lo agregado queda tachado con cuándo se quitó', function (): void {
    ['cuenta' => $cuenta, 'item' => $item] = unaCuentaParaElHistorial();
    $cargo = asentadoHaceUnRato($cuenta, $item);

    app(AnuladorDeCargo::class)->anular($cargo, MOTIVO_DEL_HISTORIAL);

    $historial = elHistorialDe($cuenta);

    expect($historial)->toHaveCount(2)
        ->and($historial[0]->tipo)->toBe(TipoDeMovimiento::Quito)
        ->and($historial[0]->quien)->toBe('Enfermera de prueba')
        ->and((string) $historial[0]->motivo)->toContain('TRASLADO')
        ->and((string) $historial[0]->monto)->toStartWith('−')
        ->and($historial[1]->tipo)->toBe(TipoDeMovimiento::Agrego)
        ->and($historial[1]->yaNoCuenta)->toBeTrue()
        ->and((string) $historial[1]->nota)->toStartWith('Se quitó');
})->note('Lo más reciente arriba. El renglón agregado no desaparece: queda tachado y dice qué le pasó.');

it('🔴 quitar y volver a asentar en el mismo momento es un solo cambio', function (): void {
    ['cuenta' => $cuenta, 'item' => $item] = unaCuentaParaElHistorial();
    $cargo = asentadoHaceUnRato($cuenta, $item);

    app(AnuladorDeCargo::class)->anular($cargo, MOTIVO_DEL_HISTORIAL);
    asentarEnElHistorial($cuenta, $item);

    $historial = elHistorialDe($cuenta);

    expect($historial)->toHaveCount(2)
        ->and($historial[0]->tipo)->toBe(TipoDeMovimiento::Cambio)
        ->and((string) $historial[0]->detalle)->toStartWith('de L. 120.00 a L. 120.00')
        ->and($historial[0]->yaNoCuenta)->toBeFalse()
        ->and($historial[1]->tipo)->toBe(TipoDeMovimiento::Agrego)
        ->and((string) $historial[1]->nota)->toStartWith('Se cambió');
})->note('Es lo que deja «rehacer» al aplicar un descuento o al actualizar el paquete: en la base son tres filas, para quien mira es un cambio.');

it('quitar y volver a escanear un rato después son dos cosas distintas', function (): void {
    ['cuenta' => $cuenta, 'item' => $item] = unaCuentaParaElHistorial();
    $cargo = asentadoHaceUnRato($cuenta, $item);

    Carbon::setTestNow(now()->subMinutes(5));
    app(AnuladorDeCargo::class)->anular($cargo, MOTIVO_DEL_HISTORIAL);
    Carbon::setTestNow();

    asentarEnElHistorial($cuenta, $item);

    expect(array_map(fn (MovimientoDelHistorial $m): TipoDeMovimiento => $m->tipo, elHistorialDe($cuenta)))
        ->toBe([TipoDeMovimiento::Agrego, TipoDeMovimiento::Quito, TipoDeMovimiento::Agrego]);
})->note('Cinco minutos entre quitar y volver a escanear no es un «rehacer»: alguien decidió dos veces, y el historial lo muestra así.');

it('la caja va en el mismo hilo que los cargos', function (): void {
    ['cuenta' => $cuenta, 'quien' => $quien, 'item' => $item] = unaCuentaParaElHistorial();
    asentadoHaceUnRato($cuenta, $item);

    TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id, 'usuario_id' => $quien->id]);

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta->refresh(),
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de('50.00'))],
        quienRecibe: $quien,
    );

    $historial = elHistorialDe($cuenta);

    expect($historial[0]->tipo)->toBe(TipoDeMovimiento::Cobro)
        ->and($historial[0]->titulo)->toBe('Recibo '.$recibo->numero)
        ->and((string) $historial[0]->detalle)->toContain('Efectivo')
        ->and($historial[1]->tipo)->toBe(TipoDeMovimiento::Agrego);
});

it('agrupa por día y esconde lo que pasa del tope', function (): void {
    $uno = static fn (Carbon $cuando, int $orden): MovimientoDelHistorial => new MovimientoDelHistorial(
        cuando: $cuando,
        tipo: TipoDeMovimiento::Agrego,
        titulo: 'RENGLON '.$orden,
        quien: 'Alguien',
        orden: $orden,
    );

    $hoy = now()->startOfDay()->addHours(12);
    $ayer = $hoy->copy()->subDay();

    $dias = app(HistorialDeLaCuenta::class)->porDia([
        $uno($hoy->copy()->addMinutes(3), 5),
        $uno($hoy->copy()->addMinutes(2), 4),
        $uno($hoy->copy()->addMinute(), 3),
        $uno($ayer->copy()->addMinutes(2), 2),
        $uno($ayer, 1),
    ], visibles: 4);

    expect(array_column($dias, 'dia'))->toBe(['Hoy', 'Ayer'])
        ->and(array_column($dias, 'oculto'))->toBe([false, false])
        ->and(array_column($dias[1]['movimientos'], 'oculto'))->toBe([false, true]);
});
