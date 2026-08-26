<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Filament\Resources\Items\Actions\MoverDeAmbitoAction;
use App\Models\CategoriaItem;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
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
        return self::para(AmbitoCatalogo::Servicios, $table);
    }

    public static function paraProductos(Table $table): Table
    {
        return self::para(AmbitoCatalogo::Productos, $table);
    }

    public static function para(AmbitoCatalogo $ambito, Table $table): Table
    {
        return $table
            /*
             * Agrupado por categoría de entrada, que es como está impreso
             * el tarifario. Sin esto son doscientas filas planas donde
             * hay que buscar «RAYOS X» a ojo.
             */
            ->groups([
                Group::make('categoria.nombre')
                    ->label('Categoría')
                    /*
                     * `->` y no `?->`: con `??` a la derecha, la cadena
                     * se evalúa con semántica de `isset` y una categoría
                     * nula no revienta. El nullsafe ahí es redundante y
                     * PHPStan lo marca (`nullsafe.neverNull`).
                     */
                    ->getTitleFromRecordUsing(fn (Item $record): string => $record->categoria->nombre
                        ?? 'Sin categoría')
                    ->collapsible(),
            ])
            ->defaultGroup('categoria.nombre')
            ->searchable()
            ->searchPlaceholder('Escaneá, o escribí código, nombre o principio activo')
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

                /*
                 * Se muestra igual estando agrupado: la tabla se puede
                 * reordenar por otra columna y ahí el encabezado del
                 * grupo desaparece. Un ítem sin categoría se ve como tal
                 * y no como un espacio en blanco.
                 */
                TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->color('info')
                    ->placeholder('Sin categoría')
                    ->sortable()
                    ->toggleable(),

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
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->multiple()
                    ->options(fn (): array => CategoriaItem::query()
                        ->delAmbito($ambito)
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (CategoriaItem $c): array => [$c->getKey() => $c->nombre])
                        ->all()),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->multiple()
                    ->options(fn (): array => $ambito->opcionesDeTipo()),

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
            ->emptyStateIcon($ambito === AmbitoCatalogo::Productos
                ? 'heroicon-o-beaker'
                : 'heroicon-o-rectangle-stack')
            ->emptyStateHeading($ambito === AmbitoCatalogo::Productos
                ? 'Farmacia no tiene productos cargados'
                : 'El catálogo está vacío')
            ->emptyStateDescription($ambito === AmbitoCatalogo::Productos
                ? 'Acá va lo que se guarda y se cuenta: medicamentos, material de curación, jeringas, '
                .'tubos. Cada uno con su lote, su vencimiento y su existencia por almacén.'
                : 'Acá va lo que el hospital ofrece y cobra: habitación, sala de operaciones, exámenes, '
                .'rayos X, honorarios. Lo que se guarda en el estante se carga en Farmacia.')
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

                MoverDeAmbitoAction::make(),

                /*
                 * El hospital reenvasa, así que el blíster que sale de
                 * farmacia no lleva el código de barras del fabricante:
                 * lo imprime el hospital, con SU código.
                 *
                 * Abre una página que se manda a imprimir sola. Un modal
                 * con JavaScript que arma otra ventana se lo come el
                 * bloqueador de pop-ups justo el día que hay que
                 * mostrarlo.
                 */
                Action::make('etiqueta')
                    ->label('Etiqueta')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn (Item $record): string => route('etiquetas.item', [
                        'item'    => $record->getKey(),
                        'formato' => 'media',
                    ]))
                    ->openUrlInNewTab(),

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
