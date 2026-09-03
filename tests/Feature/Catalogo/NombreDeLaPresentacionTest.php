<?php

declare(strict_types=1);

use App\Models\ItemPresentacion;
use App\Models\Producto;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL NOMBRE DEL PRODUCTO SE ESCRIBE UNA SOLA VEZ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio (3-sep-2026): «que sea la base el nombre y otro
 * campo para agregar la presentación como caja 100 tabletas, y así el
 * nombre base no se cambie».
 *
 * La presentación GUARDA solo el envase —«CAJA X 100 TABLETAS»— y la
 * base se lee del producto al mostrarla. Antes se guardaba «PRODUCTO /
 * ENVASE» desde el formulario y «ENVASE» desde el seeder: dos
 * convenciones en la misma columna, el nombre del producto escrito dos
 * veces, y las dos copias separándose el día que alguien corrige la
 * ficha.
 *
 * Lo que cuidan estas pruebas es que las dos mitades no se vuelvan a
 * pegar en la base y que ninguna pantalla muestre una sin la otra:
 *
 *   · `envase()`         → «CAJA X 100 TABLETAS»
 *   · `nombreCompleto()` → «ACETAMINOFEN 500 MG TABLETA CAJA X 100 TABLETAS»
 */
it('guarda solo el envase y compone el nombre al leerlo', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'ACETAMINOFEN 500 MG TABLETA']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'CAJA X 100 TABLETAS',
    ]);

    expect($presentacion->envase())->toBe('CAJA X 100 TABLETAS')
        ->and($presentacion->nombreCompleto())
        ->toBe('ACETAMINOFEN 500 MG TABLETA CAJA X 100 TABLETAS');
})->note('Guardado en dos pedazos, mostrado entero. Una sola copia del nombre del producto, imposible de desincronizar.');

it('🔴 renombrar el producto renombra sus presentaciones', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'ACETAMINOFEN 500 MG TABLETA']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'CAJA X 100 TABLETAS',
    ]);

    $producto->update(['nombre' => 'ACETAMINOFEN 500 MG TABLETA RANURADA']);

    expect($presentacion->fresh()?->nombreCompleto())
        ->toBe('ACETAMINOFEN 500 MG TABLETA RANURADA CAJA X 100 TABLETAS');
})->note('🔴 Esto es lo que compraba la regla. Con el nombre escrito dos veces, corregir la ficha dejaba las presentaciones diciendo el nombre viejo — en el desplegable de la compra y en la etiqueta impresa.');

it('la etiqueta de la presentacion es el nombre entero', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'IBUPROFENO 400 MG TABLETA']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'BLISTER X 10 TABLETAS',
    ]);

    expect($presentacion->etiqueta())->toBe($presentacion->nombreCompleto());
})->note('Cuando la presentación aparece sola —en el desplegable de la compra, en la línea del lote, en la etiqueta— tiene que decir de qué producto es.');

/*
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE ENTRÓ POR OTRA PUERTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La migración del 3-sep-2026 le cortó el prefijo a lo que ya estaba,
 * pero `envase()` lo sigue recortando al leer: un import del catálogo
 * viejo, un comando, o una fila que la migración no alcanzó porque el
 * producto ya se había renombrado.
 */
it('le quita la pleca a lo que entro con la convencion vieja', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'ACETAMINOFEN 500 MG TABLETA']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'ACETAMINOFEN 500 MG TABLETA / CAJA X 100 TABLETAS',
    ]);

    expect($presentacion->envase())->toBe('CAJA X 100 TABLETAS')
        ->and($presentacion->nombreCompleto())
        ->toBe('ACETAMINOFEN 500 MG TABLETA CAJA X 100 TABLETAS');
})->note('El producto no se nombra dos veces aunque la fila venga con las dos mitades pegadas.');

it('🔴 corta por la ultima pleca y no por la primera', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'DICLOFENACO 75 MG / 3 ML AMPOLLA']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'DICLOFENACO 75 MG / 3 ML AMPOLLA / CAJA X 5 AMPOLLAS',
    ]);

    expect($presentacion->envase())->toBe('CAJA X 5 AMPOLLAS');
})->note('🔴 Hay nombres de medicamento con pleca adentro. Cortar por la primera dejaba «3 ML AMPOLLA / CAJA X 5», que es peor que no cortar nada.');

it('🔴 no recorta palabras sueltas que el producto tambien dice', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'FRASCO DE SUERO FISIOLOGICO 500 ML']);

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => 'FRASCO X 1',
    ]);

    expect($presentacion->envase())->toBe('FRASCO X 1');
})->note('🔴 El recorte viejo comparaba palabra por palabra y aca se habria comido «FRASCO», dejando «X 1». Se corta por la pleca o no se corta nada: adivinar sobre un dato que despues se imprime en una etiqueta no es una opcion.');

it('el nombre entero es solo el envase cuando la presentacion no tiene producto cargado', function (): void {
    $presentacion = ItemPresentacion::factory()->make(['nombre' => 'CAJA X 100 TABLETAS']);
    $presentacion->setRelation('item', null);

    expect($presentacion->nombreCompleto())->toBe('CAJA X 100 TABLETAS');
})->note('Estos lectores se llaman desde consultas que a veces no traen el ítem. Un dato de menos, no una pantalla rota.');
