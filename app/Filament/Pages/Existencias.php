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
use App\Models\UbicacionDeProducto;
use App\Models\Unidad;
use App\Services\TrasladadorDeExistencias;
use App\Services\UbicadorDeProducto;
use App\Support\AlmacenesDelUsuario;
use App\Support\NumeroDeFormulario;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
 * (`UbicadorDeProducto`), y eso es lo que muestra su renglón. Su «Mover»
 * anota que ahora también hay en otro almacén —o que se llevó todo—, y
 * «Lugares» corrige la lista entera.
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
                $this->moverAction(),
                $this->moverInfinitoAction(),
                $this->lugaresAction(),
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
     * @return list<TextColumn>
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
             * Un chip por almacén: «FARMACIA · 458 TAB». Lo infinito, sin
             * cantidad. En el orden en que se despacha.
             */
            TextColumn::make('donde')
                ->label('Dónde está')
                ->state(fn (Item $record): array => $this->chipsDe($record))
                ->badge()
                ->color(fn (Item $record, string $state): string => $this->colorDelChip($record, $state))
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

        foreach ($lista as $registro) {
            if ($registro instanceof Item) {
                $ids[] = (int) $registro->id;
            }
        }

        $reales = [];
        $infinitos = [];

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

            $abiertas = UbicacionDeProducto::query()
                ->abiertas()
                ->whereIn('item_id', $ids)
                ->get(['item_id', 'almacen_id']);

            foreach ($abiertas as $lugar) {
                $infinitos[$lugar->item_id][] = [
                    'almacen'  => $lugar->almacen_id,
                    'cantidad' => null,
                    'lotes'    => 0,
                    'vence'    => null,
                ];
            }
        }

        return $this->lugares = ['reales' => $reales, 'infinitos' => $infinitos];
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
     * @return list<string>
     */
    private function chipsDe(Item $item): array
    {
        return array_keys($this->chipsConColor($item));
    }

    private function colorDelChip(Item $item, string $chip): string
    {
        return $this->chipsConColor($item)[$chip] ?? 'gray';
    }

    /**
     * «FARMACIA · 458 TAB» => color. El almacén elegido arriba,
     * resaltado; los demás, grises.
     *
     * @return array<string, string>
     */
    private function chipsConColor(Item $item): array
    {
        $chips = [];
        $elegido = $this->almacenElegido();

        foreach ($this->lugaresDe($item) as $lugar) {
            $almacen = $this->almacenesPorId()->get($lugar['almacen']);
            $nombre = $almacen instanceof Almacen ? $almacen->nombre : 'OTRA SEDE';

            $chip = $lugar['cantidad'] instanceof Decimal
                ? $nombre.' · '.self::conUnidad($lugar['cantidad'], $item)
                : $nombre;

            $chips[$chip] = $lugar['almacen'] === $elegido ? 'primary' : 'gray';
        }

        return $chips;
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
     * El botón que contesta «de dónde sale y a dónde va».
     *
     * El lote se elige ADENTRO del modal: la lista dice almacén, lote,
     * envase, vencimiento y cuánto hay, y quien está frente al estante
     * elige cuál baja —primero lo que vence, normalmente—. Solo se
     * ofrecen los lotes de los estantes que esa persona opera: farmacia
     * ve lo de bodega en «Lotes», pero no puede sacarle nada.
     *
     * El permiso es el de ajustar: un traslado toca el kardex igual,
     * aunque no cambie el total del hospital.
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
            ->modalDescription(fn (Item $record): string => $record->nombre)
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Mover')
            ->visible(fn (Item $record): bool => ! $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class)
                && $this->tieneAlgoQuePuedaMover($record))
            ->fillForm(fn (Item $record): array => [
                'existencia_id' => $this->loteSugerido($record),
            ])
            ->schema(fn (Item $record): array => $this->camposParaMover($record))
            ->action(function (array $data, Item $record, Action $action): void {
                $existencia = $this->existenciaElegida($data['existencia_id'] ?? null);

                /*
                 * 🔴 Se vuelve a mirar acá: el lote llega del navegador.
                 * Tiene que ser de ESTE producto y de un estante que esta
                 * persona opere.
                 */
                if (! $existencia instanceof Existencia
                    || $existencia->item_id !== $record->id
                    || ! $existencia->almacen instanceof Almacen
                    || ! AlmacenesDelUsuario::puedeOperarEn($existencia->almacen)) {
                    Notification::make()
                        ->title('No se pudo mover')
                        ->body('Elija uno de los lotes de la lista.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                if (! $this->mover($existencia, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * @return list<Component>
     */
    private function camposParaMover(Item $producto): array
    {
        return [
            Select::make('existencia_id')
                ->label('Qué lote, y de dónde sale')
                ->required()
                ->native(false)
                ->live()
                ->options(fn (): array => $this->lotesParaMover($producto))
                ->afterStateUpdated(function (Set $set): void {
                    $set('destino_id', null);
                    $set('cantidad', null);
                }),

            /*
             * «Entra a» y «cuánto», uno al lado del otro. En el celular,
             * uno debajo del otro.
             */
            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    /*
                     * ⚠️ SIN `searchable()`: con `options()` y búsqueda
                     * encendida, Filament busca en el servidor con
                     * `getSearchResultsUsing` —que acá no existe— y
                     * «BODEGA» devolvía «sin coincidencias» estando en la
                     * lista. Son cinco estantes: se eligen mirando.
                     */
                    Select::make('destino_id')
                        ->label('Entra a')
                        ->required()
                        ->native(false)
                        ->options(function (Get $get): array {
                            $existencia = $this->existenciaElegida($get('existencia_id'));

                            return $existencia instanceof Existencia ? self::destinosPara($existencia) : [];
                        })
                        ->helperText('¿No está el carrito? Se crea en Inventario → Almacenes.'),

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 SE MUEVEN ENVASES, NO UNIDADES DE DISPENSACIÓN
                     * ─────────────────────────────────────────────────
                     *
                     * Alguien escribió «5» queriendo bajar 5 FRASCOS y
                     * bajó 5 ML. Un traslado es un hecho físico: se pide
                     * en el envase del lote y el sistema convierte. El
                     * tope va como cadena (§8.6.2), y el candado de verdad
                     * es el UPDATE condicional del movimiento.
                     */
                    TextInput::make('cantidad')
                        ->label(function (Get $get): string {
                            $existencia = $this->existenciaElegida($get('existencia_id'));

                            return $existencia instanceof Existencia
                                ? self::comoSePideLaCantidad($existencia)
                                : 'Cuánto se mueve';
                        })
                        ->required()
                        ->numeric()
                        ->live(onBlur: true)
                        ->minValue('0.0001')
                        ->step('0.0001')
                        ->disabled(fn (Get $get): bool => ! $this->existenciaElegida($get('existencia_id')) instanceof Existencia)
                        ->maxValue(function (Get $get): ?string {
                            $existencia = $this->existenciaElegida($get('existencia_id'));

                            return $existencia instanceof Existencia ? self::topeParaMover($existencia) : null;
                        })
                        /*
                         * «Mover todo» pone el tope exacto, que el servicio
                         * entiende como «todo»: teclear 0.0833 FRASCO a
                         * mano deja restos que nadie puede mover ni contar.
                         */
                        ->hintAction(
                            Action::make('mover_todo')
                                ->label('Mover todo')
                                ->icon(Heroicon::OutlinedArrowsRightLeft)
                                ->action(function (Get $get, Set $set): void {
                                    $existencia = $this->existenciaElegida($get('existencia_id'));

                                    if ($existencia instanceof Existencia) {
                                        $set('cantidad', self::topeParaMover($existencia));
                                    }
                                }),
                        )
                        ->helperText(function (Get $get): ?string {
                            $existencia = $this->existenciaElegida($get('existencia_id'));

                            return $existencia instanceof Existencia
                                ? self::cuantoQuedaEnEsteLote($existencia, $get('cantidad'))
                                : null;
                        }),
                ]),

            /*
             * «Además hay 3 en BODEGA…»: lo que evita el viaje de más.
             */
            Text::make(function (Get $get): string {
                $existencia = $this->existenciaElegida($get('existencia_id'));

                return $existencia instanceof Existencia ? self::dondeMasHay($existencia) : '';
            })
                ->color('gray')
                ->icon(Heroicon::OutlinedMapPin)
                ->visible(fn (Get $get): bool => $this->existenciaElegida($get('existencia_id')) instanceof Existencia),

            CampoMayusculas::area('motivo')
                ->label('Nota (opcional)')
                ->rows(2)
                ->maxLength(200)
                ->placeholder('Reposición del carro, préstamo a quirófano…')
                ->helperText('Queda escrita en las dos líneas del kardex.'),
        ];
    }

    /**
     * Los lotes que esta persona puede mover, en el orden de los
     * almacenes de arriba y, dentro de cada uno, lo que vence primero.
     *
     * @return array<int, string>
     */
    private function lotesParaMover(Item $producto): array
    {
        $orden = $this->almacenesEnOrden()->pluck('id')->flip()->all();

        return Existencia::query()
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
            ))
            ->mapWithKeys(fn (Existencia $fila): array => [$fila->id => self::etiquetaDelLote($fila)])
            ->all();
    }

    /**
     * El lote que el modal trae elegido: el primero del almacén de arriba,
     * o el primero de la lista.
     */
    private function loteSugerido(Item $producto): ?int
    {
        $lotes = $this->lotesParaMover($producto);
        $almacen = $this->almacenElegido();

        if ($almacen !== null) {
            foreach (array_keys($lotes) as $id) {
                if ($this->existenciaElegida($id)?->almacen_id === $almacen) {
                    return $id;
                }
            }
        }

        $primero = array_key_first($lotes);

        return $primero === null ? null : (int) $primero;
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
     * «FARMACIA · LOT-2 · CAJA X 100 TABLETAS · vence 30/10/2026 — hay 458 TAB».
     */
    private static function etiquetaDelLote(Existencia $existencia): string
    {
        $vence = $existencia->lote?->fecha_vencimiento;

        $partes = array_filter([
            $existencia->almacen instanceof Almacen ? $existencia->almacen->nombre : null,
            $existencia->lote instanceof Lote ? $existencia->lote->numero : 'sin lote',
            self::nombreDelEnvase($existencia),
            $vence === null ? null : 'vence '.$vence->format('d/m/Y'),
        ]);

        return implode(' · ', $partes).' — hay '.self::cuantoHay($existencia);
    }

    // ── Lo que tiene stock infinito ───────────────────────────────────

    /**
     * El «Mover» de un producto con stock infinito: no pasa una cantidad
     * —no hay—, anota que ahora también hay en el destino. Si se llevó
     * todo, el origen se cierra. Lo de siempre es llevar una parte (unas
     * ampollas al carrito rojo), así que «sigue habiendo» viene marcado.
     */
    private function moverInfinitoAction(): Action
    {
        return Action::make('moverInfinito')
            ->label('Mover')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->button()
            ->outlined()
            ->color('primary')
            ->modalHeading('Mover a otro almacén')
            ->modalDescription(fn (Item $record): string => $record->nombre
                .' — stock infinito: no se cuenta, se anota dónde hay.')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Mover')
            ->visible(fn (Item $record): bool => $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class)
                && $this->tieneAlgoQuePuedaMover($record))
            ->fillForm(fn (Item $record): array => [
                'origen_id'       => array_key_first($this->origenesDelInfinito($record)),
                'queda_en_origen' => true,
            ])
            ->schema(fn (Item $record): array => [
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('origen_id')
                            ->label('Sale de')
                            ->required()
                            ->native(false)
                            ->live()
                            ->options(fn (): array => $this->origenesDelInfinito($record))
                            ->afterStateUpdated(fn (Set $set): mixed => $set('destino_id', null)),

                        Select::make('destino_id')
                            ->label('Entra a')
                            ->required()
                            ->native(false)
                            ->options(fn (Get $get): array => $this->destinosDelInfinito($record, $get('origen_id')))
                            ->helperText('¿No está el carrito? Se crea en Inventario → Almacenes.'),
                    ]),

                Toggle::make('queda_en_origen')
                    ->label('Sigue habiendo en el almacén de donde sale')
                    ->helperText('Déjelo marcado si se llevó una parte. Apáguelo si se llevó todo: ahí deja de aparecer.'),
            ])
            ->action(function (array $data, Item $record, Action $action): void {
                if (! $this->moverInfinito($record, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * «Lugares»: la lista entera de dónde hay, para corregir de una vez
     * —sumar un carrito, quitar un almacén mal anotado—.
     */
    private function lugaresAction(): Action
    {
        return Action::make('lugares')
            ->label('Lugares')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('gray')
            ->link()
            ->modalHeading(fn (Item $record): string => 'Dónde hay '.$record->nombre)
            ->modalDescription(
                'Al cargarlo a una cuenta solo se ofrecen estos almacenes. Lo que ya se cargó no cambia.'
            )
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Guardar')
            ->visible(fn (Item $record): bool => $record->tieneStockInfinito()
                && Gate::allows('create', Ajuste::class))
            ->fillForm(fn (Item $record): array => [
                'ubicaciones' => app(UbicadorDeProducto::class)->dondeEsta($record),
            ])
            ->schema([
                CheckboxList::make('ubicaciones')
                    ->hiddenLabel()
                    ->required()
                    ->validationMessages(['required' => 'Marque al menos un almacén: si no, nadie sabría de dónde sale.'])
                    ->options(fn (): array => $this->almacenesEnOrden()
                        ->filter(fn (Almacen $almacen): bool => $almacen->estaVigente())
                        ->mapWithKeys(fn (Almacen $almacen): array => [$almacen->id => $almacen->nombre])
                        ->all())
                    ->columns(['default' => 1, 'sm' => 2]),
            ])
            ->action(function (array $data, Item $record, Action $action): void {
                if (! $this->guardarLugares($record, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * Los almacenes donde está, que esta persona opera.
     *
     * @return array<int, string>
     */
    private function origenesDelInfinito(Item $producto): array
    {
        $donde = app(UbicadorDeProducto::class)->dondeEsta($producto);

        return $this->almacenesEnOrden()
            ->filter(fn (Almacen $almacen): bool => in_array((int) $almacen->id, $donde, true)
                && AlmacenesDelUsuario::puedeOperarEn($almacen))
            ->mapWithKeys(fn (Almacen $almacen): array => [$almacen->id => $almacen->nombre])
            ->all();
    }

    /**
     * Los almacenes de la misma sede a los que se puede llevar, diciendo
     * en cuáles ya está.
     *
     * @return array<int, string>
     */
    private function destinosDelInfinito(Item $producto, mixed $origenId): array
    {
        $origen = is_numeric($origenId) ? $this->almacenesPorId()->get((int) $origenId) : null;

        if (! $origen instanceof Almacen) {
            return [];
        }

        $yaEsta = app(UbicadorDeProducto::class)->dondeEsta($producto);

        return $this->almacenesEnOrden()
            ->filter(fn (Almacen $almacen): bool => (int) $almacen->id !== (int) $origen->id
                && (int) $almacen->sede_id === (int) $origen->sede_id
                && $almacen->estaVigente())
            ->mapWithKeys(fn (Almacen $almacen): array => [
                $almacen->id => $almacen->nombre.(in_array((int) $almacen->id, $yaEsta, true) ? ' · ya está' : ''),
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function moverInfinito(Item $producto, array $data): bool
    {
        /*
         * 🔴 Se vuelve a preguntar acá: `visible()` decide qué se dibuja,
         * no qué se puede llamar.
         */
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $origenes = $this->origenesDelInfinito($producto);
        $origenId = is_numeric($data['origen_id'] ?? null) ? (int) $data['origen_id'] : null;

        $origen = $origenId !== null && array_key_exists($origenId, $origenes)
            ? $this->almacenesPorId()->get($origenId)
            : null;

        $destino = is_numeric($data['destino_id'] ?? null)
            ? Almacen::query()->find((int) $data['destino_id'])
            : null;

        if (! $origen instanceof Almacen || ! $destino instanceof Almacen) {
            Notification::make()
                ->title('No se pudo mover')
                ->body('Elija de qué almacén sale y a cuál entra.')
                ->danger()
                ->send();

            return false;
        }

        $quedaEnOrigen = (bool) ($data['queda_en_origen'] ?? true);

        try {
            app(UbicadorDeProducto::class)->mover($producto, $origen, $destino, $quedaEnOrigen);
        } catch (SihlaException $e) {
            Notification::make()
                ->title('No se pudo mover')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        $this->olvidarLoLeido();

        Notification::make()
            ->title('Movido')
            ->body($quedaEnOrigen
                ? "{$producto->nombre} ahora también está en {$destino->nombre}."
                : "{$producto->nombre} pasó de {$origen->nombre} a {$destino->nombre}.")
            ->success()
            ->send();

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function guardarLugares(Item $producto, array $data): bool
    {
        abort_unless(static::canAccess() && Gate::allows('create', Ajuste::class), 403);

        $lugares = UbicadorDeProducto::idsDelFormulario($data['ubicaciones'] ?? null);

        if ($lugares === []) {
            Notification::make()
                ->title('Marque al menos un almacén')
                ->body('Si no, al cargarlo a una cuenta nadie sabría de dónde sale.')
                ->danger()
                ->send();

            return false;
        }

        try {
            app(UbicadorDeProducto::class)->dejarEn($producto, $lugares);
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

        Notification::make()
            ->title('Guardado')
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

        /*
         * Las cuentas de arriba se leyeron antes de mover —para encontrar
         * el renglón—; se vuelven a leer al pintar.
         */
        $this->olvidarLoLeido();

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
