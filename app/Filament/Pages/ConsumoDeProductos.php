<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\EstadoCargo;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\Concerns\ElijePeriodo;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Item;
use App\Models\Lote;
use App\Models\Unidad;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Qué se usó con los pacientes, cuánto, cuándo y de qué estante salió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ EXISTE (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio, sobre el stock infinito: «mientras esté en infinito no se
 * puede ingresar mercadería; se va tomando cuánto se va usando, pero solo
 * para reportaje de cuánto de qué se usó. Va quedando el historial, para
 * tener conciencia de cuánto se usó de qué esa semana, ese mes o ese día,
 * y de dónde se sacó».
 *
 * Un producto en infinito no tiene kardex que mirar: cada uso queda solo
 * en su CARGO —qué, cuánto, cuándo y, desde el 13-oct, de qué almacén—.
 * Esta pantalla lee esos cargos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * INCLUYE TAMBIÉN LO QUE TIENE STOCK REAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * «¿Cuánto acetaminofén se usó esta semana?» tiene la misma respuesta
 * esté el producto en infinito o en real, y el día que pase de uno a
 * otro el reporte no puede partirse en dos. Así que cuenta todo lo que se
 * almacena; el filtro «Origen» separa lo que salió sin inventario de lo
 * que descontó el kardex.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CUENTA Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Cuenta por `fecha_operacion`, el día del hospital en que se cargó,
 *     y no por `created_at` (§7.5-4).
 *   · Solo cargos pendientes o facturados. Un cargo anulado y su reversa
 *     salen los dos: lo que se cargó por error no se usó. Lo trasladado
 *     a otra cuenta cuenta una vez, en la cuenta nueva.
 *   · Las cantidades van en la unidad de dispensación —«35 TAB»—, la
 *     misma en la que se cobra y en la que cuenta el kardex.
 */
class ConsumoDeProductos extends Page implements HasTable
{
    use ElijePeriodo;
    use InteractsWithTable;

    /**
     * El almacén elegido arriba que significa «los cargos sin almacén»:
     * los de antes del 13-oct, cuando el estante de un producto sin
     * existencia no se guardaba.
     */
    public const SIN_ALMACEN = 0;

    /**
     * Cuántos productos se ven en el resumen antes de «Ver todos».
     */
    public const PRODUCTOS_A_LA_VISTA = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'consumo-de-productos';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.consumo-de-productos';

    /**
     * `#[Locked]`: solo lo cambian `verAlmacen()` y `verTodosLosAlmacenes()`.
     * Nulo = todos; `SIN_ALMACEN` = los que no tienen.
     */
    #[Locked]
    public ?int $almacen = null;

    /**
     * `#[Locked]`: solo lo cambian `verProducto()` y `verTodosLosProductos()`.
     */
    #[Locked]
    public ?int $producto = null;

    /**
     * Las cifras del resumen, una consulta por pintada. Privadas: Livewire
     * no las guarda entre pedidos y nunca quedan viejas.
     *
     * @var list<array{id: int, nombre: string, codigo: string, infinito: bool, veces: int, cantidad: string, deDonde: string}>|null
     */
    private ?array $resumen = null;

    private ?string $resumenDe = null;

    /**
     * Cuántos usos tuvo cada almacén en el período (`SIN_ALMACEN` para
     * los que no tienen). Lo leen el control de arriba y la guarda del
     * almacén elegido: una consulta por pintada, no una por pregunta.
     *
     * @var array<int, int>|null
     */
    private ?array $veces = null;

    private ?string $vecesDe = null;

    public static function getNavigationLabel(): string
    {
        return 'Consumo de productos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public function getTitle(): string
    {
        return 'Consumo de productos';
    }

    public function getSubheading(): string
    {
        return 'Lo que se usó con los pacientes en el período y de qué estante salió. '
            .'Incluye lo que tiene stock infinito, que no descuenta del inventario.';
    }

    /**
     * ⚠️ Hacen falta LOS DOS permisos: ver almacenes, porque es un reporte
     * de inventario, y ver cargos, porque cada renglón dice a qué paciente
     * se le cargó. Quedan farmacia, bodega con cargos, caja, auditoría y
     * dirección.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Almacen::class)
            && Gate::allows('viewAny', Cargo::class);
    }

    /**
     * `?almacen=` y `?producto=` se leen a mano y no con `#[Url]`, por la
     * misma razón que el período (ver `leerElPeriodoDeLaUrl()`).
     */
    public function mount(): void
    {
        $this->leerElPeriodoDeLaUrl();

        $this->almacen = self::enteroDeLaUrl('almacen');
        $this->producto = self::enteroDeLaUrl('producto');
    }

    // ── El período ────────────────────────────────────────────────────

    /**
     * El consumo se mira por día, por semana o por mes.
     *
     * @return array<string, string>
     */
    protected function atajosDelPeriodo(): array
    {
        return [
            'hoy'         => 'Hoy',
            'esta_semana' => 'Esta semana',
            'este_mes'    => 'Este mes',
            'mes_pasado'  => 'Mes pasado',
        ];
    }

    // ── De dónde ──────────────────────────────────────────────────────

    public function verAlmacen(int $almacenId): void
    {
        $this->almacen = $this->almacen === $almacenId ? null : $almacenId;

        $this->resetPage();
    }

    public function verTodosLosAlmacenes(): void
    {
        $this->almacen = null;

        $this->resetPage();
    }

    /**
     * Los estantes de los que salió algo en el período, con cuántos usos
     * cada uno. Una sola consulta agrupada más la de los nombres.
     *
     * No depende del almacén elegido: es el control que lo elige.
     *
     * @return list<array{id: int, nombre: string, veces: int, activo: bool}>
     */
    public function almacenes(): array
    {
        $veces = $this->vecesPorAlmacen();

        if ($veces === []) {
            return [];
        }

        $elegido = $this->almacenElegido();

        /*
         * `deTodasLasSedes()`: el cargo ya viene de la sede de quien mira,
         * y su almacén es de esa misma sede. Quitar el filtro solo evita
         * que un estante sin nombre deje la fila en blanco.
         */
        $lista = array_values(Almacen::query()
            ->deTodasLasSedes()
            ->whereIn('id', array_keys($veces))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Almacen $almacen): array => [
                'id'     => (int) $almacen->id,
                'nombre' => $almacen->nombre,
                'veces'  => $veces[(int) $almacen->id] ?? 0,
                'activo' => $elegido === (int) $almacen->id,
            ])
            ->all());

        if (isset($veces[self::SIN_ALMACEN])) {
            $lista[] = [
                'id'     => self::SIN_ALMACEN,
                'nombre' => 'Sin estante',
                'veces'  => $veces[self::SIN_ALMACEN],
                'activo' => $elegido === self::SIN_ALMACEN,
            ];
        }

        return $lista;
    }

    public function totalDeUsos(): int
    {
        return array_sum($this->vecesPorAlmacen());
    }

    // ── Qué ───────────────────────────────────────────────────────────

    public function verProducto(int $itemId): void
    {
        $this->producto = $this->producto === $itemId ? null : $itemId;

        $this->resetPage();
    }

    public function verTodosLosProductos(): void
    {
        $this->producto = null;

        $this->resetPage();
    }

    /**
     * Una fila por producto usado en el período —en el almacén elegido—,
     * del más usado al menos usado.
     *
     * CUATRO consultas en total, sin importar cuántos productos sean: los
     * totales por producto, el reparto por almacén, los nombres de los
     * almacenes y los productos. Un renglón que consultara su propio
     * reparto serían cien consultas en un mes movido (§13.2).
     *
     * @return list<array{id: int, nombre: string, codigo: string, infinito: bool, veces: int, cantidad: string, deDonde: string, activo: bool}>
     */
    public function productos(): array
    {
        $elegido = $this->productoElegido();
        $lista = [];

        foreach ($this->resumenPorProducto() as $fila) {
            $fila['activo'] = $fila['id'] === $elegido;
            $lista[] = $fila;
        }

        return $lista;
    }

    /**
     * El producto elegido, para el título del detalle.
     *
     * @return array{id: int, nombre: string, codigo: string, infinito: bool, veces: int, cantidad: string, deDonde: string, activo: bool}|null
     */
    public function productoActivo(): ?array
    {
        foreach ($this->productos() as $fila) {
            if ($fila['activo']) {
                return $fila;
            }
        }

        return null;
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consultaDeLaTabla())
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('cargos.ocurrido_en')
                ->orderByDesc('cargos.id'))
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Producto o paciente')
            ->columns([
                /*
                 * La hora en que se USÓ (`ocurrido_en`), que es la que
                 * importa para «qué se gastó en el turno de la noche».
                 */
                TextColumn::make('ocurrido_en')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->grow(false),

                /*
                 * Solo cuando se ven todos: con un producto elegido, la
                 * columna repetiría su nombre en cada renglón.
                 */
                TextColumn::make('item.nombre')
                    ->label('Producto')
                    ->description(fn (Cargo $record): string => (string) $record->item->codigo)
                    ->searchable()
                    ->wrap()
                    ->visible(fn (): bool => $this->productoElegido() === null),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->alignEnd()
                    ->weight('semibold')
                    ->formatStateUsing(fn (Cargo $record): string => self::conUnidad(
                        $record->cantidad,
                        $record->item->unidadDispensacion,
                    ))
                    ->grow(false),

                /*
                 * «De dónde se sacó», y debajo si descontó inventario o
                 * no: el lote cuando salió del kardex, «stock infinito»
                 * cuando no.
                 */
                TextColumn::make('almacen.nombre')
                    ->label('De dónde')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Sin estante')
                    ->description(fn (Cargo $record): string => self::origenDe($record))
                    ->grow(false),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    ->state(fn (Cargo $record): string => $record->encuentro->persona->nombreCompleto())
                    ->description(fn (Cargo $record): string => $record->cuenta->numero)
                    /*
                     * Contra `nombre_busqueda`, la columna generada de
                     * `personas`: sin tildes y en una sola pieza, la única
                     * forma de que «peña» encuentre a PENA.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'encuentro.persona',
                        fn (Builder $persona): Builder => $persona->where('nombre_busqueda', 'ilike', '%'.$search.'%'),
                    ))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('origen')
                    ->label('Origen')
                    ->native(false)
                    ->options([
                        'sin_inventario' => 'Stock infinito (no descontó)',
                        'inventario'     => 'Del inventario (descontó)',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $valor = $data['value'] ?? null;

                        if ($valor === 'sin_inventario') {
                            $query->whereNull('cargos.movimiento_id');
                        } elseif ($valor === 'inventario') {
                            $query->whereNotNull('cargos.movimiento_id');
                        }
                    }),
            ])
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Origen'))
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading('No se usó nada en el período')
            ->emptyStateDescription(
                'Acá aparece cada medicamento o material que se le cargó a un paciente. Si se cargó y '
                .'no lo ve, revise las fechas y el estante elegido arriba.'
            )
            ->recordActions([])
            ->toolbarActions([]);
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * Los cargos de productos que se almacenan, vivos, del período. Es la
     * única fuente de la pantalla.
     *
     * ⚠️ Pendiente o facturado, y nada más: `anulado` y `anulacion` son
     * el error y su corrección —se cancelan— y `trasladado` ya cuenta en
     * la cuenta a la que se fue.
     *
     * @return Builder<Cargo>
     */
    private function usos(): Builder
    {
        return Cargo::query()
            ->whereIn('cargos.estado', [EstadoCargo::Pendiente->value, EstadoCargo::Facturado->value])
            ->whereBetween('cargos.fecha_operacion', [$this->desdeEfectivo(), $this->hastaEfectivo()])
            ->whereHas('item', fn (Builder $item): Builder => $item->where('se_almacena', true));
    }

    /**
     * Los usos del almacén elegido, si hay uno.
     *
     * @return Builder<Cargo>
     */
    private function usosDelAlmacen(): Builder
    {
        $consulta = $this->usos();
        $almacen = $this->almacenElegido();

        if ($almacen === self::SIN_ALMACEN) {
            $consulta->whereNull('cargos.almacen_id');
        } elseif ($almacen !== null) {
            $consulta->where('cargos.almacen_id', $almacen);
        }

        return $consulta;
    }

    /**
     * ⚠️ Sin enumerar columnas en el `with`: una columna que falte no da
     * error, devuelve NULL y la pantalla miente en silencio (la lección
     * de Existencias).
     *
     * @return Builder<Cargo>
     */
    private function consultaDeLaTabla(): Builder
    {
        $consulta = $this->usosDelAlmacen()
            ->with(['item.unidadDispensacion', 'almacen', 'lote', 'cuenta', 'encuentro.persona']);

        $producto = $this->productoElegido();

        if ($producto !== null) {
            $consulta->where('cargos.item_id', $producto);
        }

        return $consulta;
    }

    /**
     * @return list<array{id: int, nombre: string, codigo: string, infinito: bool, veces: int, cantidad: string, deDonde: string}>
     */
    private function resumenPorProducto(): array
    {
        $clave = $this->desdeEfectivo().'|'.$this->hastaEfectivo().'|'.($this->almacenElegido() ?? 'todos');

        if ($this->resumen !== null && $this->resumenDe === $clave) {
            return $this->resumen;
        }

        $totales = $this->usosDelAlmacen()
            ->selectRaw('cargos.item_id, count(*) as veces, sum(cargos.cantidad) as cantidad')
            ->groupBy('cargos.item_id')
            ->toBase()
            ->get();

        if ($totales->isEmpty()) {
            $this->resumenDe = $clave;

            return $this->resumen = [];
        }

        /*
         * El reparto por almacén, para la línea «FARMACIA 30 · EMERGENCIA
         * 5». Sin el almacén elegido arriba sería redundante, pero igual
         * sale de la misma consulta y dice lo mismo.
         */
        $reparto = $this->usosDelAlmacen()
            ->selectRaw('cargos.item_id, cargos.almacen_id, sum(cargos.cantidad) as cantidad')
            ->groupBy('cargos.item_id', 'cargos.almacen_id')
            ->toBase()
            ->get();

        $idsDeAlmacen = $reparto->pluck('almacen_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $nombres = $idsDeAlmacen === []
            ? []
            : Almacen::query()
                ->deTodasLasSedes()
                ->whereIn('id', $idsDeAlmacen)
                ->pluck('nombre', 'id')
                ->all();

        $items = Item::query()
            ->with('unidadDispensacion')
            ->whereIn('id', $totales->pluck('item_id')->map(fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        /** @var array<int, list<array{nombre: string, cantidad: numeric-string}>> $porItem */
        $porItem = [];

        foreach ($reparto as $fila) {
            $porItem[(int) $fila->item_id][] = [
                'nombre'   => $fila->almacen_id === null ? 'SIN ESTANTE' : (string) ($nombres[(int) $fila->almacen_id] ?? '—'),
                'cantidad' => self::numero($fila->cantidad),
            ];
        }

        $filas = [];

        foreach ($totales as $total) {
            $item = $items->get((int) $total->item_id);

            if (! $item instanceof Item) {
                continue;
            }

            $donde = $porItem[(int) $item->id] ?? [];

            usort($donde, fn (array $a, array $b): int => bccomp($b['cantidad'], $a['cantidad'], 4));

            $filas[] = [
                'id'       => (int) $item->id,
                'nombre'   => $item->nombre,
                'codigo'   => (string) $item->codigo,
                'infinito' => $item->tieneStockInfinito(),
                'veces'    => (int) $total->veces,
                'cantidad' => self::conUnidad(self::numero($total->cantidad), $item->unidadDispensacion),
                'deDonde'  => implode(' · ', array_map(
                    fn (array $lugar): string => $lugar['nombre'].' '.self::sinCeros($lugar['cantidad']),
                    $donde,
                )),
            ];
        }

        /*
         * Del más usado al menos usado, y a igual uso por nombre. Por
         * VECES y no por cantidad: 500 ML de suero y 2 ampollas no se
         * comparan, pero cuántas veces se recurrió a cada uno sí.
         */
        usort($filas, fn (array $a, array $b): int => [$b['veces'], $a['nombre']] <=> [$a['veces'], $b['nombre']]);

        $this->resumenDe = $clave;

        return $this->resumen = $filas;
    }

    /**
     * @return array<int, int>
     */
    private function vecesPorAlmacen(): array
    {
        $clave = $this->desdeEfectivo().'|'.$this->hastaEfectivo();

        if ($this->veces !== null && $this->vecesDe === $clave) {
            return $this->veces;
        }

        $filas = $this->usos()
            ->selectRaw('cargos.almacen_id, count(*) as veces')
            ->groupBy('cargos.almacen_id')
            ->toBase()
            ->get();

        $veces = [];

        foreach ($filas as $fila) {
            $veces[$fila->almacen_id === null ? self::SIN_ALMACEN : (int) $fila->almacen_id] = (int) $fila->veces;
        }

        $this->vecesDe = $clave;

        return $this->veces = $veces;
    }

    /**
     * El almacén elegido, si tuvo usos en el período. Si no tuvo —se
     * eligió y después se cambiaron las fechas—, se ven todos: si no, la
     * pantalla quedaría vacía sin nada arriba que explique por qué.
     */
    private function almacenElegido(): ?int
    {
        return $this->almacen !== null && array_key_exists($this->almacen, $this->vecesPorAlmacen())
            ? $this->almacen
            : null;
    }

    /**
     * El producto elegido, si se usó en el período y en el almacén
     * elegido. Por la misma razón que `almacenElegido()`.
     */
    private function productoElegido(): ?int
    {
        if ($this->producto === null) {
            return null;
        }

        foreach ($this->resumenPorProducto() as $fila) {
            if ($fila['id'] === $this->producto) {
                return $this->producto;
            }
        }

        return null;
    }

    // ── Cómo se lee ───────────────────────────────────────────────────

    /**
     * «stock infinito» o «lote L-123»: si ese uso descontó inventario.
     */
    private static function origenDe(Cargo $cargo): string
    {
        if ($cargo->movimiento_id === null) {
            return 'Stock infinito';
        }

        return $cargo->lote instanceof Lote ? 'Lote '.$cargo->lote->numero : 'Del inventario';
    }

    /**
     * «35 TAB». Sin la unidad, el número no dice nada.
     */
    private static function conUnidad(string $cantidad, ?Unidad $unidad): string
    {
        $numero = self::sinCeros($cantidad);

        return $unidad instanceof Unidad ? $numero.' '.$unidad->codigo : $numero;
    }

    /**
     * Lo que devuelve `sum()` —int, float o string—, como cadena exacta.
     *
     * @return numeric-string
     */
    private static function numero(mixed $valor): string
    {
        if (is_string($valor) && is_numeric($valor)) {
            return Decimal::de($valor)->redondeado(4);
        }

        if (is_int($valor)) {
            return Decimal::de($valor)->redondeado(4);
        }

        if (is_float($valor)) {
            return Decimal::deFloat($valor)->redondeado(4);
        }

        return '0.0000';
    }

    private static function sinCeros(string $numero): string
    {
        if (! str_contains($numero, '.')) {
            return $numero;
        }

        $limpio = rtrim(rtrim($numero, '0'), '.');

        return $limpio === '' ? '0' : $limpio;
    }

    private static function enteroDeLaUrl(string $clave): ?int
    {
        $valor = request()->query($clave);

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }
}
