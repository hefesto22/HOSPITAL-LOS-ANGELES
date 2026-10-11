<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\ItemPresentacion;
use Illuminate\Database\QueryException;

/*
 * EL CÓDIGO DE BARRAS DEL PRODUCTO (Mauricio, 10-oct-2026).
 *
 * Se carga una vez en la ficha —el de la caja del fabricante— y lo
 * heredan todos los envases: escanearlo encuentra el producto en
 * cualquiera de ellos. El acetaminofén de la marca X y el de la marca Y
 * son dos productos con dos códigos distintos.
 */
it('🔴 el codigo de fabrica encuentra su producto y no el de otra marca', function (): void {
    $marcaX = Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']);
    $marcaY = Item::factory()->medicamento()->create(['codigo_barras' => '7409876543210']);

    expect(Item::porCodigo('7401234567890')?->id)->toBe($marcaX->id)
        ->and(Item::porCodigo('7409876543210')?->id)->toBe($marcaY->id)
        ->and(Item::porCodigo(' 7401234567890 ')?->id)->toBe($marcaX->id);
})->note('🔴 Dos marcas del mismo principio activo, dos códigos: escanear la caja dice cuál de las dos se tiene en la mano.');

it('el codigo interno tambien encuentra el producto, sin importar mayusculas', function (): void {
    $item = Item::factory()->medicamento()->create();

    expect(Item::porCodigo(mb_strtolower($item->codigo))?->id)->toBe($item->id);
});

it('un codigo que no es de nadie no encuentra nada, y uno vacio tampoco', function (): void {
    Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']);

    expect(Item::porCodigo('7400000000000'))->toBeNull()
        ->and(Item::porCodigo(''))->toBeNull()
        ->and(Item::porCodigo('   '))->toBeNull()
        ->and(Item::porCodigo(null))->toBeNull();
});

it('el buscador del mostrador encuentra el producto por su codigo de barras', function (): void {
    $item = Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']);

    expect(Item::buscar('7401234567890')->pluck('id')->all())->toContain($item->id);
});

it('lo heredan todos sus envases: el blister sin codigo propio se encuentra igual', function (): void {
    $item = Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']);

    ItemPresentacion::factory()
        ->conContenido('10.0000', 'BLISTER X 10 TABLETAS')
        ->create(['item_id' => $item->id, 'codigo_barras' => null]);

    expect(Item::porCodigo('7401234567890')?->presentaciones()->count())->toBe(1);
});

it('vacio se guarda como nulo, asi dos productos sin codigo no chocan', function (): void {
    $uno = Item::factory()->medicamento()->create(['codigo_barras' => '  ']);
    $otro = Item::factory()->medicamento()->create(['codigo_barras' => '']);

    expect($uno->refresh()->codigo_barras)->toBeNull()
        ->and($otro->refresh()->codigo_barras)->toBeNull();
});

it('🔴 la base no deja dos productos con el mismo codigo', function (): void {
    Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']);

    expect(fn () => Item::factory()->medicamento()->create(['codigo_barras' => '7401234567890']))
        ->toThrow(QueryException::class);
})->note('Con dos, la pistola devolvería el que el ORDER BY quiera: silencioso y distinto cada vez.');
