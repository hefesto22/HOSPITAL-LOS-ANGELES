<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ProveedoresTable
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
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado'),

                TextColumn::make('nombre')
                    ->label('Razón social')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable(),

                TextColumn::make('contacto')
                    ->label('Contacto')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('entradas_count')
                    ->label('Compras')
                    ->counts('entradas')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),
            ])
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Activo')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('nombre')
            ->emptyStateHeading('Todavía no hay proveedores')
            ->emptyStateDescription(
                'Sin proveedor no se puede registrar una compra: la entrada tiene que decir '
                .'de dónde vino la mercadería.'
            );
    }
}
