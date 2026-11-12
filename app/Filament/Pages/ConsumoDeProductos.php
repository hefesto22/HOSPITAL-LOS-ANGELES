<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navegacion\GrupoDelMenu;
use App\Filament\Pages\Concerns\ElijePeriodo;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Services\ConsumoDelPeriodo;
use App\Support\Periodo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
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
 *
 * Las consultas viven en `ConsumoDelPeriodo` desde el 11-nov-2026, cuando
 * el consumo pasó a imprimirse: la pantalla y el papel leen de ahí, y
 * esta página solo guarda lo elegido y dibuja.
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
    public const SIN_ALMACEN = ConsumoDelPeriodo::SIN_ALMACEN;

    /**
     * Cuántos productos se ven en el resumen antes de «Ver todos».
     */
    public const PRODUCTOS_A_LA_VISTA = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?string $slug = 'consumo-de-productos';

    protected static ?int $navigationSort = 3;

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
        return GrupoDelMenu::Reportes->value;
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

    // ── El papel ──────────────────────────────────────────────────────

    /**
     * «Imprimir», arriba a la derecha (Mauricio, 11-nov-2026: «que se pueda
     * imprimir»). Como el Reporte del mes: la hoja en un modal y la ventana
     * de imprimir sale sola, con «Solo el resumen» o «Con cada uso». Sale
     * lo que se está mirando: el período, el estante, el producto y el
     * filtro «Origen».
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->modalHeading(fn (): string => 'Consumo de productos · '.$this->periodo())
                ->modalDescription('La ventana de imprimir se abre sola. Si la cerrás, «Imprimir» la vuelve a abrir.')
                ->modalWidth('7xl')
                ->modalContent(fn (): View|HtmlString => $this->sePuedeImprimir()
                    ? view('honorarios.modal', [
                        'enlace'             => $this->enlaceDelReporte(incrustada: true),
                        'enlaceConDetalle'   => $this->enlaceDelReporte(incrustada: true, conDetalle: true),
                        'etiquetaConDetalle' => 'Con cada uso',
                        'titulo'             => 'Consumo de productos · '.$this->periodo(),
                    ])
                    : new HtmlString('<p>El consumo impreso cubre como máximo un año: elegí un período más corto.</p>'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar'),
        ];
    }

    /**
     * La hoja de lo que se está mirando. Público para probarlo; solo arma
     * una URL que igual pide el permiso al abrirse.
     *
     * El estante «Sin estante» es el 0, y viaja: el filtro quita solo lo
     * nulo.
     */
    public function enlaceDelReporte(bool $incrustada = false, bool $conDetalle = false): string
    {
        $origen = $this->getTableFilterState('origen')['value'] ?? null;

        return route('reportes.consumo', array_filter([
            'desde'      => $this->desdeEfectivo(),
            'hasta'      => $this->hastaEfectivo(),
            'almacen'    => $this->almacenElegido(),
            'producto'   => $this->productoElegido(),
            'origen'     => is_string($origen) && $origen !== '' ? $origen : null,
            'detalle'    => $conDetalle ? 1 : null,
            'incrustada' => $incrustada ? 1 : null,
        ], fn (mixed $valor): bool => $valor !== null));
    }

    public function sePuedeImprimir(): bool
    {
        return Periodo::dias($this->desdeEfectivo(), $this->hastaEfectivo()) <= ConsumoDelPeriodo::DIAS_MAXIMOS;
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

        $lista = [];

        foreach (app(ConsumoDelPeriodo::class)->almacenes($veces) as $almacen) {
            $almacen['activo'] = $elegido === $almacen['id'];
            $lista[] = $almacen;
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
                    ->formatStateUsing(fn (Cargo $record): string => ConsumoDelPeriodo::conUnidad(
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
                    ->description(fn (Cargo $record): string => ConsumoDelPeriodo::origenDe($record))
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
                        ConsumoDelPeriodo::filtrarPorOrigen($query, $data['value'] ?? null);
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
     * Los usos del almacén elegido, si hay uno (ver `ConsumoDelPeriodo`).
     *
     * @return Builder<Cargo>
     */
    private function usosDelAlmacen(): Builder
    {
        return app(ConsumoDelPeriodo::class)->usosDelAlmacen(
            $this->desdeEfectivo(),
            $this->hastaEfectivo(),
            $this->almacenElegido(),
        );
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

        $this->resumenDe = $clave;

        return $this->resumen = app(ConsumoDelPeriodo::class)->porProducto(
            $this->desdeEfectivo(),
            $this->hastaEfectivo(),
            $this->almacenElegido(),
        );
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

        $this->vecesDe = $clave;

        return $this->veces = app(ConsumoDelPeriodo::class)->vecesPorAlmacen(
            $this->desdeEfectivo(),
            $this->hastaEfectivo(),
        );
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

    private static function enteroDeLaUrl(string $clave): ?int
    {
        $valor = request()->query($clave);

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }
}
