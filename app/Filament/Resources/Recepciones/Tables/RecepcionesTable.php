<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Tables;

use App\Filament\Resources\Recepciones\Actions\MarcarRevisadaAction;
use App\Models\Recepcion;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que entró al hospital.
 *
 * El filtro de «sin revisar» es el que sostiene el control ahora que la
 * entrada es directa: la mercadería entra al toque, y la pregunta de
 * todos los días es cuáles no miró nadie todavía.
 */
final class RecepcionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha_recepcion')
                    ->label('Llegó')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('referencia')
                    ->label('Referencia')
                    ->placeholder('sin referencia')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('almacen.nombre')
                    ->label('Almacén')
                    ->sortable(),

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('lineas_count')
                    ->label('Productos')
                    ->counts('lineas')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                IconColumn::make('revisada_en')
                    ->label('Revisada')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Recepcion $record): string => $record->estaRevisada()
                        ? 'La miró '.($record->revisadaPor->name ?? 'alguien')
                        : 'Todavía no la revisó nadie. La mercadería YA está en el kardex.'),

                TextColumn::make('createdBy.name')
                    ->label('Recibió')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                /*
                 * Sin revisar primero y por defecto: es la lista de
                 * pendientes, no un filtro que alguien tenga que
                 * acordarse de aplicar.
                 */
                Filter::make('sin_revisar')
                    ->label('Solo las que faltan revisar')
                    ->query(fn (Builder $query): Builder => $query->whereNull('revisada_en')),

                SelectFilter::make('almacen_id')
                    ->label('Almacén')
                    ->relationship('almacen', 'nombre')
                    ->preload(),

                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                MarcarRevisadaAction::make(),
                ViewAction::make(),
            ])
            ->defaultSort('fecha_recepcion', 'desc')
            ->emptyStateHeading('Todavía no entró nada')
            ->emptyStateDescription(
                'Una recepción mueve el kardex apenas se guarda. Escaneá el código de barras de '
                .'lo que llegó y poné cuántas cajas y a cuánto.'
            );
    }
}
