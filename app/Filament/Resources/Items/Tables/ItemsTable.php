<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Models\Item;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado del catálogo.
 *
 * ⚠️ La búsqueda NO usa el LIKE por columna que arma Filament solo. Va
 * por `searchUsing`, que delega en `Item::scopeBuscar` y pega contra el
 * índice GIN de trigramas. Con dos mil ítems, un `LIKE '%...%'` sobre
 * tres columnas es un seq scan por tecla, y además no encuentra
 * "acetaminofen" escrito sin tilde.
 *
 * ⚠️ Los closures reciben sus argumentos POR NOMBRE: acá tienen que
 * llamarse `$query` y `$search` porque así los pasa `callSearchUsing`. Un
 * nombre distinto recibe un objeto vacío del contenedor y la búsqueda
 * deja de filtrar EN SILENCIO — sin error y sin log.
 */
final class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchable()
            ->searchPlaceholder('Código, nombre o principio activo')
            ->searchUsing(function (Builder $query, string $search): void {
                self::aplicarBusqueda($query, $search);
            })
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado'),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->wrap()
                    ->sortable()
                    ->description(fn (Item $record): ?string => $record->principio_activo),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TipoItem $state): string => $state->etiqueta()),

                TextColumn::make('regimen_isv')
                    ->label('ISV')
                    ->badge()
                    ->color(fn (RegimenIsv $state): string => $state->color())
                    ->formatStateUsing(fn (RegimenIsv $state): string => $state->etiqueta()),

                /*
                 * El porcentaje sale del enum, que es referencia — la
                 * tabla con vigencia todavía no existe. Cuando exista,
                 * esta columna la lee a ella. Mientras tanto es mejor
                 * mostrar el número de la ley que no mostrar nada: quien
                 * carga el catálogo tiene que ver qué descuento le está
                 * asignando al ítem.
                 */
                TextColumn::make('categoria_legal_descuento')
                    ->label('Adulto mayor')
                    ->badge()
                    ->color(fn (CategoriaLegalDeDescuento $state): string => $state->color())
                    ->formatStateUsing(function (CategoriaLegalDeDescuento $state): string {
                        if ($state === CategoriaLegalDeDescuento::SinDescuentoLegal) {
                            return 'Sin descuento';
                        }

                        return (int) round($state->porcentajeDeReferencia() * 100).' %';
                    })
                    ->tooltip(fn (CategoriaLegalDeDescuento $state): string => $state->etiqueta()
                        .' · '.$state->explicacion()),

                TextColumn::make('unidadDispensacion.codigo')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (Item $record): ?string => $record->unidadDispensacion?->nombre),

                IconColumn::make('es_controlado')
                    ->label('Controlado')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip('Exige libro con saldo corrido y reporte mensual a ARSA.')
                    ->toggleable(),

                TextColumn::make('politica_cargo')
                    ->label('Cómo se cobra')
                    ->badge()
                    ->color(fn (PoliticaCargo $state): string => $state->color())
                    ->formatStateUsing(fn (PoliticaCargo $state): string => $state->etiqueta())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->description(fn (Item $record): string => 'Desde el '
                        .$record->vigencia_desde->format('d/m/Y'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->multiple()
                    ->options(fn (): array => collect(TipoItem::cases())
                        ->mapWithKeys(fn (TipoItem $t): array => [$t->value => $t->etiqueta()])
                        ->all()),

                SelectFilter::make('regimen_isv')
                    ->label('Régimen de ISV')
                    ->options(fn (): array => collect(RegimenIsv::cases())
                        ->mapWithKeys(fn (RegimenIsv $r): array => [$r->value => $r->etiqueta()])
                        ->all()),

                SelectFilter::make('categoria_legal_descuento')
                    ->label('Categoría legal')
                    ->options(fn (): array => collect(CategoriaLegalDeDescuento::cases())
                        ->mapWithKeys(fn (CategoriaLegalDeDescuento $c): array => [
                            $c->value => $c->etiqueta(),
                        ])
                        ->all()),

                TernaryFilter::make('es_controlado')
                    ->label('Controlados')
                    ->placeholder('Todos')
                    ->trueLabel('Solo controlados')
                    ->falseLabel('Sin controlados'),

                /*
                 * Arranca prendido: quien busca en el catálogo casi
                 * siempre quiere lo que se ofrece HOY. Se puede apagar
                 * para revisar un ítem retirado que aparece en una
                 * factura vieja, que es la razón por la que el ítem no se
                 * borra nunca.
                 */
                Filter::make('solo_vigentes')
                    ->label('Solo lo vigente hoy')
                    ->default()
                    ->query(function (Builder $query): void {
                        self::soloVigentes($query);
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading('El catálogo está vacío')
            ->emptyStateDescription(
                'Todo lo que el hospital cobra —medicamentos, exámenes, habitación, honorarios— sale '
                .'de acá. Es el mismo catálogo para farmacia, laboratorio, imágenes y quirófano.'
            )
            ->recordActions([
                /*
                 * Dos condiciones, y la segunda es de permisos: el modal
                 * muestra el margen objetivo y lo que deja cada rango de
                 * edad. Eso es política comercial, y la matriz solo se lo
                 * concede a dirección y auditoría. Ver
                 * `CalcularPrecioAction::puedeVerse()`.
                 */
                CalcularPrecioAction::make()
                    ->visible(fn (Item $record): bool => CalcularPrecioAction::puedeVerse($record)),

                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Las condiciones viven en métodos propios y no dentro del cierre por
     * la misma razón que en `PacientesTable`: el cierre recibe un
     * `Builder` sin genérico, y encadenarle un scope del modelo ahí
     * adentro es lo que PHPStan no puede verificar.
     *
     * @param Builder<Item> $consulta
     */
    private static function aplicarBusqueda(Builder $consulta, string $termino): void
    {
        $consulta->buscar($termino);
    }

    /**
     * @param Builder<Item> $consulta
     */
    private static function soloVigentes(Builder $consulta): void
    {
        $consulta->vigentesEn(now());
    }
}
