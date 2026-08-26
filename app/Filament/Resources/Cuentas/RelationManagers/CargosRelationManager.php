<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\RelationManagers;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\RegimenIsv;
use App\Models\Cargo;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los cargos de la cuenta — la explicación de cada lempira.
 *
 * Va como RelationManager y no como bloque del infolist porque una
 * cuenta de hospitalización larga tiene cientos de líneas: un
 * `RepeatableEntry` renderizaría todas de una sola vez. Un
 * RelationManager pagina y filtra sin pensarlo (§12).
 *
 * ⚠️ Sin acciones de crear, editar ni borrar. Un cargo lo asienta el
 * motor y no se toca después (§9.0.3); corregir es anular desde la
 * pantalla de cuentas abiertas, que asienta la reversa.
 */
class CargosRelationManager extends RelationManager
{
    protected static string $relationship = 'cargos';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    protected static ?string $title = 'Cargos';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['item:id,nombre,codigo', 'lote:id,numero,fecha_vencimiento']))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('texto')
                    ->label('Ítem')
                    ->description(fn (Cargo $record): string => $record->item->codigo)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('cantidad')
                    ->label('Cant.')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),

                TextColumn::make('precio_unitario')
                    ->label('Precio')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),

                TextColumn::make('origen_precio')
                    ->label('Origen del precio')
                    ->badge()
                    ->formatStateUsing(fn (OrigenDelPrecio $state): string => $state->etiqueta())
                    ->color(fn (OrigenDelPrecio $state): string => $state->color())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('descuento_legal')
                    ->label('Desc. de ley')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('regimen_isv')
                    ->label('ISV')
                    ->badge()
                    ->formatStateUsing(fn (RegimenIsv $state): string => $state->etiqueta())
                    ->color(fn (RegimenIsv $state): string => $state->color()),

                TextColumn::make('isv')
                    ->label('ISV L')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2))
                    ->toggleable(isToggledHiddenByDefault: true),

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

                TextColumn::make('lote.numero')
                    ->label('Lote')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('es_tardio')
                    ->label('Tardío')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCargo $state): string => $state->etiqueta())
                    ->color(fn (EstadoCargo $state): string => $state->color()),
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
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
