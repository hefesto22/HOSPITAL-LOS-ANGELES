<?php

declare(strict_types=1);

namespace App\Filament\Resources\MargenesObjetivo;

use App\Filament\Resources\MargenesObjetivo\Pages\ListMargenesObjetivo;
use App\Filament\Resources\MargenesObjetivo\Tables\MargenesObjetivoTable;
use App\Models\MargenObjetivo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El margen que el hospital quiere ganar sobre el costo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTA PANTALLA NO EDITA: FIJA
 * ─────────────────────────────────────────────────────────────────────
 *
 * No hay botón de editar ni de borrar, y es a propósito. El margen es un
 * historial, no un valor: cambiarlo es cerrar el vigente y abrir uno
 * nuevo con fecha. Un `UPDATE` sobre la fila vigente borraría la única
 * respuesta que importa cuando alguien pregunte, en 2028, por qué ese
 * producto se vendía a ese precio en marzo.
 *
 * Por eso la única acción es «Fijar un margen nuevo», que hace las dos
 * escrituras en una transacción y deja los rangos pegados y sin
 * traslape — que es lo que la restricción de exclusión de la tabla exige.
 */
class MargenObjetivoResource extends Resource
{
    protected static ?string $model = MargenObjetivo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?string $slug = 'margenes-objetivo';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'motivo';

    public static function getNavigationLabel(): string
    {
        return 'Márgenes objetivo';
    }

    public static function getBreadcrumb(): string
    {
        return 'Márgenes';
    }

    public static function getModelLabel(): string
    {
        return 'margen objetivo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'márgenes objetivo';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public static function table(Table $table): Table
    {
        return MargenesObjetivoTable::configure($table);
    }

    /**
     * Ver el encabezado: acá no se edita, se fija uno nuevo.
     */
    public static function canEdit(mixed $record): bool
    {
        return false;
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
            'index' => ListMargenesObjetivo::route('/'),
        ];
    }
}
