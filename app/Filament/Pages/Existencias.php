<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Unidad;
use App\Services\FormasDeCobro;
use App\Services\TrasladadorDeExistencias;
use App\Services\UbicadorDeProducto;
use App\Support\AlmacenesDelUsuario;
use App\Support\NumeroDeFormulario;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as ConsultaBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

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
 * ─────────────────────────────────────────────────────────────────────
 * UN RENGLÓN POR PRODUCTO (13-oct-2026, tercera vuelta)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «se arma una lista demasiado extensa cuando sean muchos
 * medicamentos». Con un renglón por lote —y por lugar, en lo infinito—
 * trescientos productos eran mil renglones. Ahora es uno por producto:
 *
 *   · DÓNDE ESTÁ: un chip por almacén, con cuánto hay en cada uno —o sin
 *     cantidad, si es infinito—.
 *   · VENCE: el lote que vence primero.
 *   · CANTIDAD: lo que hay en el almacén elegido arriba, o en todos.
 *
 * El detalle lote por lote está a un clic, en «Lotes». Y «Mover» pide
 * el lote adentro del modal: se elige de dónde sale mirando la lista,
 * con su vencimiento, en vez de buscarlo entre renglones repetidos.
 *
 * Arriba, los almacenes en el orden en que se despacha —farmacia
 * primero, «Todos» al final— y la pantalla abre en el primero (Mauricio:
 * «el primero en aparecer debe ser farmacia, todos debe estar de
 * último»).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE TIENE STOCK INFINITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * No se cuenta: no tiene lote, ni vencimiento, ni cantidad. Tiene LUGARES
 * (`UbicadorDeProducto`) y, en cada uno, QUÉ hay —cajas, blísteres, solo
 * unidades—: eso es lo que muestra su renglón. Su «Mover» dice de dónde
 * salió, a dónde llegó y qué se llevó —un envase, unidades, o todo—; y
 * «Lugares» corrige la lista entera. Los dos, con los almacenes como
 * tarjetas.
 *
 * Lo que tiene existencia se mueve diciendo en qué —caja, blíster o
 * unidades— y cuántos (13-oct-2026).
 *
 * 🔴 Si le quedó existencia de antes de esta regla, NO se muestra: un
 * infinito no tiene lotes que vencer. `sihla:ordenar-stock-infinito` la
 * da de baja en el kardex.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UN RESOURCE (§9.A10)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `Existencia` es un SALDO y se escribe únicamente desde
 * `RegistradorDeMovimiento`. Lo único que se puede hacer desde acá es
 * MOVER, que no cambia el número: lo pasa de un estante a otro asentando
 * las dos líneas.
 */
class Existencias extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Lo que se ve, en el orden del control. `por_vencer` es en los
     * próximos 90 días y sin contar lo ya vencido.
     */
    public const VISTAS = [
        'todo'        => 'Todo',
        'por_vencer'  => 'Por vencer',
        'vencido'     => 'Vencido',
        'controlados' => 'Controlados',
        'infinito'    => 'Stock infinito',
    ];

    private const DIAS_POR_VENCER = 90;

    /**
     * Las formas del «Mover» de lo que tiene existencia: unidades sueltas,
     * o un envase sin abrir —`envase:` y el id de la presentación—.
     */
    private const FORMA_SUELTA = 'suelta';

    private const FORMA_ENVASE = 'envase:';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'existencias';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.existencias';

    /**
     * `#[Locked]`: solo lo cambian `verAlmacen()` y `verTodosLosAlmacenes()`.
     * Nulo = todos.
     */
    #[Locked]
    public ?int $almacen = null;

    /**
     * `#[Locked]`: solo lo cambia `verVista()`, que además lo valida
     * contra `VISTAS`.
     */
    #[Locked]
    public string $vista = 'todo';

    /**
     * Cuántos productos tiene cada almacén. Privada: Livewire no la guarda
     * entre pedidos, y así nunca queda vieja.
     *
     * @var array<int, int>|null
     */
    private ?array $productosPorAlmacen = null;

    /**
     * Los almacenes de la sede, por id. Son pocos: se cargan una vez por
     * pintada y de ahí salen los nombres, los colores y el orden.
     *
     * @var Collection<array-key, Almacen>|null
     */
    private ?Collection $almacenesPorId = null;

    /**
     * Lo que hay de cada producto de la página, almacén por almacén. Lo
     * leen las columnas: dos consultas por pintada, no una por renglón.
     *
     * @var array{reales: array<int, list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>>, infinitos: array<int, list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>>}|null
     */
    private ?array $lugares = null;

    /**
     * Las existencias ya cargadas para el modal de mover, por id.
     *
     * @var array<int, Existencia|null>
     */
    private array $existenciasCargadas = [];

    /**
     * Dónde está cada infinito y qué hay en cada lugar (nulo, «de todo»):
     * los modales lo preguntan varias veces por pintada y se lee una.
     *
     * @var array<int, array<int, list<int>|null>>
     */
    private array $lugaresPorItem = [];

    /**
     * Los envases que se venden cerrados de cada producto, del más grande
     * al más chico.
     *
     * @var array<int, Collection<int, ItemPresentacion>>
     */
    private array $envasesPorItem = [];

    /**
     * Los lotes que se pueden mover de cada producto, con sus formas: los
     * piden el modal y su relleno en la misma pintada.
     *
     * @var array<int, list<array{id: int, almacen: int, sede: int, titulo: string, detalle: string, vence: string|null, color: string, formas: list<array{clave: string, nombre: string, detalle: string, contenido: string, max: string, entero: bool}>}>>
     */
    private array $lotesPorItem = [];

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
        return 'Qué hay de cada producto y en qué almacén. «Lotes» muestra el detalle; «Mover» lo '
            .'pasa a otro almacén sin sacarlo del inventario.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Almacen::class);
    }

    /**
     * `?almacen=` y `?vista=` se leen a mano y no con `#[Url]`: Livewire
     * asignaría el valor crudo a la propiedad tipada, y un enlace mal
     * pegado reventaría con un TypeError en vez de ignorarse.
     *
     * Sin `?almacen=`, abre en el primero de la lista —farmacia—.
     * `?almacen=todos` abre en todos.
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $vista = request()->query('vista');
        $this->vista = is_string($vista) && array_key_exists($vista, self::VISTAS) ? $vista : 'todo';

        $almacen = request()->query('almacen');

        $this->almacen = match (true) {
            $almacen === 'todos'                         => null,
            is_string($almacen) && ctype_digit($almacen) => (int) $almacen,
            default                                      => $this->almacenes()[0]['id'] ?? null,
        };
    }

    // ── Dónde ─────────────────────────────────────────────────────────

    public function verAlmacen(int $almacenId): void
    {
        $this->almacen = $almacenId;
        $this->lugares = null;

        $this->resetPage();
    }

    public function verTodosLosAlmacenes(): void
    {
        $this->almacen = null;
        $this->lugares = null;

        $this->resetPage();
    }

    /**
     * Los almacenes con algo, en el orden en que se despacha: farmacia,
     * después los carritos y stocks de servicio, bodega al final.
     *
     * @return list<array{id: int, nombre: string, productos: int, activo: bool}>
     */
    public function almacenes(): array
    {
        $productos = $this->productosPorAlmacen();
        $elegido = $this->almacenElegido();

        $lista = [];

        foreach ($this->almacenesEnOrden() as $almacen) {
            if (! isset($productos[$almacen->id])) {
                continue;
            }

            $lista[] = [
                'id'        => (int) $almacen->id,
                'nombre'    => $almacen->nombre,
                'productos' => $productos[$almacen->id],
                'activo'    => $elegido === (int) $almacen->id,
            ];
        }

        return $lista;
    }

    /**
     * Cuántos productos distintos hay en algún almacén.
     */
    public function totalDeProductos(): int
    {
        return $this->productosConAlgo(null)->count();
    }

    // ── Qué ───────────────────────────────────────────────────────────

    public function verVista(string $vista): void
    {
        if (! array_key_exists($vista, self::VISTAS)) {
            return;
        }

        $this->vista = $vista;

        $this->resetPage();
    }

    /**
     * Las vistas con cuántos productos tiene cada una en el almacén
     * elegido. Todas las cuentas salen de UNA consulta con `count(*)
     * filter (…)`.
     *
     * @return list<array{clave: string, etiqueta: string, cuantos: int, alerta: bool, activo: bool}>
     */
    public function vistas(): array
    {
        $almacen = $this->almacenElegido();
        $hoy = now()->toDateString();
        $limite = now()->addDays(self::DIAS_POR_VENCER)->toDateString();

        $enElAlmacen = $almacen === null ? '' : ' and e.almacen_id = ?';
        $delAlmacen = $almacen === null ? [] : [$almacen];

        $loteQueVence = 'not items.stock_infinito and exists (select 1 from existencias e '
            .'join lotes l on l.id = e.lote_id '
            .'where e.item_id = items.id and e.cantidad > 0'.$enElAlmacen.' and %s)';

        $fila = $this->productosConAlgo($almacen)
            ->toBase()
            ->selectRaw(
                'count(*) as todo, '
                .'count(*) filter (where '.sprintf($loteQueVence, 'l.fecha_vencimiento >= ? and l.fecha_vencimiento <= ?').') as por_vencer, '
                .'count(*) filter (where '.sprintf($loteQueVence, 'l.fecha_vencimiento < ?').') as vencido, '
                .'count(*) filter (where items.es_controlado) as controlados, '
                .'count(*) filter (where items.stock_infinito) as infinito',
                [...$delAlmacen, $hoy, $limite, ...$delAlmacen, $hoy],
            )
            ->first();

        $lista = [];

        foreach (self::VISTAS as $clave => $etiqueta) {
            $cuantos = is_object($fila) && isset($fila->{$clave}) ? (int) $fila->{$clave} : 0;

            $lista[] = [
                'clave'    => $clave,
                'etiqueta' => $etiqueta,
                'cuantos'  => $cuantos,
                'alerta'   => $cuantos > 0 && in_array($clave, ['por_vencer', 'vencido'], true),
                'activo'   => $this->vista === $clave,
            ];
        }

        return $lista;
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $tabla): Table
    {
        return $tabla
            /*
             * ⚠️ La consulta en un closure: el almacén y la vista cambian
             * con un clic, y la tabla se arma antes de que la acción
             * corra. Evaluada recién al pintar, ya ve el valor nuevo.
             */
            ->query(fn (): Builder => $this->consulta())
            ->defaultSort('nombre')
            ->striped()
            ->paginated([25, 50, 100])
            ->searchable()
            ->searchPlaceholder('Escaneá, o escribí código o nombre')
            /*
             * La misma búsqueda del catálogo (`Item::scopeBuscar`): sin
             * tildes, tolerante a errores de tecleo, y el código de barras
             * de la caja del fabricante también encuentra.
             */
            ->searchUsing(function (Builder $query, string $search): void {
                self::buscarEn($query, $search);
            })
            ->columns($this->columnas())
            ->recordActions([
                $this->lotesAction(),
                $this->lugaresAction(),
                $this->moverAction(),
                $this->moverInfinitoAction(),
            ])
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(fn (): string => match ($this->vista) {
                'por_vencer'  => 'Nada por vencer en los próximos '.self::DIAS_POR_VENCER.' días',
                'vencido'     => 'No hay nada vencido',
                'controlados' => 'No hay controlados en existencia',
                'infinito'    => 'Nada con stock infinito acá',
                default       => 'No hay nada acá',
            })
            ->emptyStateDescription(fn (): string => $this->vista === 'todo'
                ? 'Lo que entra por recepción aparece acá, y lo que tiene stock infinito en cada almacén donde está.'
                : 'Elegí «Todo» arriba para ver el resto.');
    }

    /**
     * Los productos con algo en el almacén elegido —o en cualquiera—,
     * filtrados por la vista de arriba.
     *
     * @return Builder<Item>
     */
    private function consulta(): Builder
    {
        $almacen = $this->almacenElegido();
        $consulta = $this->productosConAlgo($almacen)->with('unidadDispensacion');

        $hoy = now()->toDateString();

        if ($this->vista === 'por_vencer') {
            $this->conLoteQueVence(
                $consulta,
                $almacen,
                fn (ConsultaBase $lotes): ConsultaBase => $lotes
                    ->whereDate('lotes.fecha_vencimiento', '>=', $hoy)
                    ->whereDate('lotes.fecha_vencimiento', '<=', now()->addDays(self::DIAS_POR_VENCER)->toDateString()),
            );
        } elseif ($this->vista === 'vencido') {
            $this->conLoteQueVence(
                $consulta,
                $almacen,
                fn (ConsultaBase $lotes): ConsultaBase => $lotes->whereDate('lotes.fecha_vencimiento', '<', $hoy),
            );
        } elseif ($this->vista === 'controlados') {
            $consulta->where('items.es_controlado', true);
        } elseif ($this->vista === 'infinito') {
            $consulta->where('items.stock_infinito', true);
        }

        return $consulta;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * QUÉ PRODUCTOS TIENEN ALGO, Y DÓNDE
     * ─────────────────────────────────────────────────────────────────
     *
     *   · Lo que lleva existencia real: si tiene saldo en el almacén.
     *   · Lo que es infinito: si tiene un lugar abierto en el almacén. Su
     *     existencia vieja —si le quedó— no cuenta: un infinito no tiene
     *     lotes.
     *
     * @return Builder<Item>
     */
    private function productosConAlgo(?int $almacen): Builder
    {
        return Item::query()
            ->where('items.se_almacena', true)
            ->where(function (Builder $consulta) use ($almacen): void {
                $consulta
                    ->where(function (Builder $real) use ($almacen): void {
                        $real->where('items.stock_infinito', false)
                            ->whereExists(function (ConsultaBase $existencias) use ($almacen): void {
                                $existencias->selectRaw('1')
                                    ->from('existencias')
                                    ->whereColumn('existencias.item_id', 'items.id')
                                    ->where('existencias.cantidad', '>', 0)
                                    ->when($almacen !== null, fn (ConsultaBase $sub): ConsultaBase => $sub
                                        ->where('existencias.almacen_id', $almacen));
                            });
                    })
                    ->orWhere(function (Builder $infinito) use ($almacen): void {
                        $infinito->where('items.stock_infinito', true)
                            ->whereExists(function (ConsultaBase $lugares) use ($almacen): void {
                                $lugares->selectRaw('1')
                                    ->from('ubicaciones_de_producto')
                                    ->whereColumn('ubicaciones_de_producto.item_id', 'items.id')
                                    ->whereNull('ubicaciones_de_producto.hasta')
                                    ->when($almacen !== null, fn (ConsultaBase $sub): ConsultaBase => $sub
                                        ->where('ubicaciones_de_producto.almacen_id', $almacen));
                            });
                    });
            });
    }

    /**
     * @param Builder<Item> $consulta
     * @param callable(ConsultaBase): ConsultaBase $condicion
     */
    private function conLoteQueVence(Builder $consulta, ?int $almacen, callable $condicion): void
    {
        $consulta
            ->where('items.stock_infinito', false)
            ->whereExists(function (ConsultaBase $existencias) use ($almacen, $condicion): void {
                $existencias->selectRaw('1')
                    ->from('existencias')
                    ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
                    ->whereColumn('existencias.item_id', 'items.id')
                    ->where('existencias.cantidad', '>', 0)
                    ->when($almacen !== null, fn (ConsultaBase $sub): ConsultaBase => $sub
                        ->where('existencias.almacen_id', $almacen));

                $condicion($existencias);
            });
    }

    /**
     * Cuántos productos distintos tiene cada almacén: existencia real con
     * saldo, o lugar abierto de un infinito. Una consulta.
     *
     * @return array<int, int>
     */
    private function productosPorAlmacen(): array
    {
        if ($this->productosPorAlmacen !== null) {
            return $this->productosPorAlmacen;
        }

        $lugares = DB::table('ubicaciones_de_producto')
            ->join('items', 'items.id', '=', 'ubicaciones_de_producto.item_id')
            ->whereNull('ubicaciones_de_producto.hasta')
            ->where('items.stock_infinito', true)
            ->select(['ubicaciones_de_producto.almacen_id', 'ubicaciones_de_producto.item_id']);

        $todo = DB::table('existencias')
            ->join('items', 'items.id', '=', 'existencias.item_id')
            ->where('existencias.cantidad', '>', 0)
            ->where('items.stock_infinito', false)
            ->where('items.se_almacena', true)
            ->select(['existencias.almacen_id', 'existencias.item_id'])
            ->union($lugares);

        $cuentas = [];

        foreach (DB::query()
            ->fromSub($todo, 'lugares')
            ->selectRaw('almacen_id, count(distinct item_id) as cuantos')
            ->groupBy('almacen_id')
            ->get() as $fila) {
            $cuentas[(int) $fila->almacen_id] = (int) $fila->cuantos;
        }

        return $this->productosPorAlmacen = $cuentas;
    }

    /**
     * El almacén elegido, si todavía tiene algo. Si se vació, se ven
     * todos: si no, la tabla quedaría vacía sin nada arriba que explique
     * por qué.
     */
    private function almacenElegido(): ?int
    {
        return $this->almacen !== null && array_key_exists($this->almacen, $this->productosPorAlmacen())
            ? $this->almacen
            : null;
    }

    /**
     * @return Collection<array-key, Almacen>
     */
    private function almacenesPorId(): Collection
    {
        return $this->almacenesPorId ??= Almacen::query()->get()->keyBy('id');
    }

    /**
     * Farmacia primero, después los carritos y stocks de servicio, bodega
     * al final; y por nombre entre iguales.
     *
     * @return Collection<int, Almacen>
     */
    private function almacenesEnOrden(): Collection
    {
        return $this->almacenesPorId()
            ->sortBy(fn (Almacen $almacen): string => self::ordenDelTipo($almacen->tipo).' '.$almacen->nombre)
            ->values();
    }

    private static function ordenDelTipo(TipoAlmacen $tipo): int
    {
        return match ($tipo) {
            TipoAlmacen::FarmaciaVenta, TipoAlmacen::AlmacenUnico => 0,
            TipoAlmacen::FarmaciaInterna                          => 1,
            TipoAlmacen::StockDeServicio                          => 2,
            TipoAlmacen::BodegaCentral                            => 3,
        };
    }

    // ── Las columnas ──────────────────────────────────────────────────

    /**
     * @return list<Column>
     */
    private function columnas(): array
    {
        return [
            /*
             * 🔴 SOLO EL NOMBRE CRECE: con dos o más columnas creciendo,
             * Filament reparte el ancho y deja huecos entre las cortas.
             */
            TextColumn::make('nombre')
                ->label('Producto')
                ->sortable()
                ->weight('medium')
                ->wrap()
                ->description(fn (Item $record): string => (string) $record->codigo),

            /*
             * ─────────────────────────────────────────────────────────
             * UN RENGLÓN POR ALMACÉN, NO UN CHIP LARGO (13-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Con las presentaciones adentro, «FARMACIA · CAJA X 100,
             * BLISTER X 10, TAB» tres veces seguidas desbordaba la tabla
             * hacia la derecha (Mauricio: «se ve feo»). Ahora es una lista
             * corta: el almacén a la izquierda y, a la derecha, cuánto hay
             * —lo real— o qué hay —lo infinito, con etiquetas chicas:
             * CAJA, BLISTER, TAB—. El almacén elegido arriba, resaltado.
             * En el orden en que se despacha
             * (`filament.tables.columns.donde-esta`).
             */
            ViewColumn::make('donde')
                ->label('Dónde está')
                ->state(fn (Item $record): array => $this->dondeEstaDe($record))
                ->view('filament.tables.columns.donde-esta')
                ->grow(false),

            /*
             * El lote que vence primero. Lo infinito no tiene: la columna
             * ni se dibuja en su vista.
             */
            TextColumn::make('vence')
                ->label('Vence')
                ->state(fn (Item $record): ?string => $this->venceDe($record))
                ->date('d/m/Y')
                ->badge()
                ->placeholder('—')
                ->color(fn (Item $record): string => self::colorPorDias(self::diasHasta($this->venceDe($record))))
                ->description(fn (Item $record): ?string => self::textoPorDias(self::diasHasta($this->venceDe($record))))
                ->visible(fn (): bool => $this->vista !== 'infinito')
                ->grow(false),

            /*
             * Cuánto hay en el almacén elegido, o en todos. «1200» a secas
             * no dice nada: va con la unidad del kardex.
             */
            TextColumn::make('cantidad')
                ->label(fn (): string => $this->almacenElegido() === null ? 'Total' : 'Aquí')
                ->state(fn (Item $record): string => $this->cantidadDe($record))
                ->description(fn (Item $record): string => $this->detalleDeLaCantidad($record))
                ->alignEnd()
                ->weight('bold')
                ->grow(false),
        ];
    }

    /**
     * Los almacenes donde está cada producto de la página. Dos consultas
     * —saldos de lo real, lugares de lo infinito— para toda la página.
     *
     * @return array{reales: array<int, list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>>, infinitos: array<int, list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>>}
     */
    private function lugaresDeLaPagina(): array
    {
        if ($this->lugares !== null) {
            return $this->lugares;
        }

        /*
         * Los de la página que se está pintando. `getTableRecords()` ya
         * los tiene en memoria: no es otra consulta. Puede venir como
         * colección o como paginador, y los dos saben dar `items()`… salvo
         * la colección, que se recorre directo.
         */
        $registros = $this->getTableRecords();
        $lista = $registros instanceof Collection ? $registros->all() : $registros->items();

        $ids = [];
        $infinitos = [];

        foreach ($lista as $registro) {
            if ($registro instanceof Item) {
                $ids[] = (int) $registro->id;

                if ($registro->tieneStockInfinito()) {
                    $infinitos[(int) $registro->id] = $registro;
                }
            }
        }

        $reales = [];
        $lugaresInfinitos = [];

        if ($ids !== []) {
            $saldos = Existencia::query()
                ->leftJoin('lotes', 'lotes.id', '=', 'existencias.lote_id')
                ->whereIn('existencias.item_id', $ids)
                ->where('existencias.cantidad', '>', 0)
                ->selectRaw(
                    'existencias.item_id, existencias.almacen_id, sum(existencias.cantidad) as cantidad, '
                    .'count(*) as lotes, min(lotes.fecha_vencimiento) as vence'
                )
                ->groupBy('existencias.item_id', 'existencias.almacen_id')
                ->toBase()
                ->get();

            foreach ($saldos as $fila) {
                $reales[(int) $fila->item_id][] = [
                    'almacen'  => (int) $fila->almacen_id,
                    'cantidad' => Decimal::de(is_numeric($fila->cantidad) ? (string) $fila->cantidad : '0'),
                    'lotes'    => (int) $fila->lotes,
                    'vence'    => is_string($fila->vence) ? mb_substr($fila->vence, 0, 10) : null,
                ];
            }
        }

        /*
         * Lo infinito: sus lugares y qué hay en cada uno, en dos consultas
         * más para toda la página. Quedan en la memoria de la columna y de
         * los modales: abrir uno no vuelve a preguntar.
         */
        if ($infinitos !== []) {
            $ubicador = app(UbicadorDeProducto::class);
            $deCadaUno = $ubicador->lugaresDeVarios(array_keys($infinitos));
            $envases = $ubicador->envasesDeVarios(array_keys($infinitos));

            foreach (array_keys($infinitos) as $itemId) {
                $this->lugaresPorItem[$itemId] = $deCadaUno[$itemId] ?? [];
                $this->envasesPorItem[$itemId] = $envases[$itemId] ?? new Collection;

                foreach (array_keys($this->lugaresPorItem[$itemId]) as $almacenId) {
                    $lugaresInfinitos[$itemId][] = [
                        'almacen'  => $almacenId,
                        'cantidad' => null,
                        'lotes'    => 0,
                        'vence'    => null,
                    ];
                }
            }
        }

        return $this->lugares = ['reales' => $reales, 'infinitos' => $lugaresInfinitos];
    }

    /**
     * Los lugares del producto, en el orden de los almacenes de arriba.
     *
     * @return list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>
     */
    private function lugaresDe(Item $item): array
    {
        $todos = $this->lugaresDeLaPagina();

        /*
         * Un infinito muestra sus lugares, nunca su existencia vieja: no
         * tiene lotes que vencer.
         */
        $lugares = $item->tieneStockInfinito()
            ? ($todos['infinitos'][$item->id] ?? [])
            : ($todos['reales'][$item->id] ?? []);

        $orden = $this->almacenesEnOrden()->pluck('id')->flip()->all();

        usort($lugares, fn (array $uno, array $otro): int => ($orden[$uno['almacen']] ?? PHP_INT_MAX) <=> ($orden[$otro['almacen']] ?? PHP_INT_MAX));

        return $lugares;
    }

    /**
     * Solo lo del almacén elegido, o todo.
     *
     * @return list<array{almacen: int, cantidad: Decimal|null, lotes: int, vence: string|null}>
     */
    private function lugaresAContar(Item $item): array
    {
        $almacen = $this->almacenElegido();

        return array_values(array_filter(
            $this->lugaresDe($item),
            fn (array $lugar): bool => $almacen === null || $lugar['almacen'] === $almacen,
        ));
    }

    /**
     * La columna «Dónde está»: un renglón por almacén con su nombre y,
     * según el producto, cuánto hay —«458 TAB»— o qué hay —las
     * presentaciones del infinito, cortas: CAJA, BLISTER, TAB—.
     *
     * @return list<array{nombre: string, elegido: bool, cantidad: string|null, envases: list<array{corto: string, largo: string}>, sueltas: string|null}>
     */
    private function dondeEstaDe(Item $item): array
    {
        $elegido = $this->almacenElegido();
        $infinito = $item->tieneStockInfinito();
        $envases = $infinito ? $this->envasesDelProducto($item) : new Collection;
        $cortos = self::cortosDe($envases);
        $lugares = $infinito ? $this->lugaresDelInfinito($item) : [];
        $ubicador = app(UbicadorDeProducto::class);
        $filas = [];

        foreach ($this->lugaresDe($item) as $lugar) {
            $almacen = $this->almacenesPorId()->get($lugar['almacen']);
            $presentaciones = [];

            if ($infinito) {
                foreach ($ubicador->disponibles($lugares[$lugar['almacen']] ?? null, $envases) as $id) {
                    $envase = $envases->firstWhere('id', $id);

                    if ($envase instanceof ItemPresentacion) {
                        $presentaciones[] = [
                            'corto' => $cortos[$id] ?? UbicadorDeProducto::comoSeLlama($envase),
                            'largo' => UbicadorDeProducto::comoSeLlama($envase),
                        ];
                    }
                }
            }

            $filas[] = [
                'nombre'   => $almacen instanceof Almacen ? $almacen->nombre : 'OTRA SEDE',
                'elegido'  => $lugar['almacen'] === $elegido,
                'cantidad' => $lugar['cantidad'] instanceof Decimal ? self::conUnidad($lugar['cantidad'], $item) : null,
                'envases'  => $presentaciones,
                'sueltas'  => $infinito ? self::codigoDeLaUnidad($item) : null,
            ];
        }

        return $filas;
    }

    /**
     * El nombre corto de cada envase: su unidad —CAJA, BLISTER—. Si dos
     * envases del producto tienen la misma unidad —la caja de 30 y la de
     * 100—, el nombre entero, para que no se confundan.
     *
     * @param Collection<int, ItemPresentacion> $envases
     *
     * @return array<int, string>
     */
    private static function cortosDe(Collection $envases): array
    {
        $codigos = [];

        foreach ($envases as $envase) {
            $unidad = $envase->unidad;
            $codigos[(int) $envase->id] = $unidad instanceof Unidad ? $unidad->codigo : $envase->envase();
        }

        $veces = array_count_values($codigos);

        foreach ($envases as $envase) {
            if (($veces[$codigos[(int) $envase->id]] ?? 0) > 1) {
                $codigos[(int) $envase->id] = UbicadorDeProducto::comoSeLlama($envase);
            }
        }

        return $codigos;
    }

    private function venceDe(Item $item): ?string
    {
        $fechas = array_filter(array_column($this->lugaresAContar($item), 'vence'));

        return $fechas === [] ? null : min($fechas);
    }

    private function cantidadDe(Item $item): string
    {
        if ($item->tieneStockInfinito()) {
            return '∞';
        }

        $total = Decimal::cero();

        foreach ($this->lugaresAContar($item) as $lugar) {
            if ($lugar['cantidad'] instanceof Decimal) {
                $total = $total->sumar($lugar['cantidad']);
            }
        }

        return self::conUnidad($total, $item);
    }

    private function detalleDeLaCantidad(Item $item): string
    {
        if ($item->tieneStockInfinito()) {
            return 'Stock infinito';
        }

        $lotes = array_sum(array_column($this->lugaresAContar($item), 'lotes'));

        return $lotes === 1 ? '1 lote' : $lotes.' lotes';
    }

    /**
     * «458 TAB»: sin ceros de más y con la unidad del kardex.
     */
    private static function conUnidad(Decimal $cantidad, Item $item): string
    {
        $numero = ItemPresentacion::sinCerosDeMas($cantidad->redondeado(2));
        $unidad = $item->unidadDispensacion;

        return $unidad instanceof Unidad ? $numero.' '.$unidad->codigo : $numero;
    }

    /**
     * La búsqueda del catálogo. En un método aparte y no en el closure:
     * ahí el analizador no sabe que la consulta es de `Item` y no ve el
     * `scopeBuscar` (la misma vuelta que da `ItemsTable`).
     *
     * @param Builder<Item> $consulta
     */
    private static function buscarEn(Builder $consulta, string $termino): void
    {
        $consulta->buscar($termino);
    }

    // ── Lotes ─────────────────────────────────────────────────────────

    /**
     * El detalle lote por lote de un producto que lleva existencia: dónde,
     * qué envase, cuándo vence y cuánto. Solo para leer; para mover está
     * el botón de al lado.
     */
    private function lotesAction(): Action
    {
        return Action::make('lotes')
            ->label('Lotes')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->link()
            ->modalHeading(fn (Item $record): string => $record->nombre)
            ->modalDescription('Lote por lote, lo que vence primero arriba.')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->visible(fn (Item $record): bool => ! $record->tieneStockInfinito())
            ->modalContent(fn (Item $record): View => view('filament.pages.partials.lotes-del-producto', [
                'lotes' => $this->lotesDe($record),
            ]));
    }

    /**
     * @return list<array{almacen: string, lote: string, envase: string|null, vence: string|null, color: string, cuanto: string|null, cantidad: string, enEnvases: string|null}>
     */
    private function lotesDe(Item $item): array
    {
        $orden = $this->almacenesEnOrden()->pluck('id')->flip()->all();

        return array_values(Existencia::query()
            ->with(['almacen', 'item.unidadDispensacion', 'lote.presentacion.unidad'])
            ->where('item_id', $item->id)
            ->conSaldo()
            ->get()
            ->sortBy(fn (Existencia $fila): string => sprintf(
                '%05d %s',
                $orden[$fila->almacen_id] ?? 99999,
                $fila->lote?->fecha_vencimiento?->format('Y-m-d') ?? '9999-12-31',
            ))
            ->map(fn (Existencia $fila): array => [
                'almacen'   => $fila->almacen instanceof Almacen ? $fila->almacen->nombre : 'OTRA SEDE',
                'lote'      => $fila->lote instanceof Lote ? $fila->lote->numero : 'Sin lote',
                'envase'    => self::nombreDelEnvase($fila),
                'vence'     => $fila->lote?->fecha_vencimiento?->format('d/m/Y'),
                'color'     => self::colorDelVencimiento($fila),
                'cuanto'    => self::cuantoLeQueda($fila),
                'cantidad'  => self::cuantoHay($fila),
                'enEnvases' => self::enCuantosEnvases($fila),
            ])
            ->all());
    }

    // ── Mover lo que tiene existencia ─────────────────────────────────

    /**
     * El botón que contesta «de dónde sale, a dónde va, en qué y cuánto».
     *
     * ─────────────────────────────────────────────────────────────────
     * EN QUÉ SE LLEVA: CAJA, BLÍSTER O UNIDADES (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «cuando sea real, debe ingresar qué cantidad se está
     * moviendo, y en qué presentación: caja o blíster». Al carrito no se
     * bajan «20 tabletas»: se bajan dos blísteres de la caja abierta. Así
     * que, después del lote y del destino, se elige en qué se lleva —los
     * envases que hay ENTEROS en ese lote, o unidades sueltas— y cuántos.
     * El sistema convierte a la unidad del kardex y lo dice antes de
     * apretar (`filament.forms.mover-existencia`).
     *
     * Los envases que se ofrecen son los que salen sin abrir de ese lote,
     * con la misma cuenta que al cobrar: de un lote de cajas con 95
     * tabletas salen 9 blísteres y ninguna caja
     * (`FormasDeCobro::sinAbrirEnElLote()`). Lo que se movió queda escrito
     * en las dos líneas del kardex: «2 BLISTER X 10».
     *
     * Solo se ofrecen los lotes de los estantes que esa persona opera:
     * farmacia ve lo de bodega en «Lotes», pero no puede sacarle nada. El
     * permiso es el de ajustar: un traslado toca el kardex igual, aunque no
     * cambie el total del hospital.
     */
    private function moverAction(): Action
    {
        return Action::make('mover')
            ->label('Mover')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->button()
            ->outlined()
            ->color('primary')
            ->modalIcon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(fn (Item $record): string => 'Mover '.$record->nombre)
            ->modalDescription('Pasa de un almacén a otro: sigue siendo del hospital, no es una baja.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Mover')
            ->visible(fn (Item $record): bool => ! $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class)
                && $this->tieneAlgoQuePuedaMover($record))
            ->fillForm(function (Item $record): array {
                $lote = $this->loteSugerido($record);

                return [
                    'traslado' => [
                        'existencia' => $lote,
                        'destino'    => null,
                        'forma'      => $this->formaSugerida($record, $lote),
                        'cantidad'   => null,
                    ],
                ];
            })
            ->schema(fn (Item $record): array => [
                ViewField::make('traslado')
                    ->hiddenLabel()
                    ->view('filament.forms.mover-existencia')
                    ->viewData([
                        'lotes'    => $this->lotesParaMover($record),
                        'destinos' => $this->tarjetasDeAlmacenes(),
                        'yaHay'    => $this->cuantoHayEnCadaAlmacen($record),
                    ]),

                CampoMayusculas::area('motivo')
                    ->label('Nota (opcional)')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Reposición del carro, préstamo a quirófano…')
                    ->helperText('Queda escrita en las dos líneas del kardex, junto con lo que se movió.'),
            ])
            ->action(function (array $data, Item $record, Action $action): void {
                $traslado = is_array($data['traslado'] ?? null) ? $data['traslado'] : [];
                $nota = is_string($data['motivo'] ?? null) ? $data['motivo'] : null;

                if (! $this->trasladar($record, $traslado, $nota)) {
                    $action->halt();
                }
            });
    }

    /**
     * Los lotes que esta persona puede mover, en el orden de los
     * almacenes de arriba y, dentro de cada uno, lo que vence primero;
     * cada uno con las formas en que se puede llevar.
     *
     * @return list<array{id: int, almacen: int, sede: int, titulo: string, detalle: string, vence: string|null, color: string, formas: list<array{clave: string, nombre: string, detalle: string, contenido: string, max: string, entero: bool}>}>
     */
    private function lotesParaMover(Item $producto): array
    {
        if (isset($this->lotesPorItem[(int) $producto->id])) {
            return $this->lotesPorItem[(int) $producto->id];
        }

        $orden = $this->almacenesEnOrden()->pluck('id')->flip()->all();
        $envases = $this->envasesDelProducto($producto);

        $filas = Existencia::query()
            ->with(['almacen', 'item.unidadDispensacion', 'lote.presentacion.unidad'])
            ->where('item_id', $producto->id)
            ->conSaldo()
            ->get()
            ->filter(fn (Existencia $fila): bool => $fila->almacen instanceof Almacen
                && AlmacenesDelUsuario::puedeOperarEn($fila->almacen))
            ->sortBy(fn (Existencia $fila): string => sprintf(
                '%05d %s',
                $orden[$fila->almacen_id] ?? 99999,
                $fila->lote?->fecha_vencimiento?->format('Y-m-d') ?? '9999-12-31',
            ));

        $lotes = [];

        foreach ($filas as $fila) {
            $this->existenciasCargadas[(int) $fila->id] = $fila;

            $vence = $fila->lote?->fecha_vencimiento;

            $lotes[] = [
                'id'      => (int) $fila->id,
                'almacen' => (int) $fila->almacen_id,
                'sede'    => (int) $fila->almacen->sede_id,
                'titulo'  => $fila->almacen->nombre.' · '.($fila->lote instanceof Lote ? $fila->lote->numero : 'sin lote'),
                'detalle' => implode(' · ', array_filter([
                    self::nombreDelEnvase($fila),
                    'hay '.self::cuantoHay($fila),
                ])),
                'vence'  => $vence === null ? null : 'vence '.$vence->format('d/m/Y'),
                'color'  => self::colorDelVencimiento($fila),
                'formas' => $this->formasDelLote($fila, $envases),
            ];
        }

        return $this->lotesPorItem[(int) $producto->id] = $lotes;
    }

    /**
     * En qué se puede llevar lo de un lote: cada envase que sale ENTERO
     * de él —del más grande al más chico— y, siempre, unidades sueltas.
     *
     * @param Collection<int, ItemPresentacion> $envases los del producto
     *
     * @return list<array{clave: string, nombre: string, detalle: string, contenido: string, max: string, entero: bool}>
     */
    private function formasDelLote(Existencia $existencia, Collection $envases): array
    {
        $saldo = $existencia->cantidadDecimal();
        $formas = app(FormasDeCobro::class);
        $lista = [];

        foreach ($envases as $envase) {
            $sinAbrir = $formas->sinAbrirEnElLote($envase, $existencia->lote?->item_presentacion_id, $saldo, $envases);

            if (! $sinAbrir->mayorQue('0')) {
                continue;
            }

            $contenido = Decimal::de($envase->unidades_por_presentacion);
            $cuantos = $sinAbrir->entre($contenido)->redondeado(0);

            $lista[] = [
                'clave'     => self::FORMA_ENVASE.$envase->id,
                'nombre'    => UbicadorDeProducto::comoSeLlama($envase),
                'detalle'   => 'hay '.$cuantos.' sin abrir',
                'contenido' => ItemPresentacion::sinCerosDeMas($contenido->redondeado(4)),
                'max'       => $cuantos,
                'entero'    => true,
            ];
        }

        $unidad = $existencia->item->unidadDispensacion;
        $hay = ItemPresentacion::sinCerosDeMas($saldo->redondeado(4));

        $lista[] = [
            'clave'     => self::FORMA_SUELTA,
            'nombre'    => $unidad instanceof Unidad ? $unidad->codigo : 'UNIDADES',
            'detalle'   => 'sueltas · hay '.$hay,
            'contenido' => '1',
            'max'       => $hay,
            'entero'    => false,
        ];

        return $lista;
    }

    /**
     * El lote que el modal trae elegido: el primero del almacén de arriba,
     * o el primero de la lista.
     */
    private function loteSugerido(Item $producto): ?int
    {
        $lotes = $this->lotesParaMover($producto);
        $almacen = $this->almacenElegido();

        foreach ($lotes as $lote) {
            if ($almacen !== null && $lote['almacen'] === $almacen) {
                return $lote['id'];
            }
        }

        return $lotes[0]['id'] ?? null;
    }

    /**
     * La forma que trae elegida: el envase más grande que hay entero en
     * ese lote, o unidades sueltas.
     */
    private function formaSugerida(Item $producto, ?int $lote): ?string
    {
        foreach ($this->lotesParaMover($producto) as $fila) {
            if ($fila['id'] === $lote) {
                return $fila['formas'][0]['clave'] ?? null;
            }
        }

        return null;
    }

    /**
     * «Ya hay 30 TAB» en cada almacén, para la tarjeta del destino. Sale
     * de lo que ya se leyó para la página.
     *
     * @return array<int, string>
     */
    private function cuantoHayEnCadaAlmacen(Item $producto): array
    {
        $hay = [];

        foreach ($this->lugaresDe($producto) as $lugar) {
            if ($lugar['cantidad'] instanceof Decimal) {
                $hay[$lugar['almacen']] = 'ya hay '.self::conUnidad($lugar['cantidad'], $producto);
            }
        }

        return $hay;
    }

    /**
     * ¿Tiene algún lote en un estante que esta persona opera? Sale de lo
     * que ya se leyó para la página: no cuesta una consulta por renglón.
     */
    private function tieneAlgoQuePuedaMover(Item $producto): bool
    {
        foreach ($this->lugaresDe($producto) as $lugar) {
            $almacen = $this->almacenesPorId()->get($lugar['almacen']);

            if ($almacen instanceof Almacen && AlmacenesDelUsuario::puedeOperarEn($almacen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La existencia que eligió el modal, cargada una vez por pedido.
     */
    private function existenciaElegida(mixed $id): ?Existencia
    {
        if (! is_numeric($id)) {
            return null;
        }

        $clave = (int) $id;

        if (! array_key_exists($clave, $this->existenciasCargadas)) {
            $this->existenciasCargadas[$clave] = Existencia::query()
                ->with(['almacen', 'item.unidadDispensacion', 'lote.presentacion.unidad'])
                ->whereKey($clave)
                ->conSaldo()
                ->first();
        }

        return $this->existenciasCargadas[$clave];
    }

    /**
     * @param array<array-key, mixed> $traslado lote, destino, forma y cantidad
     */
    private function trasladar(Item $producto, array $traslado, ?string $nota): bool
    {
        /*
         * 🔴 SE VUELVE A PREGUNTAR ACÁ, aunque el botón ya esté escondido
         * para quien no puede. `visible()` decide qué se DIBUJA, y lo que
         * se dibuja lo controla el navegador: una llamada de Livewire
         * armada a mano llega igual. La autorización tiene que estar
         * donde se escribe, no donde se pinta.
         */
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $existencia = $this->existenciaElegida($traslado['existencia'] ?? null);

        /*
         * 🔴 El lote llega del navegador: tiene que ser de ESTE producto y
         * de un estante que esta persona opere.
         */
        if (! $existencia instanceof Existencia
            || $existencia->item_id !== $producto->id
            || ! $existencia->almacen instanceof Almacen
            || ! AlmacenesDelUsuario::puedeOperarEn($existencia->almacen)) {
            return self::noSeMovio('Elija uno de los lotes de la lista.');
        }

        $destino = is_numeric($traslado['destino'] ?? null)
            ? $this->almacenesPorId()->get((int) $traslado['destino'])
            : null;

        if (! $destino instanceof Almacen) {
            return self::noSeMovio('Elija a qué almacén entra.');
        }

        /*
         * La forma se busca entre las que se calculan ACÁ, no entre las
         * que mandó el navegador: el contenido y el tope salen del lote,
         * con su saldo de ahora.
         */
        $forma = null;

        foreach ($this->formasDelLote($existencia, $this->envasesDelProducto($producto)) as $candidata) {
            if ($candidata['clave'] === ($traslado['forma'] ?? null)) {
                $forma = $candidata;
            }
        }

        if ($forma === null) {
            return self::noSeMovio('Elija en qué se lleva: el envase o unidades sueltas.');
        }

        $cantidad = NumeroDeFormulario::aDecimal($traslado['cantidad'] ?? null);

        if (! $cantidad instanceof Decimal || ! $cantidad->mayorQue('0')) {
            return self::noSeMovio('Escriba cuántos '.$forma['nombre'].' se mueven. Ejemplo: 2');
        }

        $tope = Decimal::de($forma['max']);

        if ($cantidad->mayorQue($tope)) {
            return self::noSeMovio('En este lote hay '.$forma['max'].' '.$forma['nombre'].($forma['entero'] ? ' sin abrir.' : '.'));
        }

        if ($forma['entero'] && ! $cantidad->igualA($cantidad->redondeado(0))) {
            return self::noSeMovio(
                'Un '.$forma['nombre'].' sin abrir se mueve entero. Para mover una parte, elija unidades sueltas.'
            );
        }

        /*
         * 🔴 Todo lo suelto mueve el saldo EXACTO: el tope que se mostró
         * significa «todo», sin restos de redondeo colgando en el estante.
         */
        $unidades = match (true) {
            $forma['entero']         => $cantidad->por($forma['contenido']),
            $cantidad->igualA($tope) => $existencia->cantidadDecimal(),
            default                  => $cantidad,
        };

        $loQueSeMovio = ItemPresentacion::sinCerosDeMas($cantidad->redondeado(4)).' '.$forma['nombre'];
        $limpia = trim($nota ?? '');

        $lote = $existencia->lote_id === null ? null : Lote::query()->find($existencia->lote_id);

        try {
            app(TrasladadorDeExistencias::class)->trasladar(
                item: $producto,
                lote: $lote instanceof Lote ? $lote : null,
                origen: $existencia->almacen,
                destino: $destino,
                cantidad: $unidades,
                motivo: $limpia === '' ? $loQueSeMovio : $loQueSeMovio.' · '.$limpia,
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

        /*
         * Las cuentas de arriba se leyeron antes de mover —para encontrar
         * el renglón—; se vuelven a leer al pintar.
         */
        $this->olvidarLoLeido();

        $enKardex = $forma['entero'] ? ' ('.self::conUnidad($unidades, $producto).')' : '';

        Notification::make()
            ->title('Movido')
            ->body($loQueSeMovio.$enKardex.' de '.$producto->nombre.' pasaron de '
                .$existencia->almacen->nombre.' a '.$destino->nombre.'.')
            ->success()
            ->send();

        return true;
    }

    private static function noSeMovio(string $porQue): bool
    {
        Notification::make()
            ->title('No se pudo mover')
            ->body($porQue)
            ->danger()
            ->send();

        return false;
    }

    // ── Lo que tiene stock infinito ───────────────────────────────────

    /**
     * El «Mover» de un producto con stock infinito: de qué almacén sale, a
     * cuál entra y qué se llevó —un envase, unidades sueltas, o todo—.
     *
     * No pasa una cantidad —no hay—: anota que ahora también hay en el
     * destino, con lo que se llevó, y, si se llevó todo, que en el origen
     * ya no.
     *
     * ─────────────────────────────────────────────────────────────────
     * QUÉ SE LLEVÓ (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «aunque sean infinitos, hay que tenerlo en cuenta: puede
     * que solo haya blíster, o cajas, o unidades». Se ofrecen los envases
     * que HAY en el origen —con caja, también el blíster que trae— y las
     * unidades sueltas. Llevar al carrito un blíster deja anotado que en
     * el carrito hay blísteres, no cajas.
     *
     * Con tarjetas, como «Lugares», en tres pasos que se leen de arriba
     * abajo, y al pie cómo queda (`filament.forms.mover-infinito`).
     */
    private function moverInfinitoAction(): Action
    {
        return Action::make('moverInfinito')
            ->label('Mover')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->button()
            ->outlined()
            ->color('primary')
            ->modalIcon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(fn (Item $record): string => 'Mover '.$record->nombre)
            ->modalDescription('Stock infinito: no se cuenta, se anota de dónde salió, a dónde llegó y qué se llevó.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Mover')
            ->visible(fn (Item $record): bool => $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class)
                && $this->tieneAlgoQuePuedaMover($record))
            ->fillForm(fn (Item $record): array => [
                'movimiento' => [
                    'origen'  => $this->origenSugerido($record),
                    'destino' => null,
                    /*
                     * Sin envases no hay nada que preguntar: lo que se
                     * lleva son unidades sueltas.
                     */
                    'envase' => $this->envasesDelProducto($record)->isEmpty() ? 0 : null,
                    'todo'   => false,
                ],
            ])
            ->schema(fn (Item $record): array => [
                ViewField::make('movimiento')
                    ->hiddenLabel()
                    ->view('filament.forms.mover-infinito')
                    ->viewData([
                        'origenes'    => $this->origenesDelInfinito($record),
                        'destinos'    => $this->tarjetasDeAlmacenes(),
                        'donde'       => $this->dondeEstaElInfinito($record),
                        'envases'     => $this->tarjetasDeEnvases($record),
                        'disponibles' => $this->disponiblesDelInfinito($record),
                        'unidad'      => self::codigoDeLaUnidad($record),
                    ]),
            ])
            ->action(function (array $data, Item $record, Action $action): void {
                $movimiento = is_array($data['movimiento'] ?? null) ? $data['movimiento'] : [];

                if (! $this->moverInfinito($record, $movimiento)) {
                    $action->halt();
                }
            });
    }

    /**
     * «Lugares»: la lista entera de dónde hay y qué hay en cada lugar, para
     * corregir de una vez —sumar un carrito, quitar un almacén mal
     * anotado, decir que en el carrito solo hay blísteres—. Los almacenes
     * son tarjetas que se tocan para marcar o desmarcar; marcada, cada una
     * muestra sus envases. Abajo se ve qué cambia antes de guardar
     * (`filament.forms.lugares-del-producto`).
     */
    private function lugaresAction(): Action
    {
        return Action::make('lugares')
            ->label('Lugares')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('gray')
            ->link()
            ->modalIcon(Heroicon::OutlinedMapPin)
            ->modalHeading(fn (Item $record): string => '¿Dónde hay '.$record->nombre.'?')
            ->modalDescription(
                'Stock infinito: no se cuenta, se anota dónde hay y qué hay. Al cargarlo a una cuenta solo se '
                .'ofrecen los almacenes donde hay de lo que se está cargando.'
            )
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Guardar')
            ->visible(fn (Item $record): bool => $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class))
            ->fillForm(fn (Item $record): array => [
                'lugares' => $this->lugaresParaElFormulario($record),
            ])
            ->schema(fn (Item $record): array => [
                ViewField::make('lugares')
                    ->hiddenLabel()
                    ->required()
                    ->validationMessages(['required' => 'Marque al menos un almacén: si no, nadie sabría de dónde sale.'])
                    ->view('filament.forms.lugares-del-producto')
                    ->viewData([
                        'almacenes' => $this->tarjetasDeAlmacenes(),
                        'envases'   => $this->tarjetasDeEnvases($record),
                        'unidad'    => self::codigoDeLaUnidad($record),
                        'antes'     => $this->lugaresParaElFormulario($record),
                    ]),
            ])
            ->action(function (array $data, Item $record, Action $action): void {
                if (! $this->guardarLugares($record, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * Los almacenes donde está, que esta persona opera: de esos puede
     * salir.
     *
     * @return list<array{id: int, nombre: string, tipo: string, icono: string, sede: int}>
     */
    private function origenesDelInfinito(Item $producto): array
    {
        $donde = $this->dondeEstaElInfinito($producto);

        return array_values(array_filter(
            $this->tarjetasDeAlmacenes(),
            function (array $tarjeta) use ($donde): bool {
                $almacen = $this->almacenesPorId()->get($tarjeta['id']);

                return in_array($tarjeta['id'], $donde, true)
                    && $almacen instanceof Almacen
                    && AlmacenesDelUsuario::puedeOperarEn($almacen);
            },
        ));
    }

    /**
     * El almacén de arriba si está ahí, o el primero de donde puede salir.
     */
    private function origenSugerido(Item $producto): ?int
    {
        $ids = array_column($this->origenesDelInfinito($producto), 'id');
        $elegido = $this->almacenElegido();

        if ($elegido !== null && in_array($elegido, $ids, true)) {
            return $elegido;
        }

        return $ids[0] ?? null;
    }

    /**
     * Los almacenes vigentes de la sede, en el orden de arriba, como los
     * pintan los modales.
     *
     * @return list<array{id: int, nombre: string, tipo: string, icono: string, sede: int}>
     */
    private function tarjetasDeAlmacenes(): array
    {
        return array_values($this->almacenesEnOrden()
            ->filter(fn (Almacen $almacen): bool => $almacen->estaVigente())
            ->map(fn (Almacen $almacen): array => [
                'id'     => (int) $almacen->id,
                'nombre' => $almacen->nombre,
                'tipo'   => $almacen->tipo->etiqueta(),
                'icono'  => self::iconoDelTipo($almacen->tipo),
                'sede'   => (int) $almacen->sede_id,
            ])
            ->all());
    }

    private static function iconoDelTipo(TipoAlmacen $tipo): string
    {
        return match ($tipo) {
            TipoAlmacen::FarmaciaVenta, TipoAlmacen::AlmacenUnico => 'heroicon-o-building-storefront',
            TipoAlmacen::FarmaciaInterna                          => 'heroicon-o-beaker',
            TipoAlmacen::StockDeServicio                          => 'heroicon-o-lifebuoy',
            TipoAlmacen::BodegaCentral                            => 'heroicon-o-archive-box',
        };
    }

    /**
     * Qué hay del infinito en cada almacén donde está: nulo es «de todo»;
     * una lista, los envases marcados (`UbicadorDeProducto`).
     *
     * @return array<int, list<int>|null>
     */
    private function lugaresDelInfinito(Item $producto): array
    {
        return $this->lugaresPorItem[(int) $producto->id]
            ??= app(UbicadorDeProducto::class)->lugaresDe($producto);
    }

    /**
     * @return list<int>
     */
    private function dondeEstaElInfinito(Item $producto): array
    {
        return array_keys($this->lugaresDelInfinito($producto));
    }

    /**
     * Los envases que se venden cerrados, del más grande al más chico.
     *
     * @return Collection<int, ItemPresentacion>
     */
    private function envasesDelProducto(Item $producto): Collection
    {
        return $this->envasesPorItem[(int) $producto->id]
            ??= app(UbicadorDeProducto::class)->envasesDe($producto);
    }

    /**
     * Los envases como los pintan los modales, cada uno con los que trae
     * adentro: la CAJA X 100 trae el BLISTER X 10.
     *
     * @return list<array{id: int, nombre: string, corto: string, trae: list<int>}>
     */
    private function tarjetasDeEnvases(Item $producto): array
    {
        $envases = $this->envasesDelProducto($producto);
        $cortos = self::cortosDe($envases);
        $formas = app(FormasDeCobro::class);
        $tarjetas = [];

        foreach ($envases as $envase) {
            $trae = [];

            foreach ($envases as $chico) {
                if ($chico->id !== $envase->id && in_array($envase->id, $formas->envasesQueLoTraen($chico, $envases), true)) {
                    $trae[] = (int) $chico->id;
                }
            }

            $tarjetas[] = [
                'id'     => (int) $envase->id,
                'nombre' => UbicadorDeProducto::comoSeLlama($envase),
                'corto'  => $cortos[(int) $envase->id] ?? UbicadorDeProducto::comoSeLlama($envase),
                'trae'   => $trae,
            ];
        }

        return $tarjetas;
    }

    /**
     * Los envases que hay en cada almacén donde está, contando lo que
     * traen los marcados.
     *
     * @return array<int, list<int>>
     */
    private function disponiblesDelInfinito(Item $producto): array
    {
        $ubicador = app(UbicadorDeProducto::class);
        $envases = $this->envasesDelProducto($producto);
        $disponibles = [];

        foreach ($this->lugaresDelInfinito($producto) as $almacen => $marcados) {
            $disponibles[$almacen] = $ubicador->disponibles($marcados, $envases);
        }

        return $disponibles;
    }

    /**
     * Lo que «Lugares» trae marcado: cada almacén con sus envases. «De
     * todo» se muestra con los más grandes marcados —la caja, que trae el
     * blíster—.
     *
     * @return list<array{almacen: int, envases: list<int>}>
     */
    private function lugaresParaElFormulario(Item $producto): array
    {
        $ubicador = app(UbicadorDeProducto::class);
        $envases = $this->envasesDelProducto($producto);
        $lugares = $this->lugaresDelInfinito($producto);
        $lista = [];

        foreach ($this->almacenesEnOrden() as $almacen) {
            if (! array_key_exists((int) $almacen->id, $lugares)) {
                continue;
            }

            $marcados = $lugares[(int) $almacen->id];

            $lista[] = [
                'almacen' => (int) $almacen->id,
                'envases' => $marcados ?? $ubicador->losMasGrandes(null, $envases),
            ];
        }

        return $lista;
    }

    /**
     * Qué hay en un lugar, TODO dicho: «CAJA, BLISTER, TAB».
     * Con cajas hay blísteres y tabletas, y se escribe —Mauricio: «si hay
     * cajas significa que hay blísteres y tabletas, ¿por qué no lo
     * dice?»—. Sin ningún envase, «SOLO TAB». Nulo si el producto no tiene
     * envases: ahí no hay nada que decir.
     *
     * @param list<int>|null $marcados
     */
    private function queHay(Item $producto, ?array $marcados): ?string
    {
        $envases = $this->envasesDelProducto($producto);

        if ($envases->isEmpty()) {
            return null;
        }

        $cortos = self::cortosDe($envases);
        $nombres = [];

        foreach (app(UbicadorDeProducto::class)->disponibles($marcados, $envases) as $id) {
            if (isset($cortos[$id])) {
                $nombres[] = $cortos[$id];
            }
        }

        $unidad = self::codigoDeLaUnidad($producto);

        return $nombres === [] ? 'SOLO '.$unidad : implode(', ', [...$nombres, $unidad]);
    }

    /**
     * «TAB», «ML»: la unidad del kardex.
     */
    private static function codigoDeLaUnidad(Item $producto): string
    {
        $unidad = $producto->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : 'UNIDADES';
    }

    /**
     * @param array<array-key, mixed> $movimiento origen, destino, qué se llevó y si se llevó todo
     */
    private function moverInfinito(Item $producto, array $movimiento): bool
    {
        /*
         * 🔴 Se vuelve a preguntar acá: `visible()` decide qué se dibuja,
         * no qué se puede llamar. Y el origen tiene que ser uno de donde
         * esta persona puede sacar.
         */
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $origenes = array_column($this->origenesDelInfinito($producto), 'id');
        $origenId = is_numeric($movimiento['origen'] ?? null) ? (int) $movimiento['origen'] : null;
        $destinoId = is_numeric($movimiento['destino'] ?? null) ? (int) $movimiento['destino'] : null;
        $envaseId = is_numeric($movimiento['envase'] ?? null) ? (int) $movimiento['envase'] : null;
        $todo = filter_var($movimiento['todo'] ?? false, FILTER_VALIDATE_BOOL);

        $origen = $origenId !== null && in_array($origenId, $origenes, true)
            ? $this->almacenesPorId()->get($origenId)
            : null;

        $destino = $destinoId === null ? null : $this->almacenesPorId()->get($destinoId);

        if (! $origen instanceof Almacen || ! $destino instanceof Almacen) {
            return self::faltaElegir('Elija de qué almacén sale y a cuál entra.');
        }

        if (! $todo && $envaseId === null) {
            return self::faltaElegir('Elija qué se llevó: un envase, unidades sueltas, o todo.');
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * A DONDE YA HAY DE TODO, NO SE MUEVE (13-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Mauricio: «si es infinito y ya hay de eso en ese lugar, no hay
         * lógica en moverlo». Si el destino ya tiene todo lo que hay en el
         * origen, mover no le cambia nada —tampoco «Todo»: eso sería
         * quitarlo del origen, y para eso está «Lugares»—. El modal ni lo
         * ofrece; esto es para la llamada armada a mano.
         */
        $disponibles = $this->disponiblesDelInfinito($producto);

        if (array_key_exists((int) $destino->id, $disponibles)
            && array_diff($disponibles[(int) $origen->id] ?? [], $disponibles[(int) $destino->id]) === []) {
            Notification::make()
                ->title('No hay nada que mover')
                ->body("En {$destino->nombre} ya hay todo lo que hay en {$origen->nombre}. Para quitarlo de {$origen->nombre}, use «Lugares».")
                ->warning()
                ->send();

            return false;
        }

        /*
         * 0 son unidades sueltas. Un envase tiene que ser de los de este
         * producto; si hay de él en el origen lo mira el servicio.
         */
        $envase = null;

        if (! $todo && $envaseId !== 0) {
            $envase = $this->envasesDelProducto($producto)->firstWhere('id', $envaseId);

            if (! $envase instanceof ItemPresentacion) {
                return self::faltaElegir('Elija uno de los envases de la lista.');
            }
        }

        try {
            $cambio = app(UbicadorDeProducto::class)->mover($producto, $origen, $destino, quedaEnOrigen: ! $todo, envase: $envase);
        } catch (SihlaException $e) {
            Notification::make()
                ->title('No se pudo mover')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        /*
         * Una parte a donde ya había de eso no cambia nada: se avisa en vez
         * de fingir que se movió.
         */
        if (! $cambio) {
            Notification::make()
                ->title('No cambia nada')
                ->body("En {$destino->nombre} ya había de eso. Si se llevó todo lo de {$origen->nombre}, elija «Todo».")
                ->warning()
                ->send();

            return false;
        }

        $this->olvidarLoLeido();

        $queSeLlevo = $envase instanceof ItemPresentacion
            ? UbicadorDeProducto::comoSeLlama($envase)
            : self::codigoDeLaUnidad($producto).' sueltas';

        Notification::make()
            ->title('Movido')
            ->body($todo
                ? "{$producto->nombre} pasó de {$origen->nombre} a {$destino->nombre}."
                : "En {$destino->nombre} ahora también hay {$producto->nombre} ({$queSeLlevo}).")
            ->success()
            ->send();

        return true;
    }

    private static function faltaElegir(string $que): bool
    {
        Notification::make()
            ->title('Falta elegir')
            ->body($que)
            ->warning()
            ->send();

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function guardarLugares(Item $producto, array $data): bool
    {
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $lugares = UbicadorDeProducto::lugaresDelFormulario($data['lugares'] ?? null);

        if ($lugares === []) {
            Notification::make()
                ->title('Marque al menos un almacén')
                ->body('Si no, al cargarlo a una cuenta nadie sabría de dónde sale.')
                ->danger()
                ->send();

            return false;
        }

        try {
            app(UbicadorDeProducto::class)->dejarConEnvases($producto, $lugares);
        } catch (SihlaException $e) {
            Notification::make()
                ->title('No se guardó')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        $this->olvidarLoLeido();

        $donde = [];

        foreach ($this->lugaresDelInfinito($producto) as $almacenId => $marcados) {
            $almacen = $this->almacenesPorId()->get($almacenId);
            $que = $this->queHay($producto, $marcados);

            if ($almacen instanceof Almacen) {
                $donde[] = $almacen->nombre.($que === null ? '' : ' ('.$que.')');
            }
        }

        Notification::make()
            ->title('Guardado')
            ->body("{$producto->nombre} está en: ".implode(', ', $donde).'.')
            ->success()
            ->send();

        return true;
    }

    /**
     * Después de mover, las cuentas de arriba y los chips se vuelven a
     * leer al pintar: se habían leído antes, para encontrar el renglón.
     */
    private function olvidarLoLeido(): void
    {
        $this->productosPorAlmacen = null;
        $this->lugares = null;
        $this->existenciasCargadas = [];
        $this->lugaresPorItem = [];
        $this->envasesPorItem = [];
        $this->lotesPorItem = [];
    }

    // ── Cómo se lee cada renglón ──────────────────────────────────────

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

    // ── En qué envase está cada renglón ───────────────────────────────

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

        $envase = $presentacion->envase();

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
        return self::colorPorDias($existencia->lote?->diasParaVencerDesde(now()));
    }

    private static function cuantoLeQueda(Existencia $existencia): ?string
    {
        return self::textoPorDias($existencia->lote?->diasParaVencerDesde(now()));
    }

    /**
     * Cuántos días faltan para una fecha `Y-m-d`; negativo si ya pasó.
     */
    private static function diasHasta(?string $fecha): ?int
    {
        if ($fecha === null) {
            return null;
        }

        return (int) round(now()->startOfDay()->diffInDays(CarbonImmutable::parse($fecha)->startOfDay(), false));
    }

    private static function colorPorDias(?int $dias): string
    {
        return match (true) {
            $dias === null => 'gray',
            $dias <= 30    => 'danger',
            $dias <= 90    => 'warning',
            default        => 'success',
        };
    }

    private static function textoPorDias(?int $dias): ?string
    {
        return match (true) {
            $dias === null => null,
            $dias < 0      => 'Vencido hace '.abs($dias).' d',
            $dias === 0    => 'Vence hoy',
            default        => 'En '.$dias.' d',
        };
    }
}
