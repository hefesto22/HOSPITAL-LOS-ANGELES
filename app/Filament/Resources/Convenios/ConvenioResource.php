<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios;

use App\Filament\Resources\Convenios\Pages\CreateConvenio;
use App\Filament\Resources\Convenios\Pages\EditConvenio;
use App\Filament\Resources\Convenios\Pages\ListConvenios;
use App\Filament\Resources\Convenios\Schemas\ConvenioForm;
use App\Filament\Resources\Convenios\Tables\ConveniosTable;
use App\Models\Convenio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Convenios — quién paga cada cuenta.
 *
 * Va en pantalla completa y no en modal, al revés que unidades: dar de
 * alta un convenio incluye declarar sobre qué monto se le aplica el
 * descuento del adulto mayor y escribir con qué criterio se decidió. Eso
 * no cabe —ni debe caber— en un modal de paso.
 */
class ConvenioResource extends Resource
{
    protected static ?string $model = Convenio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'convenios';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Convenios';
    }

    public static function getModelLabel(): string
    {
        return 'convenio';
    }

    public static function getPluralModelLabel(): string
    {
        return 'convenios';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }

    public static function form(Schema $schema): Schema
    {
        return ConvenioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConveniosTable::configure($table);
    }

    /**
     * Un convenio no se borra: se le pone fecha de fin de vigencia.
     *
     * Borrarlo dejaría cuentas y facturas apuntando a un pagador que ya
     * no existe, y una factura que no se puede reimprimir es un problema
     * ante el SAR, no una fila de menos.
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
            'index'  => ListConvenios::route('/'),
            'create' => CreateConvenio::route('/create'),
            'edit'   => EditConvenio::route('/{record}/edit'),
        ];
    }
}
