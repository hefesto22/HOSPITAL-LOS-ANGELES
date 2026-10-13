<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use App\Filament\Pages\ConsumoDeProductos;
use App\Models\Almacen;
use App\Services\AnuladorDeCargo;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Consumo de productos: lo que se usó y de dónde salió (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio, sobre el stock infinito: «se va tomando cuánto se va usando,
| pero solo para reportaje de cuánto de qué se usó… esa semana, ese mes
| o ese día, y de dónde se sacó».
|
| Los ayudantes (`unMedicamentoDeStockInfinito()`, `cargarConStockInfinito()`,
| …) viven en `tests/Pest.php`.
*/

it('🔴 cuenta lo que se usó con stock infinito y dice de qué estante salió', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();
    $emergencia = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create();

    cargarConStockInfinito($cuenta, $item, '3', $farmacia);
    cargarConStockInfinito($cuenta, $item, '2', $emergencia);

    $pantalla = app(ConsumoDeProductos::class);
    $productos = $pantalla->productos();

    expect($productos)->toHaveCount(1)
        ->and($productos[0]['id'])->toBe($item->id)
        ->and($productos[0]['infinito'])->toBeTrue()
        ->and($productos[0]['veces'])->toBe(2)
        ->and($productos[0]['cantidad'])->toStartWith('5')
        ->and($productos[0]['deDonde'])->toBe($farmacia->nombre.' 3 · '.$emergencia->nombre.' 2')
        ->and($pantalla->totalDeUsos())->toBe(2)
        ->and(collect($pantalla->almacenes())->pluck('veces', 'id')->all())
        ->toEqual([$farmacia->id => 1, $emergencia->id => 1]);
});

it('elegir un estante deja solo lo que salió de ahí', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();
    $emergencia = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create();

    cargarConStockInfinito($cuenta, $item, '3', $farmacia);
    cargarConStockInfinito($cuenta, $item, '2', $emergencia);

    $pantalla = app(ConsumoDeProductos::class);
    $pantalla->almacen = $emergencia->id;

    $productos = $pantalla->productos();

    expect($productos)->toHaveCount(1)
        ->and($productos[0]['veces'])->toBe(1)
        ->and($productos[0]['cantidad'])->toStartWith('2')
        ->and(collect($pantalla->almacenes())->firstWhere('activo', true)['id'] ?? null)->toBe($emergencia->id);
});

it('🔴 lo que se cargó por error y se anuló no cuenta como usado', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $cargo = cargarConStockInfinito($cuenta, $item, '3', $farmacia)->firstOrFail();

    app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGO AL PACIENTE EQUIVOCADO');

    $pantalla = app(ConsumoDeProductos::class);

    expect($pantalla->productos())->toBe([])
        ->and($pantalla->totalDeUsos())->toBe(0);
})->note('El cargo anulado y su reversa salen los dos: ni +3 ni −3, nada.');

it('cuenta también lo que salió del inventario, sin la marca de infinito', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito(infinito: false);
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    recibirParaElStockInfinito($item, $farmacia, '10');
    cargarConStockInfinito($cuenta, $item, '4', $farmacia);

    $productos = app(ConsumoDeProductos::class)->productos();

    expect($productos)->toHaveCount(1)
        ->and($productos[0]['infinito'])->toBeFalse()
        ->and($productos[0]['cantidad'])->toStartWith('4');
})->note('El día que un producto pasa de infinito a real, el reporte no se parte en dos.');

it('solo cuenta lo del período elegido', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();

    cargarConStockInfinito($cuenta, $item, '3');

    $pantalla = app(ConsumoDeProductos::class);
    $mesPasado = now()->toImmutable()->subMonthNoOverflow();
    $pantalla->desde = $mesPasado->startOfMonth()->toDateString();
    $pantalla->hasta = $mesPasado->endOfMonth()->toDateString();

    expect($pantalla->productos())->toBe([])
        ->and(app(ConsumoDeProductos::class)->productos())->toHaveCount(1);
});

it('los atajos son hoy, esta semana —de lunes a domingo—, este mes y el pasado', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));

    $atajos = collect(app(ConsumoDeProductos::class)->periodos())->pluck('rango', 'clave')->all();

    expect($atajos)->toBe([
        'hoy'         => '15/10',
        'esta_semana' => '12/10 – 18/10',
        'este_mes'    => '01/10 – 31/10',
        'mes_pasado'  => '01/09 – 30/09',
    ]);
});
