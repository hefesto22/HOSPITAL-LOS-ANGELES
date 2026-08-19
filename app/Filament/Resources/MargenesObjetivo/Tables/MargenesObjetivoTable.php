<?php

declare(strict_types=1);

namespace App\Filament\Resources\MargenesObjetivo\Tables;

use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\MargenObjetivo;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * El historial completo, con el vigente arriba.
 *
 * Se muestran también los cerrados: son la explicación de los precios de
 * ayer, y esconderlos convertiría la tabla en un valor de configuración
 * con pasos de más.
 */
final class MargenesObjetivoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipo_item')
                    ->label('Se aplica a')
                    ->badge()
                    ->color(fn (?TipoItem $state): string => $state instanceof TipoItem ? 'primary' : 'gray')
                    ->formatStateUsing(fn (?TipoItem $state): string => $state instanceof TipoItem
                        ? $state->etiqueta()
                        : 'Todo lo demás')
                    ->description(fn (MargenObjetivo $record): ?string => $record->esElDefault()
                        ? 'Default de la instalación'
                        : null),

                TextColumn::make('porcentaje')
                    ->label('Margen sobre el costo')
                    ->weight('bold')
                    ->formatStateUsing(fn (MargenObjetivo $record): string => $record->fraccion()->comoPorcentaje())
                    ->description(fn (MargenObjetivo $record): string => 'L 100 de costo se venden dejando L '
                        .Decimal::de('100')->por($record->fraccion())->redondeado(2)),

                TextColumn::make('vigencia_desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                /*
                 * El color se decide con `$record` y no con `$state`: la
                 * columna tiene cast de fecha, así que lo que llega al
                 * cierre es un Carbon, no la cadena que uno esperaría.
                 */
                TextColumn::make('vigencia_hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn (MargenObjetivo $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),

                TextColumn::make('motivo')
                    ->label('Por qué')
                    ->wrap()
                    ->toggleable(),
            ])
            /*
             * El vigente arriba, y dentro de cada fecha el específico
             * antes que el default: es el orden en que el resolutor los
             * consulta, así que la tabla se lee igual que la decisión.
             */
            ->defaultSort('vigencia_desde', 'desc')
            ->paginated([25, 50])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Todavía no hay ningún margen definido')
            ->emptyStateDescription(
                'Sin margen no hay precio que calcular: la calculadora se niega a inventar uno.'
            );
    }
}
