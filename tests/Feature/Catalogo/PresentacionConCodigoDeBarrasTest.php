<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Producto;
use Illuminate\Database\QueryException;

/**
 * EL CÓDIGO DE BARRAS ES DE LA PRESENTACIÓN.
 *
 * «ACETAMINOFEN TABLETA 800 MG» es el nombre de un medicamento: no es
 * nada que se pueda agarrar con la mano ni pegarle una etiqueta. Lo que
 * existe en el estante es la caja de 100 y el blíster de 12, y son ellos
 * los que se escanean.
 *
 * Lo que cuida este archivo es que ese código no se repita nunca —ni
 * siquiera reciclando el de una presentación dada de baja— porque un
 * código repetido no da error: hace que el lector devuelva cualquiera de
 * las dos, distinta cada vez, y en farmacia eso es dispensar otra cosa.
 */
it('propone el codigo del producto con un sufijo', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0708']);

    expect(ItemPresentacion::codigoSugeridoPara($producto))->toBe('MED-0708-01');
})->note('Se lee con los ojos, sin escáner: quien tiene la caja en la mano sabe de qué producto es. El día que el sistema no esté, eso es lo único que hay.');

it('salta al siguiente sufijo cuando el anterior ya esta tomado', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0709']);

    ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => 'MED-0709-01',
    ]);

    expect(ItemPresentacion::codigoSugeridoPara($producto))->toBe('MED-0709-02');
})->note('La caja de 100 y el blíster de 12 son dos etiquetas distintas del mismo producto.');

it('🔴 no recicla el codigo de una presentacion dada de baja', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0710']);

    $vieja = ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => 'MED-0710-01',
    ]);

    $vieja->delete();

    expect(ItemPresentacion::codigoSugeridoPara($producto))->toBe('MED-0710-02');
})->note('🔴 La presentación se da de baja del sistema, pero la etiqueta impresa sigue pegada en una caja del estante. Reasignar su código haría que esa caja escanee como otra cosa. Un código impreso no se recicla nunca.');

it('la base no deja dos presentaciones con el mismo codigo', function (): void {
    $uno = Producto::factory()->create(['codigo' => 'MED-0711']);
    $otro = Producto::factory()->create(['codigo' => 'MED-0712']);

    ItemPresentacion::factory()->create([
        'item_id'       => $uno->getKey(),
        'codigo_barras' => '7501234567891',
    ]);

    expect(fn () => ItemPresentacion::factory()->create([
        'item_id'       => $otro->getKey(),
        'codigo_barras' => '7501234567891',
    ]))->toThrow(QueryException::class);
})->note('El formulario lo explica con nombre y apellido, pero la pantalla no es la única puerta: un import del catálogo viejo escribe directo.');

it('un codigo en blanco se guarda como nulo', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0713']);

    $sinCodigo = ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => '   ',
    ]);

    $otraSinCodigo = ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => '',
    ]);

    expect($sinCodigo->fresh()?->codigo_barras)->toBeNull()
        ->and($otraSinCodigo->fresh()?->codigo_barras)->toBeNull();
})->note('Con cadena vacía el índice único dejaría pasar una sola: la segunda presentación sin código explotaría con un error de SQL, y además `where(codigo_barras, "")` encontraría algo cuando el lector manda ruido.');

it('escanear el codigo generado encuentra el producto', function (): void {
    $producto = Producto::factory()->create(['codigo' => 'MED-0714']);

    $codigo = ItemPresentacion::codigoSugeridoPara($producto);

    ItemPresentacion::factory()->create([
        'item_id'       => $producto->getKey(),
        'codigo_barras' => $codigo,
    ]);

    expect(Item::query()->buscar((string) $codigo)->pluck('items.id'))
        ->toContain($producto->getKey());
})->note('Es todo el punto del código generado: que la pistola lo encuentre.');

it('arma la frase del envase con el envase adelante', function (): void {
    expect(ItemPresentacion::comoSeEnvasa('CAJA', '100', 'TABLETA'))->toBe('CAJA X 100 TABLETA')
        ->and(ItemPresentacion::comoSeEnvasa('FRASCO', '120', 'ML'))->toBe('FRASCO X 120 ML')
        ->and(ItemPresentacion::comoSeEnvasa('BLISTER', '12', 'TABLETA'))->toBe('BLISTER X 12 TABLETA');
})->note('🔴 Lleva el envase y no solo la cantidad: «100 TABLETA» no distingue la caja de 100 de la bolsa de 100, y son dos filas que se compran distinto. Dos presentaciones con el mismo nombre en el desplegable de la compra es donde alguien elige la que no era.');

it('no repite la unidad cuando el envase es la unidad y trae una sola', function (): void {
    expect(ItemPresentacion::comoSeEnvasa('AMPOLLA', '1', 'AMPOLLA'))->toBe('AMPOLLA')
        ->and(ItemPresentacion::comoSeEnvasa('AMPOLLA', '10', 'AMPOLLA'))->toBe('AMPOLLA X 10 AMPOLLA');
})->note('«AMPOLLA X 1 AMPOLLA» se lee como un error de programación, y quien carga el catálogo lo corrige a mano — que es justo lo que esto venía a evitar.');

it('el numero del envase sale sin los ceros de la columna', function (): void {
    expect(ItemPresentacion::sinCerosDeMas('120.0000'))->toBe('120')
        ->and(ItemPresentacion::sinCerosDeMas('0.5000'))->toBe('0.5')
        ->and(ItemPresentacion::sinCerosDeMas('100'))->toBe('100');
})->note('🔴 El último caso es el que importa: `rtrim("100", "0")` devuelve «1», y ese error no da ninguna señal — imprime una etiqueta que dice que la caja trae una tableta cuando trae cien. La columna siempre trae decimales, pero esto también se llama con lo que hay tecleado en el formulario.');

it('el nombre de la presentacion entra completo aunque arranque con el del producto', function (): void {
    $producto = Producto::factory()->create([
        'codigo' => 'MED-0715',
        'nombre' => 'CLORHIDRATO DE AMBROXOL CON SALBUTAMOL JARABE 15 MG / 2 MG POR 5 ML',
    ]);

    $nombre = $producto->nombre.' CAJA X 12 FRASCOS DE 120 ML';

    $presentacion = ItemPresentacion::factory()->create([
        'item_id' => $producto->getKey(),
        'nombre'  => $nombre,
    ]);

    expect($presentacion->fresh()?->nombre)->toBe(mb_strtoupper($nombre));
})->note('El formulario propone el nombre del producto ya escrito. Si la columna fuera más angosta que el nombre del ítem, el campo abriría con algo que él mismo rechaza.');
