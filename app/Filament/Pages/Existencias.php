<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Unidad;
use App\Services\TrasladadorDeExistencias;
use App\Support\AlmacenesDelUsuario;
use App\Support\NumeroDeFormulario;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Qué hay, dónde está, y el botón para moverlo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTA PANTALLA EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Desde que el hospital separó FARMACIA de BODEGA, «¿cuántas ampollas de
 * fentanilo hay?» dejó de tener una sola respuesta. Hay 10 en bodega, 1
 * en el carrito rojo 1 y 1 en el carrito rojo 2, y la pregunta que
 * importa a las tres de la mañana no es cuántas hay sino DÓNDE.
 *
 * El dato ya estaba —`existencias` es (ítem, lote, almacén) desde el
 * primer día—, pero no había dónde verlo: se llegaba a él por el kardex
 * de un ítem, un ítem por vez. Acá se ve el estante entero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * AGRUPADA POR PRODUCTO, NO POR ALMACÉN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Agrupar por almacén contesta «qué hay en bodega», que se puede filtrar.
 * Agrupar por producto contesta «dónde está el fentanilo», que es la
 * pregunta con la que se entra: las tres filas quedan juntas, se ven los
 * tres lugares de un vistazo y se elige cuál mover. Quien quiera lo otro
 * cambia el agrupador, que está arriba.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UN RESOURCE (§9.A10)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `Existencia` es un SALDO y se escribe únicamente desde
 * `RegistradorDeMovimiento`. Un Resource traería crear, editar y borrar,
 * y las tres son exactamente lo que no puede pasar: un saldo tecleado a
 * mano deja el kardex diciendo otra cosa, y el kardex es la verdad.
 *
 * Lo único que se puede hacer desde acá es MOVER, que no cambia el
 * número: lo pasa de un estante a otro asentando las dos líneas.
 */
class Existencias extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'existencias';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.existencias';

    public static function getNavigationLabel(): string
    {
        return 'Existencias';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public function getTitle(): string
    {
        return 'Existencias';
    }

    public function getSubheading(): string
    {
        return 'Qué hay en cada estante, lote por lote. El botón «Mover» lo baja a otro almacén '
            .'sin sacarlo del inventario.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Almacen::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    // ── La cinta de estantes ──────────────────────────────────────────

    /**
     * Un renglón por almacén con saldo: cuántas filas tiene y cuántas
     * están por vencer.
     *
     * ⚠️ DOS CONSULTAS AGRUPADAS, NO UNA POR ALMACÉN. Con un conteo por
     * tarjeta serían cinco consultas cada vez que Livewire repinta la
     * pantalla, y esto se repinta con cada tecla del buscador — el N+1
     * invisible del §13.2.
     *
     * @return list<array{id: int, nombre: string, tipo: string, renglones: int, porVencer: int, activo: bool}>
     */
    public function estantes(): array
    {
        $renglones = Existencia::query()
            ->conSaldo()
            ->selectRaw('almacen_id, count(*) as cuantos')
            ->groupBy('almacen_id')
            ->pluck('cuantos', 'almacen_id');

        $porVencer = Existencia::query()
            ->conSaldo()
            ->whereHas(
                'lote',
                fn (Builder $lote): Builder => $lote
                    ->whereNotNull('fecha_vencimiento')
                    ->whereDate('fecha_vencimiento', '<=', now()->addDays(90)->toDateString()),
            )
            ->selectRaw('almacen_id, count(*) as cuantos')
            ->groupBy('almacen_id')
            ->pluck('cuantos', 'almacen_id');

        if ($renglones->isEmpty()) {
            return [];
        }

        $elegido = $this->almacenFiltrado();

        /*
         * ⚠️ `array_values()` aunque la colección ya venga en orden:
         * `Collection::all()` devuelve `array<int, …>`, que NO es lo
         * mismo que una `list` —puede tener huecos en las claves— y el
         * analizador no lo da por sentado. Sin esto no compila a nivel 7.
         */
        return array_values(Almacen::query()
            ->whereIn('id', $renglones->keys())
            ->orderBy('nombre')
            ->get()
            ->map(fn (Almacen $almacen): array => [
                'id'        => $almacen->id,
                'nombre'    => $almacen->nombre,
                'tipo'      => $almacen->tipo->etiqueta(),
                'renglones' => (int) ($renglones[$almacen->id] ?? 0),
                'porVencer' => (int) ($porVencer[$almacen->id] ?? 0),
                'activo'    => $elegido === $almacen->id,
            ])
            ->all());
    }

    /**
     * La tarjeta es un atajo al filtro, no un estado aparte.
     *
     * Escribe en el filtro de la tabla en vez de guardar el almacén en
     * una propiedad propia: si fueran dos estados, la tarjeta marcada y
     * la tabla filtrada podrían discrepar, y una pantalla de inventario
     * que dice «BODEGA» arriba mostrando filas de farmacia es peor que no
     * tener la cinta.
     *
     * Apretar la que ya está activa la apaga: es el gesto que la gente
     * intenta para volver a ver todo.
     */
    public function verSoloEste(int $almacenId): void
    {
        $this->tableFilters['almacen_id']['value'] = $this->almacenFiltrado() === $almacenId
            ? null
            : $almacenId;

        /*
         * ⚠️ `fill($this->tableFilters)` y NO `resetTableFiltersForm()`:
         * ese último llama a `fill()` sin argumentos, que vuelve a los
         * valores POR DEFECTO — es decir, borra el filtro que acabamos de
         * poner. El select de arriba quedaría vacío y la tabla sin
         * filtrar, con la tarjeta pintada como activa. Peor que no tener
         * el atajo.
         */
        $this->getTableFiltersForm()->fill($this->tableFilters);

        /*
         * Y esto es lo que guarda el filtro en la sesión y devuelve la
         * paginación a la página uno. Sin él, apretar BODEGA estando en
         * la página 4 muestra una tabla vacía.
         */
        $this->updatedTableFilters();
    }

    private function almacenFiltrado(): ?int
    {
        $valor = $this->tableFilters['almacen_id']['value'] ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $tabla): Table
    {
        return $tabla
            ->query($this->consulta())
            ->defaultGroup('item.nombre')
            ->groups([
                Group::make('item.nombre')->label('Producto')->collapsible(),
                Group::make('almacen.nombre')->label('Almacén')->collapsible(),
            ])
            ->striped()
            ->defaultSort('lote.fecha_vencimiento')
            ->paginated([25, 50, 100])
            ->columns($this->columnas())
            ->filters($this->filtros())
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([$this->moverAction()])
            ->emptyStateHeading('No hay nada en existencia')
            ->emptyStateDescription(
                'Lo que entra por recepción aparece acá. Si acaba de recibir mercadería y no la '
                .'ve, revise que la recepción se haya guardado.'
            );
    }

    /**
     * ⚠️ NINGÚN JOIN A MANO.
     *
     * La tentación es unir `items` y `lotes` acá para poder ordenar y
     * filtrar por sus columnas. No: Filament también une esas mismas
     * tablas cuando una columna de relación es `sortable()` o
     * `searchable()`, y PostgreSQL corta la consulta con «table name
     * "items" specified more than once».
     *
     * Los filtros que miran el lote o el ítem van por `whereHas`, que es
     * subconsulta y no agrega tablas al FROM.
     *
     * Solo las filas con saldo: un estante que quedó en cero no es un
     * renglón que alguien tenga que leer, y son la mayoría después de
     * unos meses.
     *
     * @return Builder<Existencia>
     */
    private function consulta(): Builder
    {
        return Existencia::query()
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 SIN ENUMERAR COLUMNAS. TRES VECES BASTÓ.
             * ─────────────────────────────────────────────────────────
             *
             * Esta pantalla cargaba solo las columnas que usaba, y esa
             * micro-optimización rompió la pantalla TRES veces en un día,
             * siempre igual: alguien —yo— suma un dato al renglón, la
             * columna que ese dato necesita no está en la lista, y
             * Eloquent devuelve NULO sin decir nada.
             *
             *   · `item` sin `unidad_dispensacion_id` → la cantidad se
             *     quedaba sin unidad.
             *   · `item` sin `nombre` → `trim(null)`, TypeError, pantalla
             *     en blanco al abrir «Mover».
             *   · `almacen` sin `sede_id` → `where('sede_id', null)`, y
             *     el desplegable de destino decía «No hay opciones
             *     disponibles» teniendo dos almacenes al lado.
             *
             * El último es el peor de los tres porque NO parece un error:
             * parece que el hospital no tiene a dónde mover.
             *
             * Son veinticinco filas por página. Lo que se ahorraba no
             * pagaba ni el primero de los tres.
             */
            ->with([
                'item.unidadDispensacion',
                'lote.presentacion.unidad',
                'almacen',
            ])
            ->conSaldo();
    }

    /**
     * @return list<TextColumn>
     */
    private function columnas(): array
    {
        return [
            /*
             * 🔴 SOLO EL NOMBRE CRECE.
             *
             * Filament reparte el ancho sobrante entre TODAS las columnas
             * que pueden crecer. Con dos o más creciendo, el resultado es
             * una tabla con huecos entre columnas cortas. `grow(false)` en
             * todo lo demás es lo que la deja apretada y legible.
             */
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL ENVASE VA EN EL RENGLÓN, NO SOLO EL PRODUCTO
             * ─────────────────────────────────────────────────────────
             *
             * El jarabe de acetaminofén se compra en frasco de 60, de 80
             * y de 120 ML, y cada envase lleva su propio lote. Con solo
             * el nombre del producto, las tres filas se leen idénticas
             * —mismo nombre, mismo código, hasta el mismo número de
             * lote— y lo único distinto es la cantidad. Ahí alguien mueve
             * el frasco que no era, y el error recién aparece en el
             * conteo.
             *
             * Por eso la segunda línea es «CÓDIGO · FRASCO X 120 ML».
             */
            TextColumn::make('item.nombre')
                ->label('Producto')
                ->searchable()
                ->sortable()
                ->weight('medium')
                ->description(fn (Existencia $record): string => self::comoSeEnvasa($record)),

            TextColumn::make('almacen.nombre')
                ->label('Dónde está')
                ->badge()
                ->grow(false)
                ->sortable()
                ->color(fn (Existencia $record): string => $record->almacen?->tipo->color() ?? 'gray')
                ->description(fn (Existencia $record): ?string => $record->almacen?->tipo->etiqueta()),

            TextColumn::make('lote.numero')
                ->label('Lote')
                ->grow(false)
                ->searchable()
                ->fontFamily(FontFamily::Mono)
                ->placeholder('Sin lote'),

            /*
             * El vencimiento va al lado de la cantidad y no escondido en
             * el lote: es lo que decide QUÉ fila mover. Primero se vacía
             * lo que vence, y para eso hay que verlo sin abrir nada.
             */
            TextColumn::make('lote.fecha_vencimiento')
                ->label('Vence')
                ->date('d/m/Y')
                ->grow(false)
                ->sortable()
                ->placeholder('—')
                ->badge()
                ->color(fn (Existencia $record): string => self::colorDelVencimiento($record))
                ->description(fn (Existencia $record): ?string => self::cuantoLeQueda($record)),

            /*
             * 🔴 «1200» a secas no dice nada: pueden ser 1.200 mililitros
             * o 1.200 frascos, y son dos hechos con dos dígitos de
             * diferencia. Va la unidad de dispensación al lado del
             * número, y debajo la traducción a envases —«= 10 FRASCO»—,
             * que es como lo cuenta quien está frente al estante.
             */
            TextColumn::make('cantidad')
                ->label('Cantidad')
                ->grow(false)
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn (Existencia $record): string => self::cuantoHay($record))
                ->description(fn (Existencia $record): ?string => self::enCuantosEnvases($record)),
        ];
    }

    /**
     * @return list<Filter|SelectFilter>
     */
    private function filtros(): array
    {
        return [
            SelectFilter::make('almacen_id')
                ->label('Almacén')
                ->options(fn (): array => Almacen::query()
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all()),

            SelectFilter::make('tipo_almacen')
                ->label('Tipo de estante')
                ->options(fn (): array => collect(TipoAlmacen::cases())
                    ->mapWithKeys(fn (TipoAlmacen $t): array => [$t->value => $t->etiqueta()])
                    ->all())
                ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                    ? $query
                    : $query->whereHas(
                        'almacen',
                        fn (Builder $almacen): Builder => $almacen->where('tipo', $data['value']),
                    )),

            /*
             * Los tres filtros que de verdad se usan, y ninguno es
             * decorativo: por vencer es lo que hay que mover o vender
             * primero, vencido es lo que ARSA encuentra en el estante, y
             * los controlados son el libro que se revisa a diario.
             */
            Filter::make('por_vencer')
                ->label('Por vencer en 90 días')
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereHas(
                    'lote',
                    fn (Builder $lote): Builder => $lote
                        ->whereNotNull('fecha_vencimiento')
                        ->whereDate('fecha_vencimiento', '>=', now()->toDateString())
                        ->whereDate('fecha_vencimiento', '<=', now()->addDays(90)->toDateString()),
                )),

            Filter::make('vencido')
                ->label('Ya vencido')
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereHas(
                    'lote',
                    fn (Builder $lote): Builder => $lote
                        ->whereNotNull('fecha_vencimiento')
                        ->whereDate('fecha_vencimiento', '<', now()->toDateString()),
                )),

            Filter::make('controlados')
                ->label('Solo controlados')
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereHas(
                    'item',
                    fn (Builder $item): Builder => $item->where('es_controlado', true),
                )),
        ];
    }

    // ── Mover ─────────────────────────────────────────────────────────

    /**
     * El botón que contesta «de dónde sale y a dónde va».
     *
     * Actúa sobre la FILA, no sobre el producto: la fila ya trae el lote
     * y el almacén de origen, y su vencimiento está a la vista de quien
     * la eligió. Un botón a nivel de producto obligaría a la pantalla a
     * decidir qué lote baja, y esa decisión la toma quien está frente al
     * estante.
     */
    private function moverAction(): Action
    {
        return Action::make('mover')
            ->label('Mover')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->button()
            ->outlined()
            ->color('primary')
            ->modalHeading('Mover a otro almacén')
            /*
             * 🔴 El encabezado nombra el RENGLÓN, no la acción. Con tres
             * frascos del mismo jarabe en la tabla, «Mover a otro
             * almacén» a secas no confirma cuál se apretó — y el modal
             * tapa justo la fila que lo diría.
             */
            ->modalDescription(fn (Existencia $record): string => self::comoSeLlamaEsteRenglon($record))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Mover')
            /*
             * ─────────────────────────────────────────────────────────
             * VER ES LIBRE; MOVER, SOLO DESDE TU ESTANTE
             * ─────────────────────────────────────────────────────────
             *
             * La tabla muestra el inventario ENTERO a cualquiera que
             * pueda entrar, y eso es a propósito: farmacia tiene que
             * poder ver que bodega tiene cuarenta cajas para pedir la
             * reposición. Esconderlo obligaría a preguntar por teléfono
             * lo que el sistema ya sabe.
             *
             * El botón es otra cosa. Aparece solo en las filas del
             * estante del que esa persona responde —el mismo mapa que
             * gobierna conteos y ajustes—, así que farmacia ve el
             * renglón de bodega pero no puede sacarle nada.
             *
             * El permiso de acción es el de ajustar: un traslado toca el
             * kardex igual, aunque no cambie el total del hospital.
             */
            ->visible(fn (Existencia $record): bool => Gate::allows('create', Ajuste::class)
                && AlmacenesDelUsuario::puedeOperarEn($record->almacen))
            ->fillForm(fn (Existencia $record): array => [
                'origen' => $record->almacen?->etiqueta() ?? '—',
            ])
            ->schema(fn (Existencia $record): array => [
                TextInput::make('origen')
                    ->label('Sale de')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(self::dondeMasHay($record)),

                /*
                 * ⚠️ SIN `searchable()`.
                 *
                 * Con `options()` y búsqueda encendida, Filament manda el
                 * término al servidor y busca con `getSearchResultsUsing`
                 * — que acá no existe—, así que escribir «BODEGA»
                 * devolvía «No se encontraron coincidencias» ESTANDO
                 * BODEGA en la lista. Un hospital tiene cinco estantes,
                 * no quinientos: se eligen mirando.
                 */
                Select::make('destino_id')
                    ->label('Entra a')
                    ->required()
                    ->native(false)
                    ->options(fn (): array => self::destinosPara($record))
                    ->helperText(
                        'Si el carrito no aparece en la lista, se crea primero en Inventario → '
                        .'Almacenes.'
                    ),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 SE MUEVEN ENVASES, NO UNIDADES DE DISPENSACIÓN
                 * ─────────────────────────────────────────────────────
                 *
                 * La primera versión pedía la cantidad en la unidad del
                 * kardex —mililitros— porque es lo que el kardex guarda.
                 * Fue un error, y se vio al primer intento: alguien
                 * escribió «5» queriendo bajar 5 FRASCOS y bajó 5 ML.
                 * La bodega quedó con 0.08 de un frasco, que es una
                 * cantidad que en el estante no existe.
                 *
                 * Un traslado es un hecho FÍSICO: alguien levanta frascos
                 * y los lleva. Se pide en frascos y el sistema convierte,
                 * que es exactamente para lo que las presentaciones
                 * existen. El kardex sigue en mililitros — eso no cambia,
                 * pero deja de ser problema de quien mueve.
                 */
                TextInput::make('cantidad')
                    ->label(self::comoSePideLaCantidad($record))
                    ->required()
                    ->numeric()
                    ->live(onBlur: true)
                    /*
                     * Paso fino aunque casi siempre se teclee un entero:
                     * devolver el resto de un frasco abierto —0.0833
                     * FRASCO— tiene que ser posible, y con `step` en 0.01
                     * el navegador rechaza ese número antes de que llegue
                     * al servidor.
                     */
                    ->minValue('0.0001')
                    ->step('0.0001')
                    /*
                     * El tope como CADENA y no como float: §8.6.2 prohíbe
                     * que una cantidad de inventario pase por punto
                     * flotante, y un tope mal redondeado deja fuera el
                     * último envase justo cuando se quiere vaciar.
                     *
                     * Igual no es el candado: el candado es el `UPDATE`
                     * condicional del movimiento. Esto es para que el
                     * error se vea antes de apretar.
                     */
                    ->maxValue(fn (): string => self::topeParaMover($record))
                    /*
                     * ─────────────────────────────────────────────────
                     * «MOVER TODO» NO ES UN ATAJO, ES LO QUE EVITA UN
                     * SALDO IMPOSIBLE
                     * ─────────────────────────────────────────────────
                     *
                     * Vaciar un renglón es de las cosas que más se hacen:
                     * devolver lo que se bajó de más, sacar el lote que
                     * vence, mudar un estante entero. Y cuando el saldo
                     * no es un número redondo de envases —5 ML de un
                     * frasco de 60 son 0.0833 FRASCO— teclearlo a mano es
                     * pedir un error de dedo que deja un resto pegado al
                     * estante equivocado.
                     *
                     * El botón pone el tope exacto, que es el número que
                     * el servicio interpreta como «todo».
                     */
                    ->hintAction(
                        Action::make('mover_todo')
                            ->label('Mover todo')
                            ->icon(Heroicon::OutlinedArrowsRightLeft)
                            ->action(fn (Set $set) => $set('cantidad', self::topeParaMover($record))),
                    )
                    ->helperText(fn (Get $get): string => self::cuantoQuedaEnEsteLote(
                        $record,
                        $get('cantidad'),
                    )),

                Textarea::make('motivo')
                    ->label('Nota (opcional)')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Reposición del carro, préstamo a quirófano…')
                    ->helperText('Queda escrita en las dos líneas del kardex.'),
            ])
            ->action(function (array $data, Existencia $record, Action $action): void {
                if (! $this->mover($record, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mover(Existencia $existencia, array $data): bool
    {
        /*
         * 🔴 SE VUELVE A PREGUNTAR ACÁ, aunque el botón ya esté escondido
         * para quien no puede. `visible()` decide qué se DIBUJA, y lo que
         * se dibuja lo controla el navegador: una llamada de Livewire
         * armada a mano llega igual. La autorización tiene que estar
         * donde se escribe, no donde se pinta.
         */
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $item = $existencia->item;
        $origen = $existencia->almacen;

        $destino = is_numeric($data['destino_id'] ?? null)
            ? Almacen::query()->find((int) $data['destino_id'])
            : null;

        if (! $item instanceof Item || ! $origen instanceof Almacen || ! $destino instanceof Almacen) {
            Notification::make()
                ->title('No se pudo mover')
                ->body('Falta el producto, el estante de origen o el de destino.')
                ->danger()
                ->send();

            return false;
        }

        $tecleado = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

        if (! $tecleado instanceof Decimal) {
            Notification::make()
                ->title('La cantidad no se entiende')
                ->body('Escriba cuántos '.(self::unidadDelEnvase($existencia) ?? 'unidades').' se mueven.')
                ->danger()
                ->send();

            return false;
        }

        $cantidad = self::aUnidadesDeDispensacion($existencia, $tecleado);

        $lote = $existencia->lote_id === null ? null : Lote::query()->find($existencia->lote_id);

        try {
            app(TrasladadorDeExistencias::class)->trasladar(
                item: $item,
                lote: $lote instanceof Lote ? $lote : null,
                origen: $origen,
                destino: $destino,
                cantidad: $cantidad,
                motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : null,
            );
        } catch (SihlaException $e) {
            Notification::make()
                ->title('No se pudo mover')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Movido')
            ->body(
                self::comoSeCuenta($existencia, $tecleado)
                .' de '.$item->nombre
                .' pasaron de '.$origen->nombre.' a '.$destino->nombre.'.'
            )
            ->success()
            ->send();

        return true;
    }

    /**
     * Los estantes a los que se puede bajar esto.
     *
     * Se excluye el propio origen —un traslado a sí mismo no mueve nada—
     * y los cerrados. Se quedan los de otra sede fuera por la misma razón
     * que los rechaza el servicio: el costo es de cada sede.
     *
     * @return array<int, string>
     */
    private static function destinosPara(Existencia $existencia): array
    {
        $origen = $existencia->almacen;

        if (! $origen instanceof Almacen) {
            return [];
        }

        /*
         * ⚠️ Se relee la sede si no vino. Es el cinturón por si alguna
         * consulta futura vuelve a cargar el almacén con columnas
         * enumeradas: sin esto, un `sede_id` nulo filtra a CERO y la
         * pantalla dice «no hay a dónde mover» en vez de fallar.
         */
        $sedeId = $origen->sede_id ?? Almacen::query()
            ->whereKey($origen->id)
            ->value('sede_id');

        if ($sedeId === null) {
            return [];
        }

        return Almacen::query()
            ->vigentes()
            ->where('sede_id', $sedeId)
            ->whereKeyNot($origen->id)
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Almacen $a): array => [$a->id => $a->etiqueta()])
            ->all();
    }

    /**
     * «Y además hay 3 en BODEGA y 1 en CARRITO ROJO 2».
     *
     * Es lo que evita el viaje de más: quien va a bajar una ampolla al
     * carrito ve, sin salir del modal, que el carrito de al lado ya tiene
     * una — o que el otro lote vence antes y conviene mover ese.
     */
    private static function dondeMasHay(Existencia $existencia): string
    {
        /**
         * ⚠️ NO se suma por almacén.
         *
         * El mismo jarabe vive en tres envases distintos —60, 80 y 120
         * ML— y todos miden en mililitros. Sumarlos porque están en el
         * mismo estante daría «2600 ML en ALMACEN-1», un número que no
         * corresponde a ningún frasco que alguien pueda agarrar. Cada
         * renglón se lista como es: cuánto, dónde y en qué envase.
         *
         * @var Collection<int, Existencia> $otras
         */
        $otras = Existencia::query()
            /*
             * 🔴 SIN ENUMERAR COLUMNAS, al revés que la consulta de la
             * tabla.
             *
             * Acá se cargaba `item:id,unidad_dispensacion_id` porque era
             * lo único que se usaba… hasta que el renglón pasó a mostrar
             * también el envase, que necesita `item.nombre` para no
             * repetirlo. El resultado no fue una etiqueta incompleta: fue
             * un TypeError con la pantalla entera en blanco.
             *
             * Son seis filas como mucho. El ahorro de enumerar columnas
             * acá no paga el riesgo de que la próxima persona que sume un
             * dato al renglón tumbe el modal sin enterarse.
             */
            ->with(['almacen', 'item.unidadDispensacion', 'lote.presentacion.unidad'])
            ->where('item_id', $existencia->item_id)
            ->whereKeyNot($existencia->id)
            ->conSaldo()
            ->orderByDesc('cantidad')
            ->limit(6)
            ->get();

        if ($otras->isEmpty()) {
            return 'Es lo único que hay de este producto en todo el hospital.';
        }

        $donde = $otras
            ->map(static function (Existencia $fila): string {
                $envase = self::nombreDelEnvase($fila);

                return self::cuantoHay($fila).' en '.$fila->almacen->nombre
                    .($envase === null ? '' : ' ('.$envase.')');
            })
            ->implode(' · ');

        return 'Además: '.$donde.'.';
    }

    /**
     * «ACETAMINOFEN JARABE · FRASCO X 120 ML · lote L0TE-1».
     *
     * Es la frase que confirma qué se está por mover, con las tres cosas
     * que distinguen un renglón de otro cuando el producto se repite.
     */
    private static function comoSeLlamaEsteRenglon(Existencia $existencia): string
    {
        $partes = array_filter([
            (string) $existencia->item->nombre,
            self::nombreDelEnvase($existencia),
            $existencia->lote === null ? null : 'lote '.$existencia->lote->numero,
        ]);

        return implode(' · ', $partes).' — hay '.self::cuantoHay($existencia).'.';
    }

    // ── Cómo se lee cada renglón ──────────────────────────────────────

    /**
     * La segunda línea del producto: «MED-705 · FRASCO X 120 ML».
     *
     * ⚠️ `Str::after($nombre, ' / ')` porque el nombre guardado de una
     * presentación es «PRODUCTO / FRASCO X 120 ML»: repetir el producto
     * debajo del producto ocupa el ancho sin agregar nada, y justamente
     * lo que hace falta distinguir es la mitad de atrás.
     */
    private static function comoSeEnvasa(Existencia $existencia): string
    {
        $codigo = (string) $existencia->item->codigo;
        $envase = self::nombreDelEnvase($existencia);

        return $envase === null ? $codigo : $codigo.' · '.$envase;
    }

    /**
     * «1200.00 ML». Sin la unidad, el número no significa nada.
     */
    private static function cuantoHay(Existencia $existencia): string
    {
        $cantidad = $existencia->cantidadDecimal()->redondeado(2);
        $unidad = $existencia->item->unidadDispensacion;

        return $unidad instanceof Unidad ? $cantidad.' '.$unidad->codigo : $cantidad;
    }

    /**
     * «= 10 FRASCO», o nada cuando el lote no tiene envase declarado.
     *
     * Es una LECTURA, nunca un número para escribir en el kardex: ahí
     * entran unidades de dispensación y solo eso. Convertir para mostrar
     * y convertir para guardar son dos cosas distintas, y confundirlas es
     * cómo se cobra una caja entera por dos tabletas.
     */
    private static function enCuantosEnvases(Existencia $existencia): ?string
    {
        $presentacion = $existencia->lote?->presentacion;

        if (! $presentacion instanceof ItemPresentacion) {
            return null;
        }

        $contenido = self::contenidoDelEnvase($existencia);

        if ($contenido === null) {
            return null;
        }

        $envases = $existencia->cantidadDecimal()->entre($contenido);

        return '= '.ItemPresentacion::sinCerosDeMas($envases->redondeado(2))
            .' '.(self::unidadDelEnvase($existencia) ?? 'envases');
    }

    // ── Cuánto se mueve, y en qué se cuenta ───────────────────────────

    /**
     * Cuántas unidades de dispensación son los envases que se tecleó.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 PEDIR EL MÁXIMO MUEVE EL SALDO EXACTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Un lote con 595 ML de frascos de 60 son 9.9167 frascos. Si alguien
     * escribe ese número para vaciar el estante y se multiplicara sin
     * más, quedarían 0.002 ML colgando por el redondeo — un saldo que
     * nadie puede mover ni contar, y que aparece como diferencia en el
     * conteo físico.
     *
     * Por eso, cuando lo pedido llega o pasa el tope, se manda la
     * cantidad EXACTA que hay. «Todo» significa todo.
     */
    private static function aUnidadesDeDispensacion(Existencia $existencia, Decimal $envases): Decimal
    {
        $contenido = self::contenidoDelEnvase($existencia);

        if ($contenido === null) {
            return $envases;
        }

        /*
         * ⚠️ Se compara contra el TOPE QUE SE MOSTRÓ, no contra la
         * división cruda. El tope va redondeado a cuatro decimales, así
         * que un lote de 5 ML en frascos de 60 se ofrece como «0.0833
         * FRASCO»: escribir ese número tiene que vaciar el renglón, y
         * comparando contra 0.083333… no lo vaciaría —quedarían 0.002 ML
         * colgando, un saldo que nadie puede mover ni contar y que
         * reaparece como diferencia en el conteo físico.
         *
         * Dicho de otro modo: el número que la pantalla ofrece como
         * máximo SIEMPRE significa «todo».
         */
        $tope = Decimal::de(self::topeParaMover($existencia));

        return $envases->menorQue($tope)
            ? $envases->por($contenido)
            : $existencia->cantidadDecimal();
    }

    /**
     * El contenido de un envase, o nulo cuando el lote entró a granel.
     *
     * La columna es NUMERIC(14,4) con CHECK de mayor que cero, así que en
     * la práctica siempre alcanza. La guarda existe porque dividir entre
     * cero acá tumbaría el modal por un dato mal cargado en una sola
     * presentación.
     */
    private static function contenidoDelEnvase(Existencia $existencia): ?string
    {
        $presentacion = $existencia->lote?->presentacion;

        if (! $presentacion instanceof ItemPresentacion) {
            return null;
        }

        $contenido = $presentacion->unidades_por_presentacion;

        if (! is_numeric($contenido) || Decimal::de($contenido)->esCero()) {
            return null;
        }

        return (string) $contenido;
    }

    /**
     * «Cuántos FRASCO se mueven» — o la unidad del kardex si el lote no
     * tiene envase declarado.
     */
    private static function comoSePideLaCantidad(Existencia $existencia): string
    {
        $envase = self::unidadDelEnvase($existencia);

        if ($envase !== null && self::contenidoDelEnvase($existencia) !== null) {
            return 'Cuántos '.$envase.' se mueven';
        }

        $unidad = $existencia->item->unidadDispensacion;

        return 'Cuántos '.($unidad instanceof Unidad ? $unidad->codigo : 'unidades').' se mueven';
    }

    /**
     * El tope del campo, en la misma unidad en que se pide.
     */
    private static function topeParaMover(Existencia $existencia): string
    {
        $contenido = self::contenidoDelEnvase($existencia);

        return $contenido === null
            ? $existencia->cantidadDecimal()->redondeado(4)
            : $existencia->cantidadDecimal()->entre($contenido)->redondeado(4);
    }

    /**
     * El texto de ayuda del campo, con la conversión a la vista.
     *
     * 🔴 Mostrar «5 FRASCO = 300.00 ML» ANTES de apretar es lo que evita
     * el error que ya pasó una vez: teclear 5 pensando en frascos y mover
     * 5 mililitros. Después ya está asentado en el kardex y se corrige
     * con otro traslado, no borrando.
     */
    private static function cuantoQuedaEnEsteLote(Existencia $existencia, mixed $tecleado = null): string
    {
        $hay = 'Hay '.self::cuantoHay($existencia).' en este lote';
        $envases = self::enCuantosEnvases($existencia);
        $hay .= $envases === null ? '. ' : ' ('.$envases.'). ';

        $pedido = NumeroDeFormulario::aDecimal($tecleado);

        if (! $pedido instanceof Decimal || $pedido->esCero()) {
            return $hay.'Lo que se mueve sigue siendo del hospital: no es una baja.';
        }

        $enUnidades = self::aUnidadesDeDispensacion($existencia, $pedido);
        $unidad = $existencia->item->unidadDispensacion;

        return $hay.'Se mueven '.self::comoSeCuenta($existencia, $pedido)
            .' = '.$enUnidades->redondeado(2)
            .($unidad instanceof Unidad ? ' '.$unidad->codigo : '')
            .' en el kardex.';
    }

    /**
     * «5 FRASCO», «2 CAJA», «300 ML» — lo que se movió, dicho como lo
     * cuenta quien lo levantó del estante.
     */
    private static function comoSeCuenta(Existencia $existencia, Decimal $tecleado): string
    {
        $envase = self::unidadDelEnvase($existencia);

        if ($envase !== null && self::contenidoDelEnvase($existencia) !== null) {
            return ItemPresentacion::sinCerosDeMas($tecleado->redondeado(2)).' '.$envase;
        }

        $unidad = $existencia->item->unidadDispensacion;

        return $tecleado->redondeado(2).($unidad instanceof Unidad ? ' '.$unidad->codigo : '');
    }

    /**
     * Cómo viene envasado, sin repetir el producto: «FRASCO 60 ML»,
     * «CAJA X 100 TABLETAS». Nulo cuando el lote entró a granel o el ítem
     * no maneja presentaciones.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 POR QUÉ NO ALCANZA CON CORTAR EL NOMBRE
     * ─────────────────────────────────────────────────────────────────
     *
     * El nombre guardado de una presentación es «PRODUCTO / envase», y la
     * primera versión se quedaba con la mitad de atrás. Funciona cuando
     * esa mitad dice «FRASCO X 120 ML» — pero en este catálogo dice
     * «ACETAMINOFEN JARABE 60 ML», o sea el producto OTRA VEZ. El
     * renglón quedaba «ACETAMINOFEN JARABE · MED-705 · ACETAMINOFEN
     * JARABE 60 ML»: tres veces lo mismo y el dato útil —60 ML— perdido
     * al final.
     *
     * Así que el envase se arma del dato que sí lo dice: la UNIDAD de la
     * presentación —FRASCO, CAJA, AMPOLLA—, más lo que queda del nombre
     * después de quitarle el producto. Y no se duplica la unidad cuando
     * el nombre ya arranca con ella.
     */
    private static function nombreDelEnvase(Existencia $existencia): ?string
    {
        $presentacion = $existencia->lote?->presentacion;

        if (! $presentacion instanceof ItemPresentacion) {
            return null;
        }

        $envase = trim(Str::after((string) $presentacion->nombre, ' / '));

        /*
         * ⚠️ El casteo no es adorno. Estos lectores se llaman desde
         * varias consultas, y una que cargue el ítem con columnas
         * enumeradas puede dejar `nombre` en nulo sin dar ninguna señal.
         * Con `trim(null)` eso es un TypeError y la pantalla se cae
         * entera; con la cadena vacía, el renglón simplemente no recorta
         * el prefijo. Un dato de menos, no una pantalla rota.
         */
        $producto = trim((string) $existencia->item->nombre);

        $envase = self::sinElPrefijoRepetido($envase, $producto);

        $unidad = self::unidadDelEnvase($existencia);

        /*
         * `str_contains` y no `startsWith`: hay presentaciones cargadas
         * como «TAB CAJA 20», donde el envase aparece en el medio.
         * Anteponerlo daría «TAB TAB CAJA 20».
         */
        if ($unidad !== null && $unidad !== '' && ! str_contains($envase, $unidad)) {
            $envase = trim($unidad.' '.$envase);
        }

        return $envase === '' ? $unidad : $envase;
    }

    /**
     * Le quita al envase las palabras que ya dice el nombre del producto.
     *
     * ─────────────────────────────────────────────────────────────────
     * PALABRA POR PALABRA, NO LA CADENA ENTERA
     * ─────────────────────────────────────────────────────────────────
     *
     * La primera versión pedía que el envase EMPEZARA con el nombre
     * completo del producto. Eso solo cubre el caso limpio:
     *
     *   ACETAMINOFEN JARABE  +  «ACETAMINOFEN JARABE 60 ML»  →  60 ML ✓
     *
     * y deja pasar el que de verdad aparece en el catálogo:
     *
     *   ACETAMINOFEN JARABE  +  «ACETAMINOFEN FRASCO 120 ML»  →  sin
     *   recortar, y el renglón terminaba diciendo «FRASCO ACETAMINOFEN
     *   FRASCO 120 ML».
     *
     * Comparando palabra por palabra se recorta lo que de verdad se
     * repite —ACETAMINOFEN— y queda «FRASCO 120 ML». Si no hay nada en
     * común, no se toca nada.
     */
    private static function sinElPrefijoRepetido(string $envase, string $producto): string
    {
        if ($envase === '' || $producto === '') {
            return $envase;
        }

        $delEnvase = preg_split('/\s+/', $envase) ?: [];
        $delProducto = preg_split('/\s+/', $producto) ?: [];

        $comunes = 0;

        while (
            isset($delEnvase[$comunes], $delProducto[$comunes])
            && $delEnvase[$comunes] === $delProducto[$comunes]
        ) {
            $comunes++;
        }

        /*
         * Si TODO el envase estaba repetido, se devuelve entero: es
         * preferible un renglón redundante a uno vacío, que no dice qué
         * frasco es.
         */
        $resto = trim(implode(' ', array_slice($delEnvase, $comunes)));

        return $resto === '' ? $envase : $resto;
    }

    /**
     * «FRASCO», «CAJA», «AMPOLLA» — el envase pelado, para la línea de
     * equivalencia. Es un dato del catálogo, no algo que se deduzca del
     * nombre.
     */
    private static function unidadDelEnvase(Existencia $existencia): ?string
    {
        $unidad = $existencia->lote?->presentacion?->unidad;

        return $unidad instanceof Unidad ? $unidad->codigo : null;
    }

    // ── Presentación del vencimiento ──────────────────────────────────

    private static function colorDelVencimiento(Existencia $existencia): string
    {
        $dias = $existencia->lote?->diasParaVencerDesde(now());

        if ($dias === null) {
            return 'gray';
        }

        return match (true) {
            $dias <= 30 => 'danger',
            $dias <= 90 => 'warning',
            default     => 'success',
        };
    }

    private static function cuantoLeQueda(Existencia $existencia): ?string
    {
        $dias = $existencia->lote?->diasParaVencerDesde(now());

        if ($dias === null) {
            return null;
        }

        return match (true) {
            $dias < 0   => 'Vencido hace '.abs($dias).' d',
            $dias === 0 => 'Vence hoy',
            default     => 'En '.$dias.' d',
        };
    }
}
