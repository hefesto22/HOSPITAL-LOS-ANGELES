<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes;

use App\Filament\Resources\Almacenes\Pages\CreateAlmacen;
use App\Filament\Resources\Almacenes\Pages\EditAlmacen;
use App\Filament\Resources\Almacenes\Pages\ListAlmacenes;
use App\Filament\Resources\Almacenes\Schemas\AlmacenForm;
use App\Filament\Resources\Almacenes\Tables\AlmacenesTable;
use App\Models\Almacen;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Almacenes — dónde vive físicamente el producto (§8.1).
 *
 * Cada almacén lleva su propio kardex y su propio costo promedio: dos
 * bodegas que compran al mismo proveedor a precios distintos no comparten
 * costo.
 */
class AlmacenResource extends Resource
{
    protected static ?string $model = Almacen::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    /*
     * Explícito: sin esto Filament derivaría "almacens" del nombre de la
     * clase. El plural en español no se resuelve con las reglas de inglés.
     */
    protected static ?string $slug = 'almacenes';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return 'Almacenes';
    }

    public static function getBreadcrumb(): string
    {
        return 'Almacenes';
    }

    public static function getModelLabel(): string
    {
        return 'almacén';
    }

    public static function getPluralModelLabel(): string
    {
        return 'almacenes';
    }

    /**
     * Vive en Inventario y no en Configuración desde que el hospital
     * separó farmacia de bodega: crear «CARRITO ROJO 1» dejó de ser algo
     * que se hace una vez al instalar y pasó a ser algo que hace bodega
     * cuando compran un carro. Enterrarlo en Configuración es cómo el
     * carrito termina cargándose a la bodega porque nadie encontró dónde
     * crearlo.
     */
    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * @return Builder<Almacen>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Almacen> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with(['sede:id,codigo,nombre', 'servicio:id,nombre']);
    }

    public static function form(Schema $schema): Schema
    {
        return AlmacenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlmacenesTable::configure($table);
    }

    /**
     * Un almacén con kardex adentro NUNCA se borra. El kardex es
     * append-only (ADR-0004) y borrar su almacén dejaría movimientos
     * huérfanos — que en un almacén de controlados es exactamente lo que
     * ARSA audita.
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
            'index'  => ListAlmacenes::route('/'),
            'create' => CreateAlmacen::route('/create'),
            'edit'   => EditAlmacen::route('/{record}/edit'),
        ];
    }
}
