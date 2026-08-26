<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrincipiosActivos;

use App\Filament\Resources\PrincipiosActivos\Pages\ListPrincipiosActivos;
use App\Filament\Resources\PrincipiosActivos\Schemas\PrincipioActivoForm;
use App\Filament\Resources\PrincipiosActivos\Tables\PrincipiosActivosTable;
use App\Models\PrincipioActivo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Farmacia · Principios activos — lo que de verdad cura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EXISTE PARA QUE LA GAVETA TENGA ETIQUETA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se imprime `PA-0001`, se pega en la gaveta del acetaminofén, y al
 * escanearlo salen los cuatro productos que lo llevan —tableta, jarabe,
 * supositorio, inyectable— sin que nadie recuerde cómo se escribe.
 *
 * Vive en una sola página con modales: son cuatro campos. Una pantalla
 * de alta completa para eso es ceremonia.
 */
class PrincipioActivoResource extends Resource
{
    protected static ?string $model = PrincipioActivo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'farmacia/principios-activos';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Principios activos';
    }

    public static function getBreadcrumb(): string
    {
        return 'Principios activos';
    }

    public static function getModelLabel(): string
    {
        return 'principio activo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'principios activos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Farmacia';
    }

    public static function form(Schema $schema): Schema
    {
        return PrincipioActivoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrincipiosActivosTable::configure($table);
    }

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
            'index' => ListPrincipiosActivos::route('/'),
        ];
    }
}
