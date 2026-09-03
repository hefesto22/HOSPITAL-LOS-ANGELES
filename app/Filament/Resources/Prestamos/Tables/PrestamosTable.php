<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos\Tables;

use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\Prestamos\Actions\DevolverPrestamoAction;
use App\Filament\Resources\Prestamos\Actions\MarcarPagadoAction;
use App\Models\Prestamo;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que se debe, arriba y en rojo.
 *
 * El orden no es por fecha descendente como en el resto del sistema: es
 * por fecha ASCENDENTE dentro de lo que sigue abierto, porque lo que
 * importa de una deuda es cuánto lleva sin saldarse. El préstamo de hace
 * tres semanas es el que hay que mirar, no el de esta mañana.
 */
final class PrestamosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('presta_nombre')
                    ->label('Quién prestó')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Prestamo $record): string => $record->presta_tipo->etiqueta()),

                TextColumn::make('item.nombre')
                    ->label('Qué')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Prestamo $record): ?string => $record->item->codigo ?? null),

                /*
                 * Lo pendiente y no lo prestado: la pregunta de esta
                 * pantalla es «cuánto falta», no «cuánto fue». Lo original
                 * va de descripción para que se pueda auditar sin abrir
                 * nada.
                 */
                TextColumn::make('cantidad')
                    ->label('Falta')
                    ->weight('bold')
                    ->state(fn (Prestamo $record): string => $record->saldoPendiente()->redondeado(2))
                    ->description(fn (Prestamo $record): string => 'de '
                        .Decimal::de($record->cantidad)->redondeado(2)
                        .' prestadas'),

                TextColumn::make('forma_de_saldo')
                    ->label('Se salda')
                    ->badge()
                    ->formatStateUsing(fn (FormaDeSaldo $state): string => $state->etiqueta())
                    ->color('gray')
                    ->description(fn (Prestamo $record): ?string => $record->monto_acordado !== null
                        ? 'L '.Decimal::de($record->monto_acordado)->redondeado(2)
                        : null),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoPrestamo $state): string => $state->etiqueta())
                    ->color(fn (EstadoPrestamo $state): string => $state->color()),

                TextColumn::make('fecha_operacion')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Prestamo $record): string => $record->almacen->nombre ?? '—'),

                TextColumn::make('motivo')
                    ->label('Por qué')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                /*
                 * Prendido por defecto. Lo saldado es la mayoría con el
                 * tiempo, y una lista donde hay que buscar lo que se debe
                 * entre lo que ya se pagó deja de mirarse.
                 */
                TernaryFilter::make('se_debe')
                    ->label('Solo lo que se debe')
                    ->default(true)
                    ->queries(
                        /*
                         * El `@var` no es adorno: Filament tipa el cierre
                         * como `Builder` a secas, sin el modelo, y sobre
                         * un builder genérico el analizador no puede
                         * resolver un scope del modelo.
                         */
                        true: function (Builder $query): Builder {
                            /** @var Builder<Prestamo> $query */
                            return $query->queSeDeben();
                        },
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('presta_tipo')
                    ->label('Quién prestó')
                    ->options(QuienPresta::opciones()),
            ])
            /*
             * El más viejo primero: de una deuda lo que importa es cuánto
             * lleva sin saldarse.
             */
            ->defaultSort('fecha_operacion', 'asc')
            ->paginated([25, 50, 100])
            ->recordActions([
                DevolverPrestamoAction::make(),
                MarcarPagadoAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No se le debe nada a nadie')
            ->emptyStateDescription(
                'Acá aparece lo que el hospital pidió prestado cuando no había existencia, con quién '
                .'lo prestó y cuánto falta devolverle.'
            );
    }
}
