<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Tables;

use App\Domain\Enums\TipoDeAjuste;
use App\Models\MargenObjetivo;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Todo lo que salió del inventario sin venderse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA COLUMNA DE PLATA ES UN PERMISO, NO UNA COLUMNA
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.L13: el costo y el margen se protegen en el Resource, en la tabla,
 * en el export y en el PDF — los cuatro. Acá el valor del ajuste solo se
 * muestra a quien puede ver márgenes, que es el mismo permiso con el que
 * ya se protege la calculadora de precios.
 *
 * Bodega y farmacia ven QUÉ se ajustó y CUÁNTO en unidades. Cuánto costó,
 * no: es información de margen, y el que registra una merma no la
 * necesita para registrarla.
 */
final class AjustesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('almacen'))
            ->columns([
                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoDeAjuste $state): string => $state->etiqueta())
                    ->color(fn (TipoDeAjuste $state): string => $state->color()),

                TextColumn::make('almacen.nombre')
                    ->label('Almacén')
                    ->sortable(),

                TextColumn::make('motivo')
                    ->label('Qué pasó')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('lineas_count')
                    ->label('Productos')
                    ->counts('lineas')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                TextColumn::make('valor_absoluto')
                    ->label('Valor al costo')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => self::puedeVerCostos()),

                TextColumn::make('conteo_id')
                    ->label('Conteo')
                    ->placeholder('—')
                    ->prefix('#')
                    ->toggleable(),

                TextColumn::make('autorizadoPor.name')
                    ->label('Autorizó')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Registró')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoDeAjuste::cases())
                        ->mapWithKeys(fn (TipoDeAjuste $t): array => [$t->value => $t->etiqueta()])
                        ->all()),

                SelectFilter::make('almacen_id')
                    ->label('Almacén')
                    ->relationship('almacen', 'nombre')
                    ->preload(),

                /*
                 * El reporte que dirección tiene que mirar: lo que pasó
                 * el tope y alguien tuvo que autorizar. Sin este filtro,
                 * el tope solo sirve para molestar en el momento.
                 */
                Filter::make('autorizados')
                    ->label('Solo los que necesitaron autorización')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('autorizado_por')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('fecha_operacion', 'desc')
            ->emptyStateHeading('Todavía no se ajustó nada')
            ->emptyStateDescription(
                'Acá quedan las mermas, las bajas por vencimiento, las correcciones y las '
                .'diferencias que asienta cada conteo físico. Es la respuesta a «¿qué se perdió '
                .'este mes y quién lo autorizó?».'
            );
    }

    /**
     * Mismo permiso con el que se protege la calculadora de precios: ver
     * el margen del hospital es una decisión de rol, no una columna que
     * se muestra porque sí.
     */
    private static function puedeVerCostos(): bool
    {
        return Gate::allows('viewAny', MargenObjetivo::class);
    }
}
