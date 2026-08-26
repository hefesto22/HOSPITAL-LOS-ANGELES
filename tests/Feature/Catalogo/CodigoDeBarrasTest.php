<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Producto;
use App\Support\CodigoDeBarras;

/**
 * EL CÓDIGO DE BARRAS QUE IMPRIME EL HOSPITAL.
 *
 * El hospital reenvasa, así que el blíster que sale de farmacia no lleva
 * el código del fabricante: lleva el interno, impreso por ellos. Lo que
 * este archivo cuida es que lo impreso sea legible de verdad — un código
 * mal armado no da error, da una etiqueta que el lector no lee, y eso se
 * descubre con el paciente esperando.
 */
it('arma un Code 128 con la cantidad exacta de modulos', function (): void {
    $svg = CodigoDeBarras::svg('MED-0027', modulo: 2, alto: 40);

    /*
     * Code 128: once módulos por símbolo —inicio, cada carácter, y la
     * suma de verificación— más trece del patrón de fin.
     */
    $modulos = 11 * (1 + mb_strlen('MED-0027') + 1) + 13;

    expect($svg)->toContain('width="'.($modulos * 2).'"')
        ->and($svg)->toStartWith('<svg')
        ->and($svg)->toContain('MED-0027');
})->note('Si sobran o faltan módulos, el lector devuelve otra cosa o no lee nada. El ancho es la forma barata de verificar la estructura sin un escáner en la mano.');

it('se niega a dibujar lo que no puede codificar', function (): void {
    expect(CodigoDeBarras::svg('ACETAMINOFÉN'))->toBe('')
        ->and(CodigoDeBarras::codificable('MED-0027'))->toBe('MED-0027')
        ->and(CodigoDeBarras::codificable('  MED-0027  '))->toBe('MED-0027');
})->note('Code 128-B no cubre la tilde. Dibujarla mal imprimiría una etiqueta que el lector lee distinto de lo que dice el papel — peor que no imprimir ninguna.');

it('escanear el codigo del hospital encuentra el producto', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0027']);

    expect(Item::query()->buscar('MED-0027')->pluck('items.id'))
        ->toContain($producto->getKey());
})->note('Es el caso de todos los días: la etiqueta del reenvasado lleva el código interno.');

it('escanear la caja del proveedor tambien encuentra el producto', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0031']);

    ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => '7501234567890',
    ]);

    $encontrados = Item::query()->buscar('7501234567890')->pluck('items.id');

    expect($encontrados)->toContain($producto->getKey());
})->note('Al recibir mercadería se escanea la caja, que sí trae el EAN del fabricante. Vive en la presentación de compra porque el mismo producto se compra de dos marcas distintas.');

it('🔴 el codigo del proveedor se compara exacto, no por parecido', function (): void {
    $conEan = Producto::factory()->create(['codigo' => 'MED-0032']);
    $otro = Producto::factory()->create(['codigo' => 'MED-0033']);

    ItemPresentacion::factory()->create([
        'item_id'       => $conEan->getKey(),
        'codigo_barras' => '7501234567890',
    ]);

    $encontrados = Item::query()->buscar('750123')->pluck('items.id');

    expect($encontrados)->not->toContain($conEan->getKey())
        ->and($encontrados)->not->toContain($otro->getKey());
})->note('🔴 Los EAN comparten prefijo de país y de empresa: un LIKE parcial devolvería el producto equivocado, y en farmacia eso es dispensar otra cosa.');
