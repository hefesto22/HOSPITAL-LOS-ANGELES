<?php

declare(strict_types=1);

namespace App\Filament\Resources\Unidades;

use App\Filament\Resources\Unidades\Pages\ListUnidades;
use App\Filament\Resources\Unidades\Schemas\UnidadForm;
use App\Filament\Resources\Unidades\Tables\UnidadesTable;
use App\Models\Unidad;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Unidades de medida.
 *
 * Es tabla y no enum porque el hospital va a inventar unidades que hoy no
 * existen —"kit de curación", "bolsa colectora"— y un enum obligaría a
 * desplegar para agregar una (§1.1).
 *
 * Vive en una sola página con modales: son quince filas de tres campos.
 * Una pantalla de alta completa para eso es ceremonia.
 */
class UnidadResource extends Resource
{
    protected static ?string $model = Unidad::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'unidades';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Unidades de medida';
    }

    public static function getBreadcrumb(): string
    {
        return 'Unidades';
    }

    public static function getModelLabel(): string
    {
        return 'unidad';
    }

    public static function getPluralModelLabel(): string
    {
        return 'unidades';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public static function form(Schema $schema): Schema
    {
        return UnidadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnidadesTable::configure($table);
    }

    /**
     * Borrar una unidad en uso deja ítems sin unidad de kardex. La FK lo
     * impide con `restrictOnDelete`, pero es mejor no ofrecer el botón
     * que explicar el error después.
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
            'index' => ListUnidades::route('/'),
        ];
    }
}
