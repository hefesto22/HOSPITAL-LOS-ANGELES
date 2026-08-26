<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\PrincipioActivo;
use App\Models\Producto;

/**
 * LA ETIQUETA DE LA GAVETA NO ELIGE: ACOTA.
 *
 * En el mostrador se escanean dos cosas que se parecen y no son lo
 * mismo:
 *
 *   · el código del ENVASE, que identifica UN producto y lo carga;
 *   · el de la GAVETA —`PA-0001`—, que identifica una MOLÉCULA.
 *
 * El acetaminofén vive en tableta, en jarabe y en supositorio. Que la
 * segunda lectura eligiera «el primero que aparezca» sería dispensar una
 * forma farmacéutica por otra, con el paciente enfrente. Por eso la
 * gaveta ofrece los que hay y quien dispensó dice cuál fue.
 *
 * Que el prefijo alcance para distinguirlas lo cuida
 * `PrincipioActivoTest`. Acá se cuida lo que se hace DESPUÉS de
 * distinguirlas.
 */
it('🔴 buscar el codigo de la gaveta en el catalogo no devuelve nada', function (): void {
    $principio = PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'ACETAMINOFÉN',
        'vigencia_desde' => now(),
    ]);

    $producto = Producto::factory()->create([
        'codigo' => 'MED-0801',
        'nombre' => 'ACETAMINOFEN 500 MG TABLETA',
    ]);

    $producto->principiosActivos()->attach($principio);

    expect(Item::buscar('PA-0001', soloVigentes: true))->toBeEmpty();
})->note('🔴 Es la razón de que la rama del prefijo exista y no sea un adorno: el buscador del catálogo no sabe nada de gavetas, así que sin ella escanear la etiqueta del estante no encontraba nada y parecía que el producto no estaba cargado.');

it('la gaveta devuelve todas las formas de esa molecula', function (): void {
    $principio = PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'ACETAMINOFÉN',
        'vigencia_desde' => now(),
    ]);

    $tableta = Producto::factory()->create([
        'codigo' => 'MED-0802',
        'nombre' => 'ACETAMINOFEN 500 MG TABLETA',
    ]);

    $jarabe = Producto::factory()->create([
        'codigo' => 'MED-0803',
        'nombre' => 'ACETAMINOFEN JARABE',
    ]);

    $principio->items()->attach([$tableta->getKey(), $jarabe->getKey()]);

    expect($principio->productosVigentes()->pluck('id')->all())
        ->toEqualCanonicalizing([$tableta->getKey(), $jarabe->getKey()]);
})->note('Las dos, no una. El jarabe y la tableta no son la misma cosa, y cuál se le dio al paciente lo sabe quien lo dio — no el escáner.');

it('🔴 la gaveta no ofrece lo que ya se retiro del catalogo', function (): void {
    $principio = PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'DIPIRONA',
        'vigencia_desde' => now(),
    ]);

    $vigente = Producto::factory()->create([
        'codigo' => 'MED-0804',
        'nombre' => 'DIPIRONA 500 MG TABLETA',
    ]);

    $retirado = Producto::factory()->create([
        'codigo'         => 'MED-0805',
        'nombre'         => 'DIPIRONA JARABE',
        'vigencia_hasta' => now()->subDay(),
    ]);

    $principio->items()->attach([$vigente->getKey(), $retirado->getKey()]);

    $ofrecidos = $principio->productosVigentes()->pluck('id')->all();

    expect($ofrecidos)->toContain($vigente->getKey())
        ->and($ofrecidos)->not->toContain($retirado->getKey());
})->note('🔴 Un producto retirado sigue explicando las facturas viejas y sigue apareciendo para el conteo físico, pero no puede volver a cobrarse. Esta lista es para cobrar.');

it('una gaveta sin nada vinculado devuelve la lista vacia y no falla', function (): void {
    $principio = PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'MOLÉCULA SIN PRODUCTOS',
        'vigencia_desde' => now(),
    ]);

    expect($principio->productosVigentes())->toBeEmpty();
})->note('Pasa de verdad: la gaveta se etiqueta antes de cargar el producto. La pantalla lo dice con nombre y apellido en vez de quedarse en blanco.');
