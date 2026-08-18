<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Tables;

use App\Domain\Enums\TipoAlmacen;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class AlmacenesTable
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

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (TipoAlmacen $state): string => $state->color())
                    ->formatStateUsing(fn (TipoAlmacen $state): string => $state->etiqueta()),

                TextColumn::make('servicio.nombre')
                    ->label('Servicio dueño')
                    ->placeholder('— no cuelga de un área')
                    ->searchable(),

                IconColumn::make('maneja_controlados')
                    ->label('Controlados')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip('Exige libro con saldo corrido y reporte mensual a ARSA.'),

                TextColumn::make('sede.codigo')
                    ->label('Sede')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoAlmacen::cases())
                        ->mapWithKeys(fn (TipoAlmacen $t): array => [$t->value => $t->etiqueta()])
                        ->all()),

                TernaryFilter::make('maneja_controlados')
                    ->label('Controlados')
                    ->placeholder('Todos')
                    ->trueLabel('Solo con controlados')
                    ->falseLabel('Sin controlados'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
