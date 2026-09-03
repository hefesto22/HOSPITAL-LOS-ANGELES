<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\ItemPresentacion;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
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
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UNA SOLA COLUMNA CRECE: EL NOMBRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Filament reparte el ancho sobrante EN PARTES IGUALES entre todas las
 * columnas que pueden crecer. Con media docena de columnas de insignia
 * —código, tipo, unidad, ISV— cada insignia de cuatro letras queda
 * flotando en el centro de una celda enorme, y entre «Nombre» y «Tipo»
 * se abre un hueco de un palmo con nada adentro. Eso es exactamente lo
 * que se veía en farmacia cuando se escondieron tres columnas.
 *
 * Por eso TODA columna de acá lleva `grow(false)` salvo el nombre, que
 * es la única de largo variable y la única que se lee de verdad.
 * Cualquier columna nueva nace con `grow(false)`.
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
        $esFarmacia = $ambito === AmbitoCatalogo::Productos;

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

                /*
                 * La segunda forma de mirar lo mismo. «Agrupar por» con
                 * una sola opción es un desplegable que promete algo que
                 * no tiene: se abre, hay un ítem, y se cierra.
                 *
                 * Por tipo es la agrupación de la otra pregunta. La
                 * categoría es la HOJA DEL TARIFARIO —hospitalización,
                 * rayos X, laboratorio—, que es como está impreso el
                 * papel. El tipo es QUÉ CLASE DE COSA ES —servicio,
                 * estancia, procedimiento, honorario médico—, que es lo
                 * que decide cómo se cobra y cómo paga ISV.
                 *
                 * ⚠️ `getTitleFromRecordUsing` y no el valor pelado: sin
                 * eso los encabezados salen «honorario_medico».
                 */
                Group::make('tipo')
                    ->label('Tipo')
                    ->getTitleFromRecordUsing(fn (Item $record): string => $record->tipo->etiqueta())
                    ->collapsible(),
            ])
            ->defaultGroup('categoria.nombre')
            /*
             * Rayado. Con doscientas filas de dos líneas cada una, la
             * banda alterna es lo único que impide leer el nombre de un
             * renglón con la unidad del de abajo.
             */
            ->striped()
            ->searchable()
            ->searchPlaceholder('Escaneá, o escribí código, nombre o principio activo')
            ->searchUsing(function (Builder $query, string $search): void {
                self::aplicarBusqueda($query, $search);
            })
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('gray')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->grow(false),

                /*
                 * La única que crece. Debajo, el principio activo —y en
                 * farmacia, si no lo tiene, la categoría—: sin eso, media
                 * tabla es de una línea y media de dos, y el ojo no
                 * encuentra el renglón.
                 */
                TextColumn::make('nombre')
                    ->label($esFarmacia ? 'Producto' : 'Nombre')
                    ->weight('medium')
                    ->wrap()
                    ->lineClamp(2)
                    ->sortable()
                    ->grow()
                    ->description(fn (Item $record): ?string => self::renglonDeAbajo($ambito, $record)),

                /*
                 * ─────────────────────────────────────────────────────
                 * CÓMO VIENE ENVASADO
                 * ─────────────────────────────────────────────────────
                 *
                 * Solo en farmacia, y es la columna que hacía falta:
                 * quien compra o despacha necesita saber si el producto
                 * viene en caja de 100 o en blíster de 12 ANTES de abrir
                 * la ficha. Del lado de servicios no existe —una consulta
                 * no se envasa—.
                 *
                 * Se muestra sin el nombre del producto: la presentación
                 * se guarda como «PRODUCTO / CAJA X 100 TABLETA» (ver
                 * `PresentacionesRelationManager`) y repetir el nombre al
                 * lado del nombre no dice nada.
                 */
                TextColumn::make('presentaciones.nombre')
                    ->label('Se envasa en')
                    ->badge()
                    ->color('info')
                    ->limitList(2)
                    ->placeholder('Sin presentación')
                    ->visible($esFarmacia)
                    ->grow(false)
                    ->getStateUsing(fn (Item $record): array => self::comoSeEnvasa($record)),

                /*
                 * Se muestra igual estando agrupado: la tabla se puede
                 * reordenar por otra columna y ahí el encabezado del
                 * grupo desaparece. Un ítem sin categoría se ve como tal
                 * y no como un espacio en blanco.
                 *
                 * ⚠️ En farmacia arranca ESCONDIDA: la tabla ya viene
                 * agrupada por categoría, así que la columna repite
                 * palabra por palabra el encabezado del grupo. Quien la
                 * quiera, la enciende.
                 */
                TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->color('info')
                    ->placeholder('Sin categoría')
                    ->sortable()
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: $esFarmacia),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->grow(false)
                    ->formatStateUsing(fn (TipoItem $state): string => $state->etiqueta()),

                TextColumn::make('unidadDispensacion.codigo')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->grow(false)
                    ->tooltip(fn (Item $record): ?string => $record->unidadDispensacion?->nombre),

                IconColumn::make('es_controlado')
                    ->label('Controlado')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->grow(false)
                    ->tooltip('Exige libro con saldo corrido y reporte mensual a ARSA.')
                    ->toggleable(),

                /*
                 * ⚠️ Escondidas por defecto en farmacia y visibles en
                 * servicios, y no es capricho: la salud es exenta y casi
                 * todo medicamento cae en el mismo numeral del Art. 30,
                 * así que acá son dos columnas que dicen «Exento 25 %»
                 * en todas las filas. Del lado de servicios sí varían
                 * —consulta, quirófano, laboratorio— y ahí es donde hay
                 * que poder verlas de un vistazo.
                 */
                TextColumn::make('regimen_isv')
                    ->label('ISV')
                    ->badge()
                    ->color(fn (RegimenIsv $state): string => $state->color())
                    ->formatStateUsing(fn (RegimenIsv $state): string => $state->etiqueta())
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: $esFarmacia),

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
                        .' · '.$state->explicacion())
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: $esFarmacia),

                TextColumn::make('politica_cargo')
                    ->label('Cómo se cobra')
                    ->badge()
                    ->color(fn (PoliticaCargo $state): string => $state->color())
                    ->formatStateUsing(fn (PoliticaCargo $state): string => $state->etiqueta())
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->description(fn (Item $record): string => 'Desde el '
                        .$record->vigencia_desde->format('d/m/Y'))
                    ->grow(false)
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
            ->emptyStateIcon($esFarmacia
                ? 'heroicon-o-beaker'
                : 'heroicon-o-rectangle-stack')
            ->emptyStateHeading($esFarmacia
                ? 'Farmacia no tiene productos cargados'
                : 'El catálogo está vacío')
            ->emptyStateDescription($esFarmacia
                ? 'Acá va lo que se guarda y se cuenta: medicamentos, material de curación, jeringas, '
                .'tubos. Cada uno con su lote, su vencimiento y su existencia por almacén.'
                : 'Acá va lo que el hospital ofrece y cobra: habitación, sala de operaciones, exámenes, '
                .'rayos X, honorarios. Lo que se guarda en el estante se carga en Farmacia.')
            ->recordActions(self::acciones($ambito))
            ->toolbarActions([]);
    }

    /**
     * Las acciones de la fila: la calculadora y el lápiz. Nada más.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ÍCONOS Y NO ETIQUETAS
     * ─────────────────────────────────────────────────────────────────
     *
     * Con etiquetas, «Calcular precio» + «Editar» come casi un tercio
     * del ancho de la fila y empuja el nombre —que es lo que se lee—
     * contra el borde. Dos íconos alineados a la derecha ocupan lo que
     * ocupan y no se mueven.
     *
     * No van dentro de un `ActionGroup`: el §9.A1 ya dejó escrito lo que
     * pasa cuando una acción que necesita `$record` se mete en uno.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LO QUE NO ESTÁ ACÁ, Y DÓNDE ESTÁ
     * ─────────────────────────────────────────────────────────────────
     *
     * · LA ETIQUETA no se imprime desde el listado. En farmacia el
     *   código de barras no es del ítem sino de la presentación —lo que
     *   se agarra con la mano es la caja de 100, no «ACETAMINOFÉN
     *   TABLETA»—, y del lado de servicios el listado es para leer el
     *   tarifario, no para imprimir. Los dos enlaces siguen en la ficha
     *   del ítem (`ItemForm`) y en cada presentación.
     *
     * · MOVER DE ÁMBITO se fue a la cabecera de la ficha
     *   (`EditItem::getHeaderActions()`). Tiene que seguir existiendo
     *   —sin ella, la jeringa que alguien cargó en «Catálogo» queda ahí
     *   para siempre y la salida es un UPDATE a mano—, pero se usa una
     *   vez cada varios meses y no se gana un ícono en doscientas filas.
     *
     * @return array<int, Action>
     */
    private static function acciones(AmbitoCatalogo $ambito): array
    {
        $acciones = [];

        /*
         * Dos condiciones, y la segunda es de permisos: el modal muestra
         * el margen objetivo y lo que deja cada rango de edad. Eso es
         * política comercial, y la matriz solo se lo concede a dirección
         * y auditoría. Ver `CalcularPrecioAction::puedeVerse()`.
         *
         * En farmacia ni se registra: el precio del producto sale del
         * costo promedio, no de una cuenta a mano.
         */
        if ($ambito === AmbitoCatalogo::Servicios) {
            $acciones[] = CalcularPrecioAction::make()
                ->iconButton()
                ->tooltip('Calcular precio')
                ->visible(fn (Item $record): bool => CalcularPrecioAction::puedeVerse($record));
        }

        $acciones[] = EditAction::make()
            ->iconButton()
            ->tooltip('Editar');

        return $acciones;
    }

    /**
     * El renglón chico debajo del nombre.
     *
     * El principio activo cuando lo hay —«acetaminofén» debajo de
     * «TYLENOL 500 MG»— y, en farmacia, la categoría cuando no lo hay.
     * La categoría de relleno es a propósito: del lado de farmacia su
     * columna arranca escondida, y sin este renglón la mitad de las
     * filas mide una línea y la otra mitad dos.
     */
    private static function renglonDeAbajo(AmbitoCatalogo $ambito, Item $item): ?string
    {
        if (filled($item->principio_activo)) {
            return $item->principio_activo;
        }

        if ($ambito !== AmbitoCatalogo::Productos) {
            return null;
        }

        return $item->categoria->nombre ?? null;
    }

    /**
     * Los envases del producto, sin repetir su nombre y con el habitual
     * primero: «CAJA X 100 TABLETA», «BLÍSTER X 12 TABLETA».
     *
     * ⚠️ Sale de la relación ya cargada (ver `ProductoResource`), no de
     * una consulta por fila. Sin ese eager loading esto es un N+1 de
     * veinticinco consultas por página.
     *
     * @return array<int, string>
     */
    private static function comoSeEnvasa(Item $item): array
    {
        return $item->presentaciones
            ->sortByDesc('es_predeterminada')
            ->map(fn (ItemPresentacion $presentacion): string => $presentacion->envase())
            ->values()
            ->all();
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
