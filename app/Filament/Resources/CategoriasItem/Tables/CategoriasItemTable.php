<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem\Tables;

use App\Domain\Enums\AmbitoCatalogo;
use App\Models\CategoriaItem;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CategoriasItemTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('orden')
                    ->label('#')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->description(fn (CategoriaItem $record): ?string => $record->descripcion),

                TextColumn::make('ambito')
                    ->label('Lado del catálogo')
                    ->badge()
                    ->color(fn (AmbitoCatalogo $state): string => $state->color())
                    ->formatStateUsing(fn (AmbitoCatalogo $state): string => $state->etiqueta()),

                /*
                 * Un conteo por fila sería un N+1 de manual. `withCount`
                 * lo resuelve en la misma consulta del listado — ver
                 * `CategoriaItemResource::getEloquentQuery()` (§13.2).
                 */
                TextColumn::make('items_count')
                    ->label('Ítems')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('orden')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('ambito')
                    ->label('Lado del catálogo')
                    ->options(fn (): array => collect(AmbitoCatalogo::cases())
                        ->mapWithKeys(fn (AmbitoCatalogo $a): array => [$a->value => $a->etiqueta()])
                        ->all()),
            ])
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading('No hay categorías')
            ->emptyStateDescription(
                'Son las hojas del tarifario: hospitalización, equipo médico, rayos X, laboratorio, '
                .'consulta externa. Del lado de farmacia, cómo se agrupa lo que está en el estante.'
            )
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
