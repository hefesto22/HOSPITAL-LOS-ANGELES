<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos\Tables;

use App\Domain\Enums\EstadoAbono;
use App\Models\Abono;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AbonosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cuenta.numero')
                    ->label('Cuenta')
                    ->searchable()
                    ->description(fn (Abono $record): ?string => $record->entregado_por),

                TextColumn::make('total')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->sortable(),

                /*
                 * Con qué se pagó. Es lo primero que se busca cuando el
                 * banco no muestra un depósito que el sistema sí tiene.
                 */
                TextColumn::make('medios')
                    ->label('Formas de pago')
                    ->wrap()
                    ->state(fn (Abono $record): string => $record->resumenDeMedios()),

                TextColumn::make('turno.numero')
                    ->label('Turno')
                    ->description(fn (Abono $record): ?string => $record->turno?->nombre),

                TextColumn::make('recibidoPor.name')
                    ->label('Recibió')
                    ->toggleable(),

                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Abono $record): string => $record->recibido_en->format('H:i')),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoAbono $state): string => $state->etiqueta())
                    ->color(fn (EstadoAbono $state): string => $state->color())
                    ->description(fn (Abono $record): ?string => $record->motivo_anulacion),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoAbono::class),

                /*
                 * ⚠️ El parámetro se llama `$query` y no `$consulta`: con
                 * cualquier otro nombre Filament entrega un Builder vacío
                 * del contenedor y el filtro no filtra nada, sin error y
                 * sin log. Hay una prueba de arquitectura que lo vigila.
                 */
                Filter::make('hoy')
                    ->label('Solo los de hoy')
                    ->query(fn (Builder $query): Builder => $query->whereDate('fecha_operacion', now()->toDateString())),
            ]);
    }
}
