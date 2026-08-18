<?php

declare(strict_types=1);

use App\Domain\Enums\MagnitudDeMedida;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| El mismo producto viene de varias formas
|--------------------------------------------------------------------------
*/

it('acepta dos presentaciones distintas del mismo item', function (): void {
    $item = Item::factory()->medicamento()->create();
    $caja = Unidad::factory()->create(['codigo' => 'CAJA']);

    ItemPresentacion::factory()->for($item)->create([
        'unidad_id'                 => $caja->getKey(),
        'nombre'                    => 'CAJA X 100',
        'unidades_por_presentacion' => '100.0000',
        'es_predeterminada'         => true,
    ]);

    ItemPresentacion::factory()->for($item)->create([
        'unidad_id'                 => $caja->getKey(),
        'nombre'                    => 'CAJA X 50',
        'unidades_por_presentacion' => '50.0000',
    ]);

    expect($item->presentaciones()->count())->toBe(2);
})->note('El mismo medicamento se compra en caja de 100 a un proveedor y de 50 a otro. Con una sola equivalencia en el ítem, la segunda compra se convierte a mano — y ahí nace el costo cien veces más alto que nadie nota hasta el cierre.');

it('no deja cargar dos veces la misma presentacion', function (): void {
    $item = Item::factory()->medicamento()->create();
    $caja = Unidad::factory()->create(['codigo' => 'CAJA']);

    ItemPresentacion::factory()->for($item)->create([
        'unidad_id'                 => $caja->getKey(),
        'nombre'                    => 'CAJA X 100',
        'unidades_por_presentacion' => '100.0000',
    ]);

    ItemPresentacion::factory()->for($item)->create([
        'unidad_id'                 => $caja->getKey(),
        'nombre'                    => 'OTRO NOMBRE',
        'unidades_por_presentacion' => '100.0000',
    ]);
})->throws(QueryException::class)
    ->note('Dos filas idénticas son la misma presentación cargada dos veces, y quien recibe la compra elige a ciegas.');

it('no deja dos presentaciones predeterminadas', function (): void {
    $item = Item::factory()->medicamento()->create();

    ItemPresentacion::factory()->for($item)->predeterminada()->create([
        'unidades_por_presentacion' => '100.0000',
    ]);

    ItemPresentacion::factory()->for($item)->predeterminada()->create([
        'unidades_por_presentacion' => '50.0000',
    ]);
})->throws(QueryException::class)
    ->note('Con dos por defecto, la que gana depende del ORDER BY.');

it('el codigo de barras es unico en todo el catalogo', function (): void {
    ItemPresentacion::factory()->create(['codigo_barras' => '7501234567890']);
    ItemPresentacion::factory()->create(['codigo_barras' => '7501234567890']);
})->throws(QueryException::class)
    ->note('El lector no sabe qué ítem está leyendo: eso es precisamente lo que va a averiguar.');

it('permite muchas presentaciones sin codigo de barras', function (): void {
    ItemPresentacion::factory()->count(3)->create(['codigo_barras' => null]);

    expect(ItemPresentacion::query()->count())->toBe(3);
})->note('El índice único es parcial: el NULL no compite consigo mismo.');

it('no acepta una presentacion vacia', function (): void {
    ItemPresentacion::factory()->create(['unidades_por_presentacion' => '0']);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Conversión — con bcmath, nunca con float
|--------------------------------------------------------------------------
*/

it('convierte cajas a unidades de dispensacion', function (): void {
    $presentacion = ItemPresentacion::factory()->create([
        'unidades_por_presentacion' => '100.0000',
    ]);

    expect($presentacion->aUnidadesDeDispensacion('3'))->toBe('300.0000')
        ->and($presentacion->desdeUnidadesDeDispensacion('150'))->toBe('1.5000');
})->note('El kardex se lleva SIEMPRE en la unidad mínima de dispensación. La conversión se aplica ANTES de promediar el costo.');

it('convierte contenidos fraccionarios sin perder centesimas', function (): void {
    $presentacion = ItemPresentacion::factory()->create([
        'unidades_por_presentacion' => '0.1000',
    ]);

    expect($presentacion->aUnidadesDeDispensacion('3'))->toBe('0.3000');
})->note('§8.6.2: en punto flotante 3 × 0.1 no da 0.3, y ese error se acumula movimiento tras movimiento hasta que el inventario deja de cuadrar con contabilidad.');

/*
|--------------------------------------------------------------------------
| Unidades
|--------------------------------------------------------------------------
*/

it('sabe cuando una unidad esta en uso', function (): void {
    $enUso = Unidad::factory()->create(['codigo' => 'AMP']);
    $libre = Unidad::factory()->create(['codigo' => 'SOBRE']);

    Item::factory()->medicamento()->create(['unidad_dispensacion_id' => $enUso->getKey()]);

    expect($enUso->estaEnUso())->toBeTrue()
        ->and($libre->estaEnUso())->toBeFalse();
})->note('Borrar una unidad en uso dejaría ítems sin unidad de kardex. La FK lo impide; esto permite no ofrecer el botón.');

it('no deja dos unidades con el mismo codigo', function (): void {
    Unidad::factory()->create(['codigo' => 'ML']);
    Unidad::factory()->create(['codigo' => 'ML']);
})->throws(QueryException::class);

it('el volumen admite fraccion y el conteo no', function (): void {
    expect(MagnitudDeMedida::Volumen->admiteFraccionPorNaturaleza())->toBeTrue()
        ->and(MagnitudDeMedida::Conteo->admiteFraccionPorNaturaleza())->toBeFalse();
})->note('Media ampolla que se abrió no es media existencia: es una ampolla consumida y una merma.');
