<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos;

use App\Filament\Resources\Items\RelationManagers\ExistenciasRelationManager;
use App\Filament\Resources\Items\RelationManagers\PreciosRelationManager;
use App\Filament\Resources\Items\RelationManagers\PresentacionesRelationManager;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Filament\Resources\Productos\Pages\CreateProducto;
use App\Filament\Resources\Productos\Pages\EditProducto;
use App\Filament\Resources\Productos\Pages\ListProductos;
use App\Models\Producto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Farmacia · Productos — lo que se guarda en el estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES LA MISMA TABLA QUE EL CATÁLOGO, CON OTRA PUERTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `Producto` hereda de `Item` y comparte su tabla (ADR-0003). Lo que
 * cambia es quién entra y qué se le pregunta: acá se cargan
 * medicamentos, material de curación, jeringas y tubos, con lote,
 * vencimiento, registro ARSA y existencia por almacén; en
 * `ItemResource` se carga lo que se ofrece y se cobra sin stock.
 *
 * Los permisos son propios (`ViewAny:Producto`, `Create:Producto`…)
 * porque Shield los nombra por modelo. Es lo que permite que farmacia
 * pueda dar de alta una ampolla sin poder tocar el precio de una
 * cesárea — con un solo modelo, quien puede una cosa puede la otra.
 *
 * El formulario y la tabla son los del catálogo, configurados para el
 * ámbito de productos: las reglas del ítem son una sola cosa y no se
 * duplican por pantalla (ver `ItemForm::para()`).
 */
class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $slug = 'farmacia/productos';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Productos';
    }

    public static function getBreadcrumb(): string
    {
        return 'Productos';
    }

    public static function getModelLabel(): string
    {
        return 'producto';
    }

    public static function getPluralModelLabel(): string
    {
        return 'productos de farmacia';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Farmacia';
    }

    /**
     * El recorte a lo almacenable ya lo pone el global scope de
     * `Producto`, así que también lo respetan la búsqueda global y la
     * ruta de edición directa. Acá solo va el eager loading.
     *
     * @return Builder<Producto>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Producto> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'categoria:id,codigo,nombre,ambito,orden',
            'unidadDispensacion:id,codigo,nombre,simbolo',
            'unidadFraccion:id,codigo,nombre,simbolo',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return ItemForm::paraProductos($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::paraProductos($table);
    }

    /**
     * Un producto no se borra: se le pone fecha de fin de vigencia. Su
     * kardex histórico tiene que seguir siendo consultable, y sus cargos
     * tienen que poder reimprimirse (§8.4).
     */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Los tres paneles que solo tienen sentido de este lado: en qué
     * presentación se compra, a qué precio se vende, y cuánto hay hoy en
     * cada almacén.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            PresentacionesRelationManager::class,
            PreciosRelationManager::class,
            ExistenciasRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListProductos::route('/'),
            'create' => CreateProducto::route('/nuevo'),
            'edit'   => EditProducto::route('/{record}/editar'),
        ];
    }
}
