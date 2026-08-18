<?php

declare(strict_types=1);

namespace App\Filament\Resources\Unidades\Tables;

use App\Domain\Enums\MagnitudDeMedida;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class UnidadesTable
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

                TextColumn::make('simbolo')
                    ->label('Símbolo')
                    ->placeholder('—'),

                TextColumn::make('magnitud')
                    ->label('Qué mide')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (MagnitudDeMedida $state): string => $state->etiqueta()),

                IconColumn::make('permite_fraccion')
                    ->label('Decimales')
                    ->alignCenter()
                    ->boolean()
                    ->tooltip('Si no los admite, ninguna salida del kardex puede tener fracción.'),
            ])
            ->defaultSort('nombre')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('magnitud')
                    ->label('Qué mide')
                    ->options(fn (): array => collect(MagnitudDeMedida::cases())
                        ->mapWithKeys(fn (MagnitudDeMedida $m): array => [$m->value => $m->etiqueta()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make()->modalHeading('Editar unidad')->modalWidth('lg'),
            ])
            ->toolbarActions([]);
    }
}
