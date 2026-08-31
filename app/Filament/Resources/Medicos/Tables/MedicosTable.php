<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Tables;

use App\Models\Especialidad;
use App\Models\Medico;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class MedicosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Médico')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('especialidad.nombre')
                    ->label('Especialidad')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                /*
                 * Buscable y plegada: nadie la lee de corrido, pero
                 * cuando la aseguradora pregunta por «el médico
                 * 0801-1990-09368» es el único campo por el que se lo
                 * puede encontrar.
                 */
                TextColumn::make('identidad')
                    ->label('Identidad')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('colegiacion')
                    ->label('Colegiación')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                /*
                 * Cuántos honorarios tiene con precio propio. Cero no es
                 * un problema: significa que cobra lo que dice el
                 * tarifario, que es lo normal.
                 */
                TextColumn::make('honorarios_count')
                    ->label('Precios propios')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'success')
                    ->alignCenter()
                    ->tooltip('Cero significa que cobra lo que dice el tarifario.'),

                IconColumn::make('vigente')
                    ->label('Vigente')
                    ->alignCenter()
                    ->boolean()
                    ->state(fn (Medico $record): bool => $record->estaVigente()),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('especialidad_id')
                    ->label('Especialidad')
                    ->options(fn (): array => Especialidad::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
