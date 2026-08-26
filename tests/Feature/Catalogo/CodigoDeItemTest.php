<?php

declare(strict_types=1);

use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\Producto;
use App\Services\AsignadorDeCodigoDeItem;

/**
 * EL CÓDIGO DEL CATÁLOGO SE PROPONE SOLO.
 *
 * El prefijo sale de la categoría —que es la hoja del tarifario— y el
 * número continúa el que ya está cargado. Tres cosas que parecen detalle
 * y no lo son: no reinicia, no reutiliza, y no cambia de ancho.
 *
 * El servicio se pide del contenedor en cada prueba y no desde un
 * `beforeEach` con `$this->...`: una propiedad dinámica sobre el TestCase
 * es justo lo que el analizador no puede verificar.
 */
function asignadorDeCodigo(): AsignadorDeCodigoDeItem
{
    return app(AsignadorDeCodigoDeItem::class);
}

it('continua la numeracion que ya existe', function (): void {
    $categoria = CategoriaItem::factory()->deProductos()->create(['codigo' => 'MED']);

    Producto::factory()->create(['codigo' => 'MED-0026']);
    Producto::factory()->create(['codigo' => 'MED-0012']);

    expect(asignadorDeCodigo()->siguiente($categoria))->toBe('MED-0027');
})->note('Arrancar de cero en un catálogo ya cargado produce colisiones desde el primer alta.');

it('respeta el ancho con el que se escribio la familia', function (): void {
    $categoria = CategoriaItem::factory()->create(['codigo' => 'HOS']);

    Item::factory()->create(['codigo' => 'HOS-023']);

    expect(asignadorDeCodigo()->siguiente($categoria))->toBe('HOS-024');
})->note('Mezclar anchos rompe el orden alfabético del listado: HOS-0024 se ordena antes que HOS-003, y así es como una hoja del tarifario impreso deja de leerse.');

it('arranca en uno cuando la categoria no tiene nada', function (): void {
    $categoria = CategoriaItem::factory()->create(['codigo' => 'TER']);

    expect(asignadorDeCodigo()->siguiente($categoria))->toBe('TER-0001');
});

it('🔴 no reutiliza el codigo de algo retirado', function (): void {
    $categoria = CategoriaItem::factory()->create(['codigo' => 'LAB']);

    Item::factory()->create(['codigo' => 'LAB-048'])->delete();

    expect(asignadorDeCodigo()->siguiente($categoria))->toBe('LAB-049');
})->note('🔴 El código retirado sigue impreso en facturas viejas. Dos productos distintos con el mismo código a diez años de distancia es lo que hace que una auditoría no cierre.');

it('ignora los codigos que no siguen el patron', function (): void {
    $categoria = CategoriaItem::factory()->deProductos()->create(['codigo' => 'MED']);

    Producto::factory()->create(['codigo' => 'MED-PARAC500']);
    Producto::factory()->create(['codigo' => 'MED-0003']);

    expect(asignadorDeCodigo()->siguiente($categoria))->toBe('MED-0004');
})->note('Un código del proveedor cargado a mano no puede envenenar el contador de toda la familia.');

it('distingue lo autogenerado de lo tecleado a mano', function (): void {
    $asignador = asignadorDeCodigo();

    expect($asignador->pareceAutogenerado('MED-0027'))->toBeTrue()
        ->and($asignador->pareceAutogenerado('HOS-023'))->toBeTrue()
        ->and($asignador->pareceAutogenerado('PARAC500'))->toBeFalse()
        ->and($asignador->pareceAutogenerado('MED-PARAC500'))->toBeFalse();
})->note('De esto depende que cambiar de categoría a mitad de la carga no le pise el código a quien lo escribió a propósito.');
