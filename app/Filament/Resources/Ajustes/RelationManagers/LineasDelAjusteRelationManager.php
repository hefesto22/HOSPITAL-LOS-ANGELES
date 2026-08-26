<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\RelationManagers;

use App\Domain\Enums\MotivoDeAjuste;
use App\Models\AjusteLinea;
use App\Models\MargenObjetivo;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Qué productos movió el ajuste, y contra qué línea del kardex.
 *
 * La columna del movimiento es lo que cierra el rastro: cualquier número
 * raro del kardex se puede seguir hasta acá, y desde acá hasta la línea
 * del conteo y la persona que la contó.
 */
class LineasDelAjusteRelationManager extends RelationManager
{
    protected static string $relationship = 'lineas';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedQueueList;

    protected static ?string $title = 'Productos ajustados';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['item', 'lote']))
            ->columns([
                TextColumn::make('item.nombre')
                    ->label('Producto')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('lote.numero')
                    ->label('Lote')
                    ->placeholder('sin lote'),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn (MotivoDeAjuste $state): string => $state->etiqueta())
                    ->color(fn (MotivoDeAjuste $state): string => $state->color()),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn (AjusteLinea $record): string => $record->esEntrada() ? 'success' : 'danger')
                    ->description(fn (AjusteLinea $record): string => $record->esEntrada()
                        ? 'sumó existencia'
                        : 'restó existencia'),

                TextColumn::make('costo_unitario')
                    ->label('Costo unitario')
                    ->money('HNL')
                    ->alignEnd()
                    ->visible(fn (): bool => self::puedeVerCostos()),

                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('HNL')
                    ->alignEnd()
                    ->visible(fn (): bool => self::puedeVerCostos()),

                TextColumn::make('movimiento_id')
                    ->label('Kardex')
                    ->prefix('#')
                    ->placeholder('—')
                    ->toggleable()
                    ->tooltip('La línea del kardex que este ajuste generó.'),

                TextColumn::make('texto')
                    ->label('Detalle')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->paginated([25, 50])
            ->emptyStateHeading('Sin líneas')
            ->emptyStateDescription('Un ajuste sin líneas no debería existir: la base lo impide.');
    }

    private static function puedeVerCostos(): bool
    {
        return Gate::allows('viewAny', MargenObjetivo::class);
    }
}
