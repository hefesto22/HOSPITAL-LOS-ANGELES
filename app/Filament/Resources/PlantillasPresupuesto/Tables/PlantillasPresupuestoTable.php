<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Tables;

use App\Models\PlantillaPresupuesto;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlantillasPresupuestoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nombre')
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Cirugía')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lineas_count')
                    ->label('Renglones')
                    ->counts('lineas')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'success' : 'danger')
                    ->tooltip('Una plantilla sin renglones no cotiza nada.'),

                TextColumn::make('presupuestos_count')
                    ->label('Usada')
                    ->counts('presupuestos')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'info' : 'gray')
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) && (int) $state > 0
                        ? $state.' veces'
                        : 'nunca')
                    ->tooltip('Las que nunca se usan conviene retirarlas con vigencia.'),

                TextColumn::make('dias_vigencia')
                    ->label('Vale')
                    ->alignCenter()
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? "{$state} días" : '—'),

                TextColumn::make('tope_referencia')
                    ->label('No más de')
                    ->alignEnd()
                    ->money('HNL')
                    ->placeholder('sin tope')
                    ->tooltip('Al cotizar avisa si la cotización se pasa de este monto.'),

                TextColumn::make('holgura_fraccion')
                    ->label('Holgura')
                    ->alignCenter()
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state)
                        ? bcmul((string) $state, '100', 1).' %'
                        : '—'),

                TextColumn::make('vigencia_hasta')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? 'En uso' : 'Retirada')
                    ->color(fn (mixed $state): string => $state === null ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('solo_en_uso')
                    ->label('Solo las que están en uso')
                    ->default()
                    /*
                     * ⚠️ El closure recibe `Builder<Model>`, sin el
                     * genérico del modelo, así que NINGÚN scope se
                     * resuelve estáticamente. El `@var` se lo dice.
                     *
                     * La alternativa —copiar acá las tres condiciones de
                     * `scopeVigentesEn`— deja dos definiciones de «está
                     * vigente» que se separan la primera vez que una
                     * cambie.
                     */
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<PlantillaPresupuesto> $query */
                        return $query->vigentesEn(now());
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Todavía no hay plantillas')
            ->emptyStateDescription(
                'Una plantilla es la lista de lo que lleva una cirugía. Sin ellas, cada presupuesto se escribe renglón por renglón — y así es como se cotiza de menos.'
            );
    }
}
