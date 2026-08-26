<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargos\Tables;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\RegimenIsv;
use App\Models\Cargo;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CargosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Orden por fecha de operación y no por `id`: es la llave de
             * partición, así que ordenar por ella deja que PostgreSQL
             * pode particiones en vez de recorrerlas todas (§12).
             */
            ->defaultSort('fecha_operacion', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('fecha_operacion')->label('Fecha')->date('d/m/Y')->sortable(),

                TextColumn::make('cuenta.numero')->label('Cuenta')->searchable()->toggleable(),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    /*
                     * Acceso directo y sin `?->`: las tres llaves foráneas
                     * son NOT NULL y el Resource ya trae la cadena con
                     * eager loading. Larastan tipa los BelongsTo como no
                     * nulos, así que un `?->` acá es error de nivel 4
                     * (§9.B1) y un `instanceof` es condición siempre
                     * verdadera.
                     */
                    ->state(fn (Cargo $record): string => $record->cuenta->encuentro->persona->nombreCompleto())
                    ->wrap(),

                TextColumn::make('texto')->label('Ítem')->searchable()->wrap(),

                TextColumn::make('cantidad')->label('Cant.')->numeric(decimalPlaces: 4)->alignEnd(),

                TextColumn::make('regimen_isv')
                    ->label('ISV')
                    ->badge()
                    ->formatStateUsing(fn (RegimenIsv $state): string => $state->etiqueta())
                    ->color(fn (RegimenIsv $state): string => $state->color()),

                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn (Cargo $record): string => $record->totalParaMostrar()),

                TextColumn::make('porcion_paciente')
                    ->label('Paciente')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2))
                    ->toggleable(),

                TextColumn::make('porcion_aseguradora')
                    ->label('Aseguradora')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2))
                    ->toggleable(),

                IconColumn::make('es_tardio')->label('Tardío')->boolean()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCargo $state): string => $state->etiqueta())
                    ->color(fn (EstadoCargo $state): string => $state->color()),

                TextColumn::make('motivo_anulacion')
                    ->label('Motivo de anulación')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoCargo::cases())
                        ->mapWithKeys(fn (EstadoCargo $e): array => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('regimen_isv')
                    ->label('Régimen de ISV')
                    ->options(fn (): array => collect(RegimenIsv::cases())
                        ->mapWithKeys(fn (RegimenIsv $r): array => [$r->value => $r->etiqueta()])
                        ->all()),

                /*
                 * Filtro por defecto al día de hoy: sin él, la primera
                 * carga de esta pantalla ordena toda la tabla
                 * particionada. Con él, PostgreSQL toca una partición.
                 */
                Filter::make('de_hoy')
                    ->label('Solo los de hoy')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('fecha_operacion', now()->toDateString()))
                    ->default(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
