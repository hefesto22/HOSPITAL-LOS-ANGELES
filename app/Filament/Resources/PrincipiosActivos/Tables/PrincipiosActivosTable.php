<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrincipiosActivos\Tables;

use App\Models\PrincipioActivo;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PrincipiosActivosTable
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
                    ->label('Principio activo')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('tambien_llamado')
                    ->label('También llamado')
                    ->searchable()
                    ->placeholder('—')
                    ->color('gray'),

                /*
                 * 🔴 El número que hace útil esta pantalla. Un principio
                 * activo con CERO productos es uno que alguien creó de
                 * más o uno que hay que terminar de vincular — y sin la
                 * columna, no hay forma de verlo sin abrirlos de a uno.
                 */
                TextColumn::make('items_count')
                    ->label('Productos')
                    ->counts('items')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'success')
                    ->sortable(),

                TextColumn::make('codigo_atc')
                    ->label('ATC')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('vigencia_hasta')
                    ->label('Retirado el')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->filters([
                /*
                 * ⚠️ El parámetro se llama `$query` y NO se le cambia el
                 * nombre. Filament resuelve los parámetros del cierre por
                 * NOMBRE: con cualquier otro entrega un Builder vacío del
                 * contenedor, y el filtro deja de filtrar sin excepción,
                 * sin log y sin nada en la pantalla que lo delate. Hay un
                 * test de arquitectura que lo vigila.
                 */
                Filter::make('sin_productos')
                    ->label('Sin productos vinculados')
                    ->query(fn (Builder $query): Builder => $query->doesntHave('items')),

                Filter::make('vigentes')
                    ->label('Solo lo vigente hoy')
                    ->default()
                    /*
                     * Y el `@var` tampoco es decoración: el cierre recibe
                     * un `Builder` pelado y el analizador no puede saber
                     * de qué modelo es, así que no encuentra el scope
                     * `vigentesEn`.
                     */
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<PrincipioActivo> $query */
                        return $query->vigentesEn(now());
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Editar principio activo')
                    ->modalWidth('xl'),

                Action::make('etiqueta')
                    ->label('Etiqueta')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn (PrincipioActivo $record): string => route('etiquetas.principio', [
                        'principio' => $record->getKey(),
                        'formato'   => 'media',
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }
}
