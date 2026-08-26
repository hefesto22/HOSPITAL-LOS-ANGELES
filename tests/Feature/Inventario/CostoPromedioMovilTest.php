<?php

declare(strict_types=1);

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoDeAjuste;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaAjustada;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Services\CalculadoraDeCostoPromedio;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeAjuste;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Support\Facades\DB;

/**
 * GOLDEN TEST del promedio ponderado MÓVIL.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA QUE ESTE ARCHIVO PROTEGE
 * ─────────────────────────────────────────────────────────────────────
 *
 * De `docs/dominio-inventario-y-precios.md`, decisión cerrada:
 *
 *   «Si un producto se agota y se vuelve a comprar, el promedio arranca
 *    del costo nuevo.»
 *
 * Eso solo se cumple si `cantidad_base` BAJA cuando baja la existencia.
 * Antes del bloque 5d-1 solo subía —`absorber()` era el único que la
 * escribía— y no se notaba porque no existía ninguna salida.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE COSTABA EL BUG, AL CÉNTIMO
 * ─────────────────────────────────────────────────────────────────────
 *
 *     Compra 1 · 100 u a L 5,00   →  base 100 · promedio L 5,00
 *     Se pierden las 100          →  base tiene que quedar en 0
 *     Compra 2 ·  10 u a L 20,00  →  promedio correcto: L 20,00
 *
 * Con la base sin bajar:  (100×5 + 10×20) / 110 = L 6,363636.
 *
 * Y con margen objetivo 120 % sobre un descuento legal máximo del 25 %,
 * el precio de lista resulta de multiplicar el costo por 2,933333:
 *
 *     correcto     L 20,000000 × 2,933333 = L 58,67
 *     con el bug   L  6,363636 × 2,933333 = L 18,67
 *
 * Se vendería a L 18,67 algo que costó L 20,00 — a pérdida, en todas las
 * ventas de ese producto, hasta el próximo cierre contable.
 */
function costoMovil(): CalculadoraDeCostoPromedio
{
    return app(CalculadoraDeCostoPromedio::class);
}

function existenciaMovil(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/**
 * @param numeric-string $unidades
 * @param numeric-string $costo
 */
function entradaMovil(Item $item, Almacen $almacen, string $unidades, string $costo, string $lote): void
{
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [
            new LineaRecibida(
                item: $item,
                presentacion: null,
                cantidadPresentacion: Decimal::de($unidades),
                unidadesPorPresentacion: Decimal::de('1'),
                costoPorPresentacion: Decimal::de($costo),
                numeroLote: $lote,
                vencimiento: now()->addYear(),
            ),
        ],
    );
}

/**
 * @param numeric-string $unidades
 */
function salidaMovil(Item $item, Almacen $almacen, Lote $lote, string $unidades): void
{
    app(RegistradorDeAjuste::class)->registrar(
        almacen: $almacen,
        tipo: TipoDeAjuste::Merma,
        lineas: [
            new LineaAjustada(
                item: $item,
                lote: $lote,
                motivo: MotivoDeAjuste::CadenaDeFrioRota,
                cantidad: Decimal::de($unidades),
                esEntrada: false,
            ),
        ],
        motivo: 'El refrigerador se apagó durante la noche',
    );
}

/**
 * @return array{0: Item, 1: Almacen}
 */
function escenarioMovil(): array
{
    return [
        Item::factory()->medicamento()->create(['requiere_lote' => true]),
        Almacen::factory()->create(),
    ];
}

function baseDelCostoMovil(Item $item, Almacen $almacen): ?string
{
    $fila = DB::table('costos_promedio')
        ->where('item_id', $item->id)
        ->where('almacen_id', $almacen->id)
        ->first();

    $base = $fila?->cantidad_base;

    return is_string($base) ? $base : null;
}

/*
|--------------------------------------------------------------------------
| El caso que da nombre al archivo
|--------------------------------------------------------------------------
*/

it('agotar y volver a comprar reinicia el promedio al costo nuevo', function (): void {
    actingAsAdmin();

    [$item, $bodega] = escenarioMovil();

    entradaMovil($item, $bodega, '100', '5', 'L-1');

    expect(costoMovil()->vigente($item, $bodega)->redondeado(4))->toBe('5.0000')
        ->and(baseDelCostoMovil($item, $bodega))->toBe('100.0000');

    salidaMovil($item, $bodega, Lote::query()->where('numero', 'L-1')->firstOrFail(), '100');

    expect(existenciaMovil()->totalEn($item, $bodega)->redondeado(0))->toBe('0')
        ->and(baseDelCostoMovil($item, $bodega))->toBe('0.0000')
        // El costo NO cambió con la salida: sigue siendo lo que costó.
        ->and(costoMovil()->vigente($item, $bodega)->redondeado(4))->toBe('5.0000');

    entradaMovil($item, $bodega, '10', '20', 'L-2');

    expect(costoMovil()->vigente($item, $bodega)->redondeado(4))->toBe('20.0000')
        ->and(baseDelCostoMovil($item, $bodega))->toBe('10.0000');
})->note('L 20,0000 exacto, no L 6,363636. La diferencia entre vender con 120 % de margen y vender a pérdida.');

it('una salida parcial pondera la compra siguiente contra lo que de verdad queda', function (): void {
    actingAsAdmin();

    [$item, $bodega] = escenarioMovil();

    entradaMovil($item, $bodega, '100', '10', 'L-1');
    salidaMovil($item, $bodega, Lote::query()->where('numero', 'L-1')->firstOrFail(), '60');

    expect(baseDelCostoMovil($item, $bodega))->toBe('40.0000');

    entradaMovil($item, $bodega, '60', '20', 'L-2');

    /*
     * (40 × 10 + 60 × 20) / 100 = 1.600 / 100 = L 16,00
     *
     * Con la base sin corregir habría sido (100×10 + 60×20)/160 = L 13,75,
     * y el hospital creería que su inventario vale menos de lo que vale.
     */
    expect(costoMovil()->vigente($item, $bodega)->redondeado(4))->toBe('16.0000')
        ->and(baseDelCostoMovil($item, $bodega))->toBe('100.0000');
});

it('un sobrante sube la cantidad base sin tocar el costo', function (): void {
    actingAsAdmin();

    [$item, $bodega] = escenarioMovil();

    entradaMovil($item, $bodega, '100', '10', 'L-1');

    app(RegistradorDeAjuste::class)->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Correccion,
        lineas: [
            new LineaAjustada(
                item: $item,
                lote: Lote::query()->where('numero', 'L-1')->firstOrFail(),
                motivo: MotivoDeAjuste::ErrorDeRegistro,
                cantidad: Decimal::de('20'),
                esEntrada: true,
            ),
        ],
        motivo: 'La recepción del lunes se digitó con veinte de menos',
    );

    expect(baseDelCostoMovil($item, $bodega))->toBe('120.0000')
        ->and(costoMovil()->vigente($item, $bodega)->redondeado(4))->toBe('10.0000');
})->note('Veinte unidades que aparecieron no traen información de costo: mezclarlas al promedio con un cero movería el precio de venta de todo el producto por un error de conteo.');

it('la cantidad base siempre coincide con la suma de las existencias', function (): void {
    actingAsAdmin();

    [$item, $bodega] = escenarioMovil();

    entradaMovil($item, $bodega, '100', '10', 'L-1');
    entradaMovil($item, $bodega, '50', '12', 'L-2');
    salidaMovil($item, $bodega, Lote::query()->where('numero', 'L-1')->firstOrFail(), '30');
    salidaMovil($item, $bodega, Lote::query()->where('numero', 'L-2')->firstOrFail(), '20');

    expect(baseDelCostoMovil($item, $bodega))
        ->toBe(existenciaMovil()->totalEn($item, $bodega)->paraBase(4));
})->note('Es la invariante entera: si estas dos se separan, el promedio móvil miente y el precio de lista con él.');
