<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai\Tables;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\RangoCai;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RangosCaiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tipo')
                    ->label('Documento')
                    ->badge()
                    ->formatStateUsing(fn (TipoDocumentoDeVenta $state): string => $state->etiqueta())
                    ->color(fn (TipoDocumentoDeVenta $state): string => $state->color()),

                TextColumn::make('cai')
                    ->label('CAI')
                    ->searchable()
                    ->copyable()
                    ->description(fn (RangoCai $record): string => $record->establecimiento
                        .'-'.$record->punto_emision.'-'.$record->tipo_codigo),

                TextColumn::make('siguiente')
                    ->label('Próximo número')
                    ->state(fn (RangoCai $record): string => $record->proximoNumero())
                    ->description(fn (RangoCai $record): string => 'del '.$record->desde.' al '.$record->hasta),

                /*
                 * 🔴 LAS DOS ALERTAS SON INDEPENDIENTES.
                 *
                 * Un rango de 5,000 puede vencer con 4,000 sin usar, y
                 * uno de 500 se agota en marzo aunque venza en
                 * diciembre. Mirar una sola es cómo un hospital se queda
                 * sin poder dar de alta un martes a las 6 de la tarde.
                 */
                TextColumn::make('disponibles')
                    ->label('Quedan')
                    ->state(fn (RangoCai $record): string => (string) $record->disponibles())
                    ->badge()
                    ->color(function (RangoCai $record): string {
                        $umbral = config('sihla.facturacion.alerta_cai_porcentaje_rango');
                        $tope = is_numeric($umbral) ? (float) $umbral : 0.80;

                        return match (true) {
                            $record->seAgoto()                    => 'danger',
                            $record->fraccionConsumida() >= $tope => 'warning',
                            default                               => 'success',
                        };
                    }),

                TextColumn::make('fecha_limite_emision')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable()
                    ->badge()
                    ->color(function (RangoCai $record): string {
                        $dias = config('sihla.facturacion.alerta_cai_dias_restantes');
                        $umbral = is_numeric($dias) ? (int) $dias : 30;

                        return match (true) {
                            $record->vencioAl(now())                                    => 'danger',
                            $record->fecha_limite_emision->diffInDays(now()) <= $umbral => 'warning',
                            default                                                     => 'success',
                        };
                    }),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('resolucion')
                    ->label('Resolución')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('activos')
                    ->label('Solo activos')
                    ->query(fn (Builder $query): Builder => $query->where('activo', true)),

                Filter::make('problemas')
                    ->label('Por vencer o agotados')
                    ->query(function (Builder $query): Builder {
                        $dias = config('sihla.facturacion.alerta_cai_dias_restantes');
                        $umbral = is_numeric($dias) ? (int) $dias : 30;

                        return $query->where(function (Builder $q) use ($umbral): void {
                            $q->whereDate('fecha_limite_emision', '<=', now()->addDays($umbral)->toDateString())
                                ->orWhereColumn('siguiente', '>', 'hasta');
                        });
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
