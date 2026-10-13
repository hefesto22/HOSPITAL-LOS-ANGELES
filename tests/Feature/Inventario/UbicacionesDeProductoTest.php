<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\UbicacionException;
use App\Filament\Pages\Existencias;
use App\Models\Almacen;
use App\Models\UbicacionDeProducto;
use App\Services\CambiadorDeStockInfinito;
use App\Services\UbicadorDeProducto;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Dónde está lo que tiene stock infinito (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «Mover debería estar disponible; si no, ¿cómo se marca qué
| productos están en el carrito rojo, en farmacia y en bodega? Al crear
| un producto, por defecto va a farmacia, pero se debe poder seleccionar
| si se crea en farmacia y bodega. Así, al vender, saben de dónde lo
| sacaron».
|
| Los ayudantes (`unMedicamentoDeStockInfinito()`, …) viven en
| `tests/Pest.php`.
*/

function ubicador(): UbicadorDeProducto
{
    return app(UbicadorDeProducto::class);
}

/**
 * Una farmacia y una bodega de la misma sede: mover entre sedes no vale.
 *
 * @return array{farmacia: Almacen, bodega: Almacen, carrito: Almacen}
 */
function tresAlmacenesDeUnaSede(): array
{
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    return [
        'farmacia' => $farmacia,
        'bodega'   => Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create(['sede_id' => $farmacia->sede_id]),
        'carrito'  => Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['sede_id' => $farmacia->sede_id]),
    ];
}

it('🔴 al marcarlo infinito queda en los almacenes donde tenía existencia', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    ['farmacia' => $farmacia, 'bodega' => $bodega, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    recibirParaElStockInfinito($item, $farmacia, '5');
    recibirParaElStockInfinito($item, $bodega, '20');

    app(CambiadorDeStockInfinito::class)->cambiar([$item], true);

    expect(ubicador()->dondeEsta($item->refresh()))->toEqualCanonicalizing([$farmacia->id, $bodega->id])
        ->and(ubicador()->dondeEsta($item))->not->toContain($carrito->id)
        ->and(elSaldoDe($item, $bodega))->toBe('0.0000');
})->note('«Si tenía stock pasa a infinito»: deja de contarse, pero sigue estando donde estaba.');

it('sin existencia en ningún lado, al marcarlo queda en farmacia', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    ['farmacia' => $farmacia] = tresAlmacenesDeUnaSede();

    app(CambiadorDeStockInfinito::class)->cambiar([$item], true);

    expect(ubicador()->dondeEsta($item->refresh()))->toBe([$farmacia->id]);
});

it('🔴 mover con «sigue habiendo» lo deja en los dos almacenes', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $farmacia);
    ubicador()->mover($item, $farmacia, $carrito, quedaEnOrigen: true);

    expect(ubicador()->dondeEsta($item))->toEqualCanonicalizing([$farmacia->id, $carrito->id]);
});

it('🔴 mover sin «sigue habiendo» cierra el origen, sin borrarlo', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $farmacia);
    ubicador()->mover($item, $farmacia, $carrito, quedaEnOrigen: false);

    $cerrada = UbicacionDeProducto::query()
        ->where('item_id', $item->id)
        ->where('almacen_id', $farmacia->id)
        ->first();

    expect(ubicador()->dondeEsta($item))->toBe([$carrito->id])
        ->and($cerrada)->toBeInstanceOf(UbicacionDeProducto::class)
        ->and($cerrada?->hasta)->not->toBeNull()
        ->and($cerrada?->motivo_cierre)->toContain('SE LLEVÓ TODO');
})->note('Se puede contestar «¿estaba en farmacia el sábado?»: la fila queda, cerrada.');

it('no se le quita a mano el último almacén', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $farmacia);

    expect(fn () => ubicador()->retirar($item, $farmacia, UbicadorDeProducto::MOTIVO_A_MANO))
        ->toThrow(UbicacionException::class);

    ubicador()->ubicar($item, $carrito);

    expect(ubicador()->retirar($item, $farmacia, UbicadorDeProducto::MOTIVO_A_MANO))->toBeTrue()
        ->and(ubicador()->dondeEsta($item))->toBe([$carrito->id]);
});

it('ubicar dos veces el mismo almacén no deja dos filas abiertas', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia] = tresAlmacenesDeUnaSede();

    expect(ubicador()->ubicar($item, $farmacia))->toBeTrue()
        ->and(ubicador()->ubicar($item, $farmacia))->toBeFalse()
        ->and(UbicacionDeProducto::query()->where('item_id', $item->id)->count())->toBe(1);
});

it('🔴 al cargarlo a una cuenta solo sale de un almacén donde está', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'bodega' => $bodega] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $farmacia);

    expect(fn () => cargarConStockInfinito($cuenta, $item, '1', $bodega))->toThrow(CargoException::class);

    $cargo = cargarConStockInfinito($cuenta, $item, '2', $farmacia)->firstOrFail();

    expect($cargo->almacen_id)->toBe($farmacia->id)
        ->and($cargo->movimiento_id)->toBeNull();
})->note('Así, en el historial, «de dónde se sacó» es siempre un lugar donde de verdad había.');

it('un infinito sin ningún lugar anotado sale de cualquier almacén', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    ['bodega' => $bodega] = tresAlmacenesDeUnaSede();

    $cargo = cargarConStockInfinito($cuenta, $item, '1', $bodega)->firstOrFail();

    expect($cargo->almacen_id)->toBe($bodega->id);
})->note('Los que se marcaron antes de esta regla: antes que trabar un cargo, se acepta y queda anotado de dónde salió.');

it('al pasarlo a stock real se cierran todos sus lugares', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $farmacia);
    ubicador()->ubicar($item, $carrito);

    app(CambiadorDeStockInfinito::class)->cambiar([$item], false);

    expect(ubicador()->dondeEsta($item->refresh()))->toBe([])
        ->and(UbicacionDeProducto::query()->where('item_id', $item->id)->whereNotNull('hasta')->count())->toBe(2);
});

it('lo que lleva existencia real no se ubica a mano', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    ['farmacia' => $farmacia] = tresAlmacenesDeUnaSede();

    expect(fn () => ubicador()->ubicar($item, $farmacia))->toThrow(UbicacionException::class);
});

it('la ficha deja el producto exactamente donde se marcó', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'bodega' => $bodega, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->dejarEn($item, [$farmacia->id, $bodega->id]);
    ubicador()->dejarEn($item, [$bodega->id, $carrito->id]);

    expect(ubicador()->dondeEsta($item))->toEqualCanonicalizing([$bodega->id, $carrito->id]);
});

it('🔴 en Existencias un infinito no muestra su existencia vieja, solo sus lugares', function (): void {
    $real = unMedicamentoDeStockInfinito(infinito: false);
    $infinito = unMedicamentoDeStockInfinito(infinito: false);
    ['farmacia' => $farmacia, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    recibirParaElStockInfinito($real, $farmacia, '8');
    recibirParaElStockInfinito($infinito, $farmacia, '5');

    /*
     * Como quedaron los que se marcaron antes de la regla: la bandera
     * puesta y la existencia viva.
     */
    DB::table('items')->where('id', $infinito->id)->update(['stock_infinito' => true]);
    ubicador()->ubicar($infinito->refresh(), $carrito);

    $pantalla = app(Existencias::class);
    $vistas = collect($pantalla->vistas())->pluck('cuantos', 'clave')->all();

    expect($vistas['todo'])->toBe(2)
        ->and($vistas['infinito'])->toBe(1)
        ->and($pantalla->totalDeProductos())->toBe(2)
        ->and(collect($pantalla->almacenes())->pluck('productos', 'id')->all())
        ->toEqual([$farmacia->id => 1, $carrito->id => 1]);
})->note('Mauricio: «si son infinitos no tienen fecha de vencimiento, ¿por qué siguen apareciendo?». Su existencia vieja no cuenta en ningún lado.');

it('los almacenes de arriba van con farmacia primero y bodega al final', function (): void {
    $item = unMedicamentoDeStockInfinito();
    ['farmacia' => $farmacia, 'bodega' => $bodega, 'carrito' => $carrito] = tresAlmacenesDeUnaSede();

    ubicador()->ubicar($item, $bodega);
    ubicador()->ubicar($item, $carrito);
    ubicador()->ubicar($item, $farmacia);

    expect(collect(app(Existencias::class)->almacenes())->pluck('id')->all())
        ->toBe([$farmacia->id, $carrito->id, $bodega->id]);
})->note('Mauricio: «el primero en aparecer debe ser farmacia; todos debe estar de último».');

it('el comando deja en regla a los infinitos de antes: sin existencia y con su lugar', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    ['bodega' => $bodega] = tresAlmacenesDeUnaSede();

    recibirParaElStockInfinito($item, $bodega, '6');
    DB::table('items')->where('id', $item->id)->update(['stock_infinito' => true]);

    $this->artisan('sihla:ordenar-stock-infinito')->assertSuccessful();

    expect(elSaldoDe($item, $bodega))->toBe('0.0000')
        ->and(ubicador()->dondeEsta($item->refresh()))->toBe([$bodega->id]);
});
