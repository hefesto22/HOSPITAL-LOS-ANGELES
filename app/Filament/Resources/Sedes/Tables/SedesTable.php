<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes\Tables;

use App\Models\Sede;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabla de Sedes — §10.6.
 */
final class SedesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('codigo_establecimiento')
                    ->label('Estab. SAR')
                    ->placeholder('—')
                    ->tooltip('Los 3 primeros dígitos del correlativo fiscal.'),

                TextColumn::make('rtn')
                    ->label('RTN')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // withCount en getEloquentQuery, no una subconsulta por fila (§12).
                TextColumn::make('servicios_count')
                    ->label('Servicios')
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                TextColumn::make('almacenes_count')
                    ->label('Almacenes')
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                TextColumn::make('vigencia_hasta')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Sede $record): string => $record->estaVigenteEn(now()) ? 'Vigente' : 'Cerrada')
                    ->color(fn (Sede $record): string => $record->estaVigenteEn(now()) ? 'success' : 'danger'),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50, 100])
            ->filters([
                TernaryFilter::make('vigente')
                    ->label('Vigencia')
                    ->placeholder('Todas')
                    ->trueLabel('Solo vigentes')
                    ->falseLabel('Solo cerradas')
                    ->queries(
                        // El scope vigentesEn() vive en el modelo Sede; el
                        // @var le dice a PHPStan sobre qué Builder estamos.
                        true: function (Builder $consulta): Builder {
                            /** @var Builder<Sede> $consulta */
                            return $consulta->vigentesEn(now());
                        },
                        false: fn (Builder $consulta): Builder => $consulta->whereDate('vigencia_hasta', '<', now()),
                        blank: fn (Builder $consulta): Builder => $consulta,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
