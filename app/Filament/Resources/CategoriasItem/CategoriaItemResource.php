<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem;

use App\Filament\Resources\CategoriasItem\Pages\CreateCategoriaItem;
use App\Filament\Resources\CategoriasItem\Pages\EditCategoriaItem;
use App\Filament\Resources\CategoriasItem\Pages\ListCategoriasItem;
use App\Filament\Resources\CategoriasItem\Schemas\CategoriaItemForm;
use App\Filament\Resources\CategoriasItem\Tables\CategoriasItemTable;
use App\Models\CategoriaItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Categorías del catálogo — las hojas del tarifario.
 *
 * Existe como pantalla y no como enum porque cada hospital agrupa
 * distinto y cada aseguradora manda su tarifario partido a su manera
 * (§1.1: adaptar es configuración, no programación). Agregar «Terapia
 * respiratoria» no puede necesitar un deploy.
 */
class CategoriaItemResource extends Resource
{
    protected static ?string $model = CategoriaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'categorias-del-catalogo';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Categorías';
    }

    public static function getBreadcrumb(): string
    {
        return 'Categorías';
    }

    public static function getModelLabel(): string
    {
        return 'categoría';
    }

    public static function getPluralModelLabel(): string
    {
        return 'categorías del catálogo';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    /**
     * @return Builder<CategoriaItem>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<CategoriaItem> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->withCount('items');
    }

    public static function form(Schema $schema): Schema
    {
        return CategoriaItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriasItemTable::configure($table);
    }

    /**
     * Una categoría no se borra: se le cierra la vigencia.
     *
     * Además la FK `items_categoria_fk` es RESTRICT, así que la base
     * tampoco dejaría borrar una con ítems. Pero incluso una vacía se
     * retira en vez de borrarse: pudo tener ítems ayer, y esos ítems
     * están en facturas que hay que poder explicar.
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
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListCategoriasItem::route('/'),
            'create' => CreateCategoriaItem::route('/nueva'),
            'edit'   => EditCategoriaItem::route('/{record}/editar'),
        ];
    }
}
