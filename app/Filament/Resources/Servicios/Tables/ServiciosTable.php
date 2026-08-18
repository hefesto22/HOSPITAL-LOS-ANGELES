<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servicios\Tables;

use App\Domain\Enums\TipoServicio;
use App\Models\Servicio;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ServiciosTable
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
                    ->formatStateUsing(fn (TipoServicio $state): string => $state->etiqueta()),

                IconColumn::make('camas')
                    ->label('Camas')
                    ->alignCenter()
                    ->boolean()
                    ->state(fn (Servicio $record): bool => $record->tipo->tieneCamas())
                    ->tooltip('Los servicios con camas entran en el censo.'),

                TextColumn::make('almacenes_count')
                    ->label('Almacenes')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'info')
                    ->alignCenter()
                    ->tooltip('Cero es válido: el área consume del dispensario.'),

                TextColumn::make('sede.codigo')
                    ->label('Sede')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('centro_costo')
                    ->label('C. costo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoServicio::cases())
                        ->mapWithKeys(fn (TipoServicio $t): array => [$t->value => $t->etiqueta()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
