<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
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
 * Catálogo de lo que el hospital OFRECE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIGUE SIENDO UN SOLO CATÁLOGO (ADR-0003, §8.4)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Debajo hay una sola tabla `items`. Lo que se partió en dos es la
 * PANTALLA: acá vive lo que se cobra sin descontar existencia —consulta
 * externa, hospitalización, equipo médico, rayos X, laboratorio— y en
 * `ProductoResource`, dentro de Farmacia, lo que se guarda en el
 * estante.
 *
 * La factura de un ingreso mezcla habitación, laboratorio y medicamento
 * en el mismo documento, y esas líneas salen todas de `items`. Partir la
 * tabla obligaría a que cada cargo supiera a cuál de las dos apunta.
 *
 * ⚠️ El filtro va en `getEloquentQuery()` y no en un `where` de la
 * tabla: así también lo respetan la búsqueda global y la ruta de edición
 * directa por URL, que es el agujero típico de Filament (§9.L5).
 */
class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $slug = 'catalogo';

    protected static ?int $navigationSort = 6;

    /**
     * Buscar por nombre en la barra global de Filament. El código y el
     * principio activo entran por el buscador de la tabla, que usa el
     * índice de trigramas.
     */
    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Catálogo de servicios';
    }

    public static function getBreadcrumb(): string
    {
        return 'Catálogo';
    }

    public static function getModelLabel(): string
    {
        return 'servicio';
    }

    public static function getPluralModelLabel(): string
    {
        return 'servicios del catálogo';
    }

    /**
     * Junto a los productos de farmacia y no en «Catálogos y precios»
     * (Mauricio, 13-oct-2026): es donde se registra lo importante —lo que
     * se cobra—, y ahí está también el precio de cada seguro. «Catálogos y
     * precios» queda para lo que se configura una vez: categorías,
     * unidades, márgenes, convenios.
     */
    public static function getNavigationGroup(): ?string
    {
        return 'Farmacia y servicios';
    }

    /**
     * @return Builder<Item>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Item> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta
            ->where('items.se_almacena', false)
            ->with([
                'categoria:id,codigo,nombre,ambito,orden',
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
     * ─────────────────────────────────────────────────────────────────
     * SIN PESTAÑAS DEBAJO DE LA FICHA (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «ese toggle de precios no debería estar; en automático
     * deben listarse los seguros ahí». Los precios de cada pagador se
     * escriben en la ficha misma (`PreciosDeLaFicha`), así que la lista
     * de precios de abajo sobraba. Lo que contestaba —«¿por qué esta
     * factura de marzo dice L 29.33?»— sigue a mano en «Historial de
     * precios», arriba a la derecha.
     *
     * Las variantes se ven solo en el servicio que ya tiene de antes
     * (`PresentacionesRelationManager::canViewForRecord()`): un servicio
     * no tiene variantes.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            PresentacionesRelationManager::class,
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
