<?php

declare(strict_types=1);

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\CategoriasDelCatalogoSeeder;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * EL CATÁLOGO PARTIDO EN DOS PANTALLAS, SOBRE UNA SOLA TABLA.
 *
 * Lo que este archivo cuida es que la separación sea de verdad y no un
 * filtro que se puede saltear. Tres formas de saltearlo, las tres
 * probadas acá:
 *
 *   · por consulta —el listado de servicios devolviendo medicamentos—;
 *   · por URL —abrir por ID la ficha de un producto desde el catálogo,
 *     que es el agujero típico de Filament (§9.L5)—;
 *   · por dato —un producto de farmacia archivado bajo «Rayos X», que no
 *     rompe ninguna pantalla y solo se nota cuando el reporte de
 *     ingresos por área no cuadra y nadie sabe por qué.
 */

/*
|--------------------------------------------------------------------------
| Una tabla, dos puertas
|--------------------------------------------------------------------------
*/

it('el catalogo de servicios no muestra lo que se almacena', function (): void {
    $servicio = Item::factory()->create();
    $producto = Producto::factory()->create();

    $ids = ItemResource::getEloquentQuery()->pluck('items.id');

    expect($ids)->toContain($servicio->getKey())
        ->and($ids)->not->toContain($producto->getKey());
})->note('El filtro va en getEloquentQuery y no en un where de la tabla: así también lo respeta la ruta de edición directa por URL.');

it('farmacia no muestra lo que no se almacena', function (): void {
    $servicio = Item::factory()->create();
    $producto = Producto::factory()->create();

    $ids = ProductoResource::getEloquentQuery()->pluck('items.id');

    expect($ids)->toContain($producto->getKey())
        ->and($ids)->not->toContain($servicio->getKey());
});

it('Producto filtra por global scope pero Item sigue viendo todo', function (): void {
    Item::factory()->create();
    Producto::factory()->create();

    expect(Producto::query()->count())->toBe(1)
        ->and(Item::query()->count())->toBe(2);
})->note('🔴 El scope va en Producto y NUNCA en Item: cargos, kardex y tarifarios usan Item, y partirlo por debajo dejaría a una factura sin poder listar sus propias líneas.');

it('un producto nace almacenable aunque nadie lo diga', function (): void {
    $producto = Producto::factory()->create(['se_almacena' => false]);

    expect($producto->refresh()->se_almacena)->toBeTrue();
})->note('Lo fija la puerta por la que se entra, no un interruptor del formulario.');

/*
|--------------------------------------------------------------------------
| La categoría y el lado del catálogo dicen lo mismo, o la base no deja
|--------------------------------------------------------------------------
*/

it('el ambito de la categoria se deriva solo, no se escribe', function (): void {
    $deServicios = CategoriaItem::factory()->create();
    $item = Item::factory()->create(['categoria_id' => $deServicios->getKey()]);

    expect($item->refresh()->categoria_ambito)->toBe(AmbitoCatalogo::Servicios);

    $deProductos = CategoriaItem::factory()->deProductos()->create();
    $producto = Producto::factory()->create(['categoria_id' => $deProductos->getKey()]);

    expect($producto->refresh()->categoria_ambito)->toBe(AmbitoCatalogo::Productos);
})->note('Un campo que pida repetir algo que el sistema ya sabe se llena mal alguna vez. Se copia de se_almacena en el saving del modelo.');

it('la base rechaza un producto archivado en una categoria de servicios', function (): void {
    $deServicios = CategoriaItem::factory()->create();
    $producto = Producto::factory()->create();

    DB::table('items')
        ->where('id', $producto->getKey())
        ->update([
            'categoria_id'     => $deServicios->getKey(),
            'categoria_ambito' => AmbitoCatalogo::Servicios->value,
        ]);
})->throws(QueryException::class)
    ->note('Se prueba con DB::table crudo (§9.C4): pasando por el modelo, el saving corrige el ámbito antes de llegar al CHECK y el constraint nunca se ejercita.');

it('la base rechaza una categoria cuyo ambito no existe con ese id', function (): void {
    $deServicios = CategoriaItem::factory()->create();
    $item = Item::factory()->create();

    DB::table('items')
        ->where('id', $item->getKey())
        ->update([
            'categoria_id'     => $deServicios->getKey(),
            'categoria_ambito' => AmbitoCatalogo::Productos->value,
        ]);
})->throws(QueryException::class)
    ->note('Es la FK compuesta contra categorias_item (id, ambito). Sin ella, la columna redundante sería un adorno.');

it('la base rechaza dejar la categoria a medias', function (): void {
    $item = Item::factory()->create();

    DB::table('items')
        ->where('id', $item->getKey())
        ->update(['categoria_ambito' => AmbitoCatalogo::Servicios->value]);
})->throws(QueryException::class)
    ->note('Con un ámbito suelto, la FK compuesta deja de verificar (MATCH SIMPLE ignora la fila si algún lado es nulo) y el CHECK pasa a hablar de la nada.');

/*
|--------------------------------------------------------------------------
| La clasificación inicial
|--------------------------------------------------------------------------
*/

it('el seeder clasifica por prefijo de codigo y no deja huerfanos', function (): void {
    $consulta = Item::factory()->create(['codigo' => 'CON-001']);
    $laboratorio = Item::factory()->create(['codigo' => 'LAB-041']);
    $raro = Item::factory()->create(['codigo' => 'ZZZ-999']);
    $medicamento = Producto::factory()->create(['codigo' => 'MED-0012']);

    test()->seed(CategoriasDelCatalogoSeeder::class);

    expect($consulta->refresh()->categoria?->codigo)->toBe('CON')
        ->and($laboratorio->refresh()->categoria?->codigo)->toBe('LAB')
        ->and($medicamento->refresh()->categoria?->codigo)->toBe('MED')
        ->and($raro->refresh()->categoria?->codigo)->toBe('SRV')
        ->and(Item::query()->whereNull('categoria_id')->count())->toBe(0);
})->note('Los códigos ya traían escrita su hoja del tarifario; lo que no case cae en la genérica de su lado y queda a la vista, nunca sin categoría.');

it('el seeder se puede volver a correr sin pisar una reclasificacion a mano', function (): void {
    $item = Item::factory()->create(['codigo' => 'CON-001']);

    test()->seed(CategoriasDelCatalogoSeeder::class);

    $otra = CategoriaItem::query()->where('codigo', 'HOS')->firstOrFail();
    $item->update(['categoria_id' => $otra->getKey()]);

    test()->seed(CategoriasDelCatalogoSeeder::class);

    expect($item->refresh()->categoria?->codigo)->toBe('HOS');
})->note('Solo toca ítems SIN categoría. Un seeder que reclasifica cada vez que corre borra el trabajo de quien ordenó el catálogo a mano.');

/*
|--------------------------------------------------------------------------
| Permisos: farmacia carga su estante, no el tarifario del hospital
|--------------------------------------------------------------------------
*/

it('farmacia da de alta productos pero no toca el catalogo de servicios', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);

    /*
     * Se siembra TODO lo que la matriz declara, y no solo los tres
     * sujetos que interesan acá: el seeder reconcilia contra su propio
     * archivo y verifica que cada permiso exista de verdad.
     */
    foreach (MatrizDePermisosSeeder::permisosDeclarados() as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    test()->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole('farmacia');
    $usuario = $usuario->fresh() ?? $usuario;

    expect($usuario->can('create', Producto::class))->toBeTrue('farmacia debería cargar su estante')
        ->and($usuario->can('create', Item::class))->toBeFalse('farmacia no fija el precio de una cesárea')
        ->and($usuario->can('create', CategoriaItem::class))->toBeFalse('reordenar el tarifario es de dirección');
})->note('Es la mitad del sentido de que Producto sea un modelo propio: Shield nombra los permisos por modelo, así que Create:Producto se concede sin conceder Create:Item.');

/*
|--------------------------------------------------------------------------
| El ISV: exento por defecto, gravado por excepción
|--------------------------------------------------------------------------
*/

it('un item nace exento de ISV cuando nadie contesta', function (): void {
    $item = new Item;
    $item->codigo = 'ZZZ-777';
    $item->nombre = 'ALGO QUE ENTRO POR UN IMPORT';
    $item->tipo = TipoItem::Servicio;
    $item->vigencia_desde = now();
    $item->save();

    expect($item->refresh()->regimen_isv)->toBe(RegimenIsv::Exento);
})->note('Confirmado por el contador del hospital (20-ago-2026): casi todo lo que factura un hospital privado hondureño es exento por el Art. 15 inciso d. Lo gravado —estética, cafetería, parqueo— es la excepción y se marca a mano.');

it('lo gravado escrito a proposito no se pisa', function (): void {
    $item = Item::factory()->create(['regimen_isv' => RegimenIsv::Gravado15]);

    expect($item->refresh()->regimen_isv)->toBe(RegimenIsv::Gravado15);
})->note('El default solo actúa si NADIE contestó. Pisarlo le borraría el impuesto a lo único que sí lo paga.');

/*
|--------------------------------------------------------------------------
| 🔴 Heredar el modelo no hereda el nombre
|--------------------------------------------------------------------------
*/

it('un producto busca sus relaciones por item_id y no por producto_id', function (): void {
    $producto = Producto::factory()->create();

    expect($producto->getForeignKey())->toBe('item_id')
        ->and($producto->existencias()->getForeignKeyName())->toBe('item_id')
        ->and($producto->lotes()->getForeignKeyName())->toBe('item_id')
        ->and($producto->presentaciones()->getForeignKeyName())->toBe('item_id')
        ->and($producto->precios()->getForeignKeyName())->toBe('item_id')
        ->and($producto->descuentos()->getForeignPivotKeyName())->toBe('item_id');
})->note('🔴 Eloquent arma la llave foránea con el NOMBRE DE LA CLASE, no con el de la tabla: desde Producto buscaba producto_id, que no existe. Reventaba la pantalla de farmacia entera y ni PHPStan ni los tests del modelo lo veían, porque se resuelve en tiempo de ejecución.');

it('un producto puede contestar si tiene inventario escrito', function (): void {
    $producto = Producto::factory()->create();

    expect($producto->tieneInventarioEscrito())->toBeFalse();
})->note('Es la consulta exacta que reventaba al abrir Farmacia → Productos: la usa la acción de mover para negarse a sacar de farmacia algo con stock.');

it('un producto se audita como item', function (): void {
    expect(Producto::factory()->create()->getMorphClass())->toBe(Item::class);
})->note('Sin esto el historial del mismo registro queda partido en dos según por qué pantalla se lo editó.');

/*
|--------------------------------------------------------------------------
| 🔴 Un insumo vive de un solo lado (ADR-0006)
|--------------------------------------------------------------------------
*/

it('🔴 no se puede dar de alta un insumo desde el catalogo de servicios', function (): void {
    expect(AmbitoCatalogo::Servicios->tiposPermitidos())->not->toContain(TipoItem::Insumo)
        ->and(AmbitoCatalogo::Productos->tiposPermitidos())->toContain(TipoItem::Insumo);
})->note('🔴 Estuvo en los dos lados y el costo era invisible: dos filas «GASA», una que descuenta existencia y otra que no. Quien cobra elige la que le aparezca primero en el buscador y nadie se entera hasta el conteo físico, cuando falta gasa que el kardex jura que está.');
