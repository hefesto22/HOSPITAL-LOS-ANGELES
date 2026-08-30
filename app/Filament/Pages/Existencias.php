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
use App\Models\Lote;
use App\Services\TrasladadorDeExistencias;
use App\Support\NumeroDeFormulario;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

        return Almacen::query()
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
            ->values()
            ->all();
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
            ->with([
                'item:id,codigo,nombre,es_controlado',
                'lote:id,numero,fecha_vencimiento',
                'almacen:id,codigo,nombre,tipo',
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
            TextColumn::make('item.nombre')
                ->label('Producto')
                ->searchable()
                ->sortable()
                ->weight('medium')
                ->description(fn (Existencia $record): ?string => $record->item?->codigo),

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

            TextColumn::make('cantidad')
                ->label('Cantidad')
                ->grow(false)
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn (Existencia $record): string => $record->cantidadDecimal()->redondeado(2)),
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
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Mover')
            /*
             * El mismo permiso que ajustar. Un traslado toca el kardex
             * igual que un ajuste, aunque no cambie el total del
             * hospital: quien puede corregir un saldo puede mover uno.
             * Un permiso propio sería una fila más en la matriz para
             * decir lo mismo.
             */
            ->visible(fn (): bool => Gate::allows('create', Ajuste::class))
            ->fillForm(fn (Existencia $record): array => [
                'origen' => $record->almacen?->etiqueta() ?? '—',
            ])
            ->schema(fn (Existencia $record): array => [
                TextInput::make('origen')
                    ->label('Sale de')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(self::dondeMasHay($record)),

                Select::make('destino_id')
                    ->label('Entra a')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(fn (): array => self::destinosPara($record))
                    ->helperText(
                        'Si el carrito no aparece en la lista, se crea primero en Inventario → '
                        .'Almacenes.'
                    ),

                TextInput::make('cantidad')
                    ->label('Cuánto se mueve')
                    ->required()
                    ->numeric()
                    ->minValue('0.0001')
                    ->step('0.0001')
                    /*
                     * El tope como CADENA y no como float: §8.6.2 prohíbe
                     * que una cantidad de inventario pase por punto
                     * flotante, y un tope mal redondeado deja fuera la
                     * última unidad del estante justo cuando se quiere
                     * vaciar.
                     *
                     * Igual no es el candado: el candado es el `UPDATE`
                     * condicional del movimiento. Esto es para que el
                     * error se vea antes de apretar.
                     */
                    ->maxValue(fn (): string => $record->cantidadDecimal()->redondeado(4))
                    ->helperText(
                        'Hay '.$record->cantidadDecimal()->redondeado(2).' en este lote. '
                        .'Lo que se mueve sigue siendo del hospital: no es una baja.'
                    ),

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

        $cantidad = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

        if (! $cantidad instanceof Decimal) {
            Notification::make()
                ->title('La cantidad no se entiende')
                ->body('Escriba cuántas unidades se mueven.')
                ->danger()
                ->send();

            return false;
        }

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
                $cantidad->redondeado(2).' de '.$item->nombre
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

        return Almacen::query()
            ->vigentes()
            ->where('sede_id', $origen->sede_id)
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
        /** @var Collection<int, Existencia> $otras */
        $otras = Existencia::query()
            ->with('almacen:id,nombre')
            ->where('item_id', $existencia->item_id)
            ->whereKeyNot($existencia->id)
            ->conSaldo()
            ->get();

        if ($otras->isEmpty()) {
            return 'Es lo único que hay de este producto en todo el hospital.';
        }

        /*
         * A mano y no con `reduce()`: sumar `Decimal` dentro de un
         * `reduce` obliga a un acumulador que puede ser nulo en la
         * primera vuelta, y el tipo se vuelve más difícil de leer que el
         * bucle que lo reemplaza.
         *
         * @var array<int, array{nombre: string, total: Decimal}> $porEstante
         */
        $porEstante = [];

        foreach ($otras as $fila) {
            $id = $fila->almacen_id;

            $porEstante[$id] ??= [
                'nombre' => $fila->almacen?->nombre ?? '—',
                'total'  => Decimal::cero(),
            ];

            $porEstante[$id]['total'] = $porEstante[$id]['total']->sumar($fila->cantidadDecimal());
        }

        $donde = implode(' · ', array_map(
            static fn (array $estante): string => $estante['total']->redondeado(2).' en '.$estante['nombre'],
            array_values($porEstante),
        ));

        return 'Además: '.$donde.'.';
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
