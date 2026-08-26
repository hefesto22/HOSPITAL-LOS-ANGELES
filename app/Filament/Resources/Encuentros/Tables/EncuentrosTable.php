<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros\Tables;

use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEncuentro;
use App\Models\Encuentro;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class EncuentrosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('abierto_en', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('numero')->label('Encuentro')->searchable()->sortable()->copyable(),

                TextColumn::make('persona')
                    ->label('Paciente')
                    ->state(fn (Encuentro $record): string => $record->persona->nombreCompleto())
                    ->description(fn (Encuentro $record): string => $record->expediente->numero)
                    ->wrap(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoEncuentro $state): string => $state->etiqueta())
                    ->color(fn (TipoEncuentro $state): string => $state->color()),

                TextColumn::make('abierto_en')->label('Ingreso')->dateTime('d/m/Y H:i')->sortable(),

                TextColumn::make('servicio.nombre')->label('Servicio')->placeholder('—')->toggleable(),

                TextColumn::make('alta_medica_en')
                    ->label('Alta médica')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('salida_fisica_en')
                    ->label('Salida física')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoEncuentro $state): string => $state->etiqueta())
                    ->color(fn (EstadoEncuentro $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => TipoEncuentro::opciones()),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoEncuentro::cases())
                        ->mapWithKeys(fn (EstadoEncuentro $e): array => [$e->value => $e->etiqueta()])
                        ->all()),
            ])
            ->recordActions([ViewAction::make()->label('Ver')])
            ->toolbarActions([]);
    }
}
