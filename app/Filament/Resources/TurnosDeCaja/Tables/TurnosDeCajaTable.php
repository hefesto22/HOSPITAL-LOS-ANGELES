<?php

declare(strict_types=1);

namespace App\Filament\Resources\TurnosDeCaja\Tables;

use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Domain\ValueObjects\Decimal;
use App\Models\TurnoDeCaja;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TurnosDeCajaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Turno')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TurnoDeCaja $record): ?string => $record->nombre),

                TextColumn::make('usuario.name')
                    ->label('Cajero')
                    ->searchable(),

                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (TurnoDeCaja $record): string => $record->abierto_en->format('H:i')
                        .($record->cerrado_en === null ? ' →' : ' → '.$record->cerrado_en->format('H:i'))),

                TextColumn::make('fondo_inicial')
                    ->label('Fondo')
                    ->money('HNL')
                    ->toggleable(),

                TextColumn::make('efectivo_esperado')
                    ->label('Esperado')
                    ->money('HNL')
                    ->placeholder('—'),

                TextColumn::make('efectivo_contado')
                    ->label('Contado')
                    ->money('HNL')
                    ->placeholder('—'),

                /*
                 * 🔴 LA COLUMNA QUE JUSTIFICA LA PANTALLA.
                 *
                 * Verde en cero, roja si falta, amarilla si sobra: un
                 * sobrante también es un error —casi siempre un vuelto
                 * mal dado— y esconderlo enseña a no reportarlo.
                 */
                TextColumn::make('diferencia')
                    ->label('Diferencia')
                    ->money('HNL')
                    ->weight('bold')
                    ->placeholder('—')
                    ->color(function (TurnoDeCaja $record): string {
                        if ($record->diferencia === null) {
                            return 'gray';
                        }

                        $diferencia = Decimal::de($record->diferencia);

                        return match (true) {
                            $diferencia->esCero()     => 'success',
                            $diferencia->esNegativo() => 'danger',
                            default                   => 'warning',
                        };
                    })
                    ->description(fn (TurnoDeCaja $record): ?string => $record->notas_cierre),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoTurnoDeCaja $state): string => $state->etiqueta())
                    ->color(fn (EstadoTurnoDeCaja $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoTurnoDeCaja::class),

                /*
                 * ⚠️ `$query`, no `$consulta`: con otro nombre Filament
                 * inyecta un Builder vacío del contenedor y el filtro
                 * devuelve la tabla entera como si nada.
                 */
                Filter::make('descuadrados')
                    ->label('Solo los que no cuadraron')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('diferencia')->where('diferencia', '<>', 0)),
            ]);
    }
}
