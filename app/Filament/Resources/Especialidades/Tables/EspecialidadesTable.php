<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Tables;

use App\Models\Especialidad;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class EspecialidadesTable
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

                TextColumn::make('medicos_count')
                    ->label('Médicos')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'info')
                    ->alignCenter(),

                IconColumn::make('vigente')
                    ->label('Vigente')
                    ->alignCenter()
                    ->boolean()
                    ->state(fn (Especialidad $record): bool => $record->estaVigente()),

                TextColumn::make('vigencia_hasta')
                    ->label('Cerrada el')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->paginated([25, 50, 100])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
