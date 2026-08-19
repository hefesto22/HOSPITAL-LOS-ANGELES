<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\RelationManagers\ExistenciasRelationManager;
use App\Filament\Resources\Items\RelationManagers\PreciosRelationManager;
use App\Filament\Resources\Items\RelationManagers\PresentacionesRelationManager;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\Item;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catálogo único de ítems facturables (§8.4).
 *
 * Farmacia, laboratorio, imágenes, quirófano y hospitalización cobran
 * contra esta pantalla. Es la única.
 */
class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $slug = 'catalogo';

    protected static ?int $navigationSort = 10;

    /**
     * Buscar por nombre en la barra global de Filament. El código y el
     * principio activo entran por el buscador de la tabla, que usa el
     * índice de trigramas.
     */
    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Catálogo';
    }

    public static function getBreadcrumb(): string
    {
        return 'Catálogo';
    }

    public static function getModelLabel(): string
    {
        return 'ítem';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ítems';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    /**
     * @return Builder<Item>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Item> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'unidadDispensacion:id,codigo,nombre,simbolo',
            'unidadFraccion:id,codigo,nombre,simbolo',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return ItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::configure($table);
    }

    /**
     * Un ítem NO se borra: se le pone fecha de fin de vigencia.
     *
     * §8.4: "los catálogos tienen vigencia, no un booleano `activo`". Un
     * servicio que dejó de ofrecerse en 2027 sigue teniendo que explicar
     * la factura de 2026 donde aparece. Borrarlo deja cargos apuntando a
     * un ítem que no existe, y esa factura ya no se puede reimprimir.
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
     * Las presentaciones de compra se cargan DENTRO de la ficha del ítem.
     *
     * Una presentación sin su ítem no significa nada, y una pantalla
     * aparte obligaría a elegir el ítem de un selector — que es como se
     * termina cargando "CAJA X 100" en el producto equivocado.
     *
     * El panel se oculta solo en los ítems que no son físicos: una
     * consulta médica no viene en caja. Ver
     * `PresentacionesRelationManager::canViewForRecord()`.
     *
     * Los precios van en el mismo lugar y por la misma razón. Además es
     * el único sitio donde se le puede poner precio a lo que no se
     * compra —un honorario, una estancia, un hemograma—, que es la Ruta B
     * del §4.1: ahí no hay costo del cual derivar, se fija a mano.
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
            'index'  => ListItems::route('/'),
            'create' => CreateItem::route('/nuevo'),
            'edit'   => EditItem::route('/{record}/editar'),
        ];
    }
}
