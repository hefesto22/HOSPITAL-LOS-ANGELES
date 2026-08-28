<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores;

use App\Filament\Resources\Proveedores\Pages\CreateProveedor;
use App\Filament\Resources\Proveedores\Pages\EditProveedor;
use App\Filament\Resources\Proveedores\Pages\ListProveedores;
use App\Filament\Resources\Proveedores\Schemas\ProveedorForm;
use App\Filament\Resources\Proveedores\Tables\ProveedoresTable;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Proveedores — a quién se le compra.
 *
 * Entidad aparte del MPI a propósito: `personas` es el índice de
 * PACIENTES, y meter droguerías ahí rompería el detector de duplicados y
 * la búsqueda de admisión.
 */
class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $slug = 'proveedores';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Proveedores';
    }

    public static function getModelLabel(): string
    {
        return 'proveedor';
    }

    public static function getPluralModelLabel(): string
    {
        return 'proveedores';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'rtn'];
    }

    public static function form(Schema $schema): Schema
    {
        return ProveedorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProveedoresTable::configure($table);
    }

    /**
     * Un proveedor no se borra: se desactiva. Borrarlo dejaría entradas
     * de compra apuntando a un origen que ya no existe.
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
            'index'  => ListProveedores::route('/'),
            'create' => CreateProveedor::route('/create'),
            'edit'   => EditProveedor::route('/{record}/edit'),
        ];
    }
}
