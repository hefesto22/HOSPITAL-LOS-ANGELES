<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
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

    protected static ?int $navigationSort = 10;

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
     * Presentaciones y precios. Existencias no: acá no hay stock.
     *
     * 🔴 Las presentaciones SÍ van de este lado, aunque nada se
     * almacene. Una presentación no es solo un envase: es una VARIANTE.
     * «HONORARIO DE CONSULTA» → «Dr. Carlos», «Dr. Miguel». Sin eso, el
     * catálogo termina con cuarenta filas de honorarios que solo se
     * distinguen por el apellido al final del nombre, que es donde
     * alguien elige el equivocado con el paciente enfrente.
     *
     * Los precios son el otro panel obligatorio: es el único sitio donde
     * se le pone precio a lo que no se compra —un honorario, una
     * estancia, un hemograma—, la Ruta B del §4.1, donde no hay costo
     * del cual derivar.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            PresentacionesRelationManager::class,
            PreciosRelationManager::class,
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
