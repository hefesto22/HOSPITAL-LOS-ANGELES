<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\CategoriaItem;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\AjustadorDeBaseDePrecios;
use App\Services\CopiadorDeBaseDePrecios;
use App\Services\PreciosDeLaFicha;
use App\Support\NumeroDeFormulario;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Todos los precios de un pagador, en una sola tabla.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTA PANTALLA EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los precios ya se podían cargar: entrando al ítem, pestaña de precios,
 * botón de fijar. Para uno está bien. Para firmar con una aseguradora
 * nueva —que son ciento treinta ítems— es ciento treinta veces el mismo
 * viaje, y a la mitad alguien se cansa: quedan sesenta ítems sin precio,
 * y eso se descubre a las once de la noche cuando el mostrador dice «este
 * ítem no tiene precio para este pagador».
 *
 * Acá se elige el pagador arriba y se ven sus precios, editables en la
 * propia fila.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES VISTAS POR BASE, Y CADA UNA DICE CUÁNTAS FILAS TRAE (6-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * No son la misma clase de cosa y por eso no se miran igual:
 *
 *   · EL PRECIO DE LISTA es obligatorio: todo lo que el hospital ofrece
 *     tiene que tener uno. Arranca en «Pendientes», que es el trabajo
 *     que falta. El centinela de L 10 («PENDIENTE PRECIO DE LISTA») CUENTA
 *     COMO PENDIENTE: antes la pantalla decía «285 con precio, 0 sin
 *     precio» con casi todo el catálogo en L 10, y lo único accionable
 *     quedaba escondido detrás de un número que tranquilizaba.
 *
 *   · LA BASE DE UN SEGURO es un acuerdo firmado y muestra SOLO LO
 *     PACTADO. Lo que no está pactado NO es un hueco: se cobra al precio
 *     de lista. Para sumar algo nuevo está «Agregar ítem», que es un acto
 *     deliberado: se elige el ítem y se escribe SU precio.
 *
 * ⚠️ Se probó una vista «Sin pactar» (y «Todos») en el seguro, con el
 * campo vacío para pactar tecleando. Mauricio la quitó el mismo día
 * (6-nov-2026): «es confuso; solo los pactados, y si quieren agregar
 * algo nuevo lo agregan». No volverla a poner sin que él la pida.
 *
 * El campo de precio guarda al salir y Enter salta a la fila siguiente
 * (ver la vista), así que cargar una base es escribir, Enter, escribir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA COLUMNA «VS LISTA» NO ES UN ADORNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es el control de sanidad: si un ítem quedó 60 % por debajo de la lista
 * mientras el resto está en 15 %, o alguien tecleó mal o ese ítem se
 * negoció aparte. En una tabla de ciento treinta filas, esa columna es
 * lo que hace que el error salte a la vista en vez de aparecer en la
 * conciliación a sesenta días.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UN RESOURCE (§9.A10)
 * ─────────────────────────────────────────────────────────────────────
 *
 * El registro que se edita es `Tarifario`, pero lo que se lista son
 * ÍTEMS —incluidos los que todavía no tienen tarifario—. Un Resource de
 * tarifarios no podría mostrar lo que no existe, que es justamente lo
 * que hay que ver.
 */
class BasesDePrecios extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $slug = 'bases-de-precios';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.bases-de-precios';

    /**
     * Qué pagador se está mirando. `null` = el precio de lista del
     * hospital, que es el que responde cuando nadie negoció nada.
     */
    public ?int $convenioId = null;

    /**
     * Qué filas se ven dentro de la base. Vacío = la vista con la que
     * arranca cada base (ver `vistaActual()`).
     */
    public string $vista = '';

    /**
     * La categoría elegida en las fichas de arriba. `null` = todas.
     */
    public ?int $categoriaId = null;

    private const VISTAS_DE_LA_LISTA = [
        'pendientes' => 'Pendientes',
        'reales'     => 'Con precio',
        'todos'      => 'Todos',
    ];

    /**
     * Una sola: el seguro muestra lo pactado y nada más (ver arriba).
     */
    private const VISTAS_DE_UN_SEGURO = [
        'pactados' => 'Pactados',
    ];

    public static function getNavigationLabel(): string
    {
        return 'Bases de precios';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public function getTitle(): string
    {
        return 'Bases de precios';
    }

    public function getSubheading(): string
    {
        return $this->convenioId === null
            ? 'El catálogo completo con el precio del hospital. Se edita en la propia fila.'
            : 'Lo pactado con este pagador. Lo que no está acá se cobra al precio de lista.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Item::class);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL `?base=` DE LA URL
     * ─────────────────────────────────────────────────────────────────
     *
     * Es lo que permite llegar acá desde el listado de seguros con el
     * pagador ya elegido, y desde el alta apenas se heredó el catálogo.
     * Sin eso, quien acaba de crear PALIG cae en la pestaña del precio
     * de lista y tiene que volver a buscarlo entre veinte.
     *
     * Se lee a mano y no con `#[Url]`: Livewire asigna el valor crudo de
     * la query string a la propiedad tipada, y un `?base=palig` escrito
     * a mano —o pegado de un chat— reventaría con un TypeError en vez de
     * simplemente ignorarse.
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $base = request()->query('base');

        if (is_string($base) && ctype_digit($base)) {
            $this->convenioId = (int) $base;
        }
    }

    // ── El selector de base ───────────────────────────────────────────

    /**
     * Las pestañas de arriba: la lista primero y después cada pagador
     * con tarifario propio, cada uno con cuántos ítems tiene cargados.
     *
     * @return list<array{id: int|null, nombre: string, cuantos: int}>
     */
    public function bases(): array
    {
        $copiador = app(CopiadorDeBaseDePrecios::class);

        /*
         * Un conteo por pestaña sería una consulta por pestaña, y esto
         * se dibuja en cada pintada de la pantalla.
         */
        $conteos = $copiador->conteosPorPagador();

        $bases = [[
            'id'      => null,
            'nombre'  => 'PRECIO DE LISTA · PARTICULAR',
            'cuantos' => $copiador->cuantosTienenPrecio(null, sinHonorarios: true),
        ]];

        foreach ($this->pagadores() as $convenio) {
            $cuantos = $conteos[$convenio->id] ?? 0;

            /*
             * 🔴 QUIEN NO PAGA UN TERCERO NO LLEVA PESTAÑA MIENTRAS ESTÉ
             * VACÍA — y son dos: el contado y el seguro externo.
             *
             * En los dos, el precio ES el de lista y la primera pestaña
             * ya lo dice. Con una pestaña aparte alguien le teclea un
             * precio, y a partir de ese día el particular y la lista
             * dejan de ser lo mismo sin que nadie lo haya decidido.
             *
             * ⚠️ Si YA tiene precios cargados sí se muestra. Esconder
             * datos que existen es peor que la pestaña de más: quedan
             * cobrando sin que se puedan ver ni corregir.
             */
            if (! $convenio->tipo->pagaUnTercero() && $cuantos === 0) {
                continue;
            }

            $bases[] = [
                'id'      => $convenio->id,
                'nombre'  => $convenio->nombre,
                'cuantos' => $cuantos,
            ];
        }

        return $bases;
    }

    public function cambiarDeBase(?int $convenioId): void
    {
        $this->convenioId = $convenioId;
        $this->vista = '';
        $this->categoriaId = null;

        $this->resetTable();
    }

    // ── Las vistas y las categorías ───────────────────────────────────

    /**
     * La lista arranca en lo pendiente; un seguro, en lo pactado. Un
     * valor que no corresponde a la base —el de la otra, o uno tecleado
     * desde el navegador— vuelve al de arranque en vez de romper.
     */
    public function vistaActual(): string
    {
        $validas = $this->convenioId === null ? self::VISTAS_DE_LA_LISTA : self::VISTAS_DE_UN_SEGURO;

        return array_key_exists($this->vista, $validas) ? $this->vista : (string) array_key_first($validas);
    }

    public function cambiarDeVista(string $vista): void
    {
        $this->vista = $vista;

        $this->resetTable();
    }

    public function cambiarDeCategoria(?int $categoriaId): void
    {
        $this->categoriaId = $categoriaId;

        $this->resetTable();
    }

    /**
     * Las fichas de arriba, cada una con cuántas filas trae dentro de la
     * categoría elegida. «Pendientes» con algo adentro va en alerta: cada
     * uno es un ítem que en caja no se puede cobrar a un particular.
     *
     * @return list<array{clave: string, rotulo: string, cuantos: int, activa: bool, alerta: bool}>
     */
    public function vistas(): array
    {
        $validas = $this->convenioId === null ? self::VISTAS_DE_LA_LISTA : self::VISTAS_DE_UN_SEGURO;
        $actual = $this->vistaActual();
        $vistas = [];

        foreach ($validas as $clave => $rotulo) {
            $cuantos = $this->filas($clave, $this->categoriaId)->count();

            $vistas[] = [
                'clave'   => $clave,
                'rotulo'  => $rotulo,
                'cuantos' => $cuantos,
                'activa'  => $clave === $actual,
                'alerta'  => $clave === 'pendientes' && $cuantos > 0,
            ];
        }

        return $vistas;
    }

    /**
     * Las categorías que tienen algo en la vista elegida, con cuántos.
     *
     * Dos consultas y no un JOIN: `vigentesEn()` filtra por columnas sin
     * calificar y `categorias_item` también tiene `vigencia_desde`, así
     * que el JOIN las volvería ambiguas.
     *
     * @return list<array{id: int, nombre: string, cuantos: int}>
     */
    public function categorias(): array
    {
        /** @var array<int, int> $conteos */
        $conteos = $this->filas($this->vistaActual(), null)
            ->toBase()
            ->whereNotNull('items.categoria_id')
            ->groupBy('items.categoria_id')
            ->pluck(DB::raw('count(*) as cuantos'), 'items.categoria_id')
            ->map(fn (mixed $cuantos): int => (int) $cuantos)
            ->all();

        /*
         * La elegida se sigue mostrando aunque en esta vista quede en
         * cero: si desapareciera, no habría ficha encendida y la tabla
         * vacía no diría por qué.
         */
        $ids = array_keys($conteos);

        if ($this->categoriaId !== null && ! in_array($this->categoriaId, $ids, true)) {
            $ids[] = $this->categoriaId;
        }

        if ($ids === []) {
            return [];
        }

        return array_values(CategoriaItem::query()
            ->whereIn('id', $ids)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (CategoriaItem $categoria): array => [
                'id'      => $categoria->id,
                'nombre'  => $categoria->nombre,
                'cuantos' => $conteos[$categoria->id] ?? 0,
            ])
            ->all());
    }

    /**
     * @return Collection<int, Convenio>
     */
    private function pagadores(): Collection
    {
        return Convenio::query()
            ->orderBy('nombre')
            ->get();
    }

    public function baseActual(): ?Convenio
    {
        if ($this->convenioId === null) {
            return null;
        }

        $convenio = Convenio::query()->find($this->convenioId);

        return $convenio instanceof Convenio ? $convenio : null;
    }

    public function nombreDeLaBase(): string
    {
        return $this->baseActual()->nombre ?? 'PRECIO DE LISTA';
    }

    /**
     * Los dos números que hay que poder leer sin contar filas: cuánto
     * está cargado y cuánto falta.
     *
     * El que importa es el SEGUNDO. «Ciento treinta con precio» tranquiliza;
     * «ocho sin precio» es lo accionable, porque cada uno de esos ocho es
     * una discusión en el mostrador esperando a que alguien lo pida.
     *
     * @return array{conPrecio: int, sinPrecio: int, total: int}
     */
    public function resumenDeLaBase(): array
    {
        /*
         * El total sigue el mismo recorte que la tabla: si la pantalla no
         * lista farmacia para un convenio, contarla en el denominador
         * dejaría un «sin precio» que nunca puede llegar a cero.
         */
        $total = Item::query()
            ->vigentesEn(now())
            ->when($this->convenioId !== null, fn (Builder $q): Builder => $q->where('se_almacena', false))
            ->when($this->convenioId === null, fn (Builder $q): Builder => $q->whereRaw(Item::VA_EN_LA_LISTA))
            ->count();

        $conPrecio = app(CopiadorDeBaseDePrecios::class)
            ->cuantosTienenPrecio($this->baseActual(), sinHonorarios: $this->convenioId === null);

        return [
            'total'     => $total,
            'conPrecio' => $conPrecio,
            'sinPrecio' => max(0, $total - $conPrecio),
        ];
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consulta())
            ->defaultSort('items.codigo')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Item $record): string => $record->tipo->etiqueta()),

                TextColumn::make('unidadDispensacion.simbolo')
                    ->label('Unidad')
                    ->placeholder('—')
                    ->toggleable(),

                /*
                 * El precio, editable acá mismo. `TextInputColumn` guarda
                 * al salir del campo; `updateStateUsing` es donde se
                 * decide si eso fue una corrección o un cambio de precio.
                 */
                TextInputColumn::make('precio_de_la_base')
                    ->label(fn (): string => $this->convenioId === null ? 'Precio de lista' : 'Precio pactado')
                    ->placeholder('pendiente')
                    ->type('number')
                    ->extraInputAttributes(['step' => '0.0001', 'min' => '0', 'class' => 'text-right'])
                    ->updateStateUsing(fn (Item $record, mixed $state): mixed => $this->guardar($record, $state)),

                TextColumn::make('precio_de_lista')
                    ->label('Precio de lista')
                    ->alignEnd()
                    ->placeholder('pendiente')
                    ->formatStateUsing(fn (mixed $state): string => $state === null
                        ? '—'
                        : number_format((float) $state, 2))
                    ->visible(fn (): bool => $this->convenioId !== null)
                    ->toggleable(),

                TextColumn::make('diferencia')
                    ->label('vs lista')
                    ->alignEnd()
                    ->state(fn (Item $record): string => $this->diferencia($record))
                    ->badge()
                    ->color(fn (Item $record): string => $this->colorDeLaDiferencia($record))
                    ->visible(fn (): bool => $this->convenioId !== null),
            ])
            /*
             * Sin filtros: las vistas y las categorías van arriba, a un
             * clic y con su número. Escondidos en el embudo no los usaba
             * nadie.
             */
            /*
             * La contraparte de «Agregar»: sacar un ítem de la base de un
             * pagador. Solo del lado de un pagador —del precio de lista
             * no se saca nada: un ítem sin precio de lista no se puede
             * cobrar en ninguna parte—.
             */
            ->recordActions([
                Action::make('quitarDeLaBase')
                    ->label('Quitar')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Sacarlo de esta base: vuelve a cobrarse al precio de lista.')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Item $record): string => 'Quitar '.$record->codigo.' de esta base')
                    ->modalDescription(fn (): string => 'Desde hoy se vuelve a cobrar al precio de lista. '
                        .'Las facturas ya emitidas no cambian: cada cargo lleva su propio precio congelado.')
                    ->modalSubmitActionLabel('Quitar')
                    ->visible(fn (): bool => $this->convenioId !== null)
                    ->action(fn (Item $record) => $this->quitar($record)),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Item>
     */
    private function consulta(): Builder
    {
        $query = $this->filas($this->vistaActual(), $this->categoriaId)->with('unidadDispensacion');

        /*
         * Subconsultas y no JOIN: un ítem puede tener varias filas de
         * tarifario —una por vigencia— y con JOIN cada ítem aparecería
         * repetido. `addSelect` con `limit(1)` trae exactamente el
         * vigente y deja una fila por ítem, que es lo que la pantalla
         * necesita.
         */
        $query->addSelect('items.*');

        /*
         * `trim_scale` y no `precio` pelado: la columna es numeric(14,4)
         * y PostgreSQL devuelve SIEMPRE los cuatro decimales, así que la
         * tabla se llenaba de «1080.0000». Cuatro decimales existen
         * porque un mililitro de una solución puede costar centésimas de
         * lempira, pero mostrárselos a quien carga una consulta de 1080
         * es ruido — y ruido en una columna de dinero es donde se cuelan
         * los ceros de más.
         *
         * `trim_scale` recorta solo los decimales que sobran: 1080.0000
         * queda «1080» y 283.3305 queda intacto. Es presentación, no
         * redondeo: lo guardado no se toca.
         */
        $query->addSelect(['precio_de_la_base' => Tarifario::query()
            ->selectRaw('trim_scale(tarifarios.precio)')
            ->whereColumn('tarifarios.item_id', 'items.id')
            ->when(
                $this->convenioId === null,
                fn ($sub) => $sub->whereNull('tarifarios.convenio_id'),
                fn ($sub) => $sub->where('tarifarios.convenio_id', $this->convenioId),
            )
            ->whereNull('tarifarios.sede_id')
            /*
             * En la lista, el centinela de L 10 sale como campo VACÍO con
             * «pendiente»: un 10 en la casilla parece un precio y se le
             * escribe al lado en vez de encima.
             */
            ->when($this->convenioId === null, fn ($sub) => $this->sinElCentinela($sub))
            ->vigentesEn(now())
            ->limit(1),
        ]);

        /*
         * Igual para la columna de referencia: contra L 10 la diferencia
         * daba «+9900 %», que no le dice nada a nadie.
         */
        $query->addSelect(['precio_de_lista' => Tarifario::query()
            ->selectRaw('trim_scale(tarifarios.precio)')
            ->whereColumn('tarifarios.item_id', 'items.id')
            ->whereNull('tarifarios.convenio_id')
            ->whereNull('tarifarios.sede_id')
            ->tap(fn ($sub) => $this->sinElCentinela($sub))
            ->vigentesEn(now())
            ->limit(1),
        ]);

        return $query;
    }

    /**
     * Las filas de una vista, sin columnas: lo usan la tabla y los
     * conteos de las fichas, y por eso cuentan lo mismo que se ve.
     *
     * @return Builder<Item>
     */
    private function filas(string $vista, ?int $categoriaId): Builder
    {
        $query = Item::query()->vigentesEn(now());

        if ($this->convenioId !== null) {
            /*
             * A un seguro no se le pacta precio de medicamentos: paga el
             * de lista, que se recalcula solo con cada compra. La regla
             * de verdad la impone `FijadorDePrecio`; esto evita ofrecer
             * el intento.
             */
            $query->where('items.se_almacena', false);
        } else {
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 LOS HONORARIOS DE MOSTRADOR NO VAN EN LA LISTA (6-nov-2026)
             * ─────────────────────────────────────────────────────────
             *
             * El HONORARIO MÉDICO genérico se le cobra al particular con
             * el número que se escribe al cargarlo —lo que cobra ESE
             * médico—. Acá quedaba para siempre en «Pendientes» pidiendo
             * un precio que nadie iba a usar.
             *
             * Los de una especialidad SÍ van: «consulta externa según el
             * doctor que tenga esa especialidad». Su precio de lista es el
             * que se le propone al particular para todos sus médicos.
             *
             * En la base de un seguro aparecen todos: ahí está lo pactado.
             */
            $query->whereRaw(Item::VA_EN_LA_LISTA);
        }

        if ($vista === 'pendientes') {
            $query->whereNotExists(fn ($sub) => $this->sinElCentinela($this->subconsultaDePrecio($sub, null)));

            /*
             * 🔴 UN HONORARIO SIN PRECIO DE LISTA NO ESTÁ PENDIENTE
             * (Mauricio, 8-nov-2026: «es a gusto del usuario al
             * agregarlo»). La consulta de una especialidad se cobra según
             * el doctor: sin lista, en la cuenta se escribe el monto. Se
             * ve en «Todos» para ponerle uno si se quiere, pero no pide
             * nada.
             */
            $query->where('items.tipo', '!=', TipoItem::Honorario->value);
        } elseif ($vista === 'reales') {
            $query->whereExists(fn ($sub) => $this->sinElCentinela($this->subconsultaDePrecio($sub, null)));
        } elseif ($vista === 'pactados') {
            $query->whereExists(fn ($sub) => $this->subconsultaDePrecio($sub, $this->convenioId));
        }

        if ($categoriaId !== null) {
            $query->where('items.categoria_id', $categoriaId);
        }

        return $query;
    }

    /**
     * Deja afuera el precio de lista que nadie fijó (L 10 con el motivo
     * «PENDIENTE PRECIO DE LISTA»). Para esta pantalla ese ítem NO tiene
     * precio: en caja no se le puede cobrar a un particular.
     *
     * @template TSub of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<Tarifario>
     *
     * @param TSub $sub
     *
     * @return TSub
     */
    private function sinElCentinela($sub)
    {
        $sub->whereRaw('upper(trim(tarifarios.motivo)) not like ?', [PreciosDeLaFicha::MARCA_PENDIENTE.'%']);

        return $sub;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $sub
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function subconsultaDePrecio($sub, ?int $convenioId)
    {
        $sub->select(DB::raw('1'))
            ->from('tarifarios')
            ->whereColumn('tarifarios.item_id', 'items.id')
            ->whereNull('tarifarios.sede_id')
            ->whereNull('tarifarios.deleted_at')
            ->whereRaw('tarifarios.vigencia @> ?::date', [now()->toDateString()]);

        return $convenioId === null
            ? $sub->whereNull('tarifarios.convenio_id')
            : $sub->where('tarifarios.convenio_id', $convenioId);
    }

    /**
     * Lo que pasa al salir del campo de precio.
     */
    private function guardar(Item $item, mixed $state): mixed
    {
        abort_unless(Gate::allows('update', $item), 403);

        $numero = NumeroDeFormulario::aDecimal($state);

        if (! $numero instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('No se entiende ese precio')
                ->body('Escribí solo números, con punto para los decimales. Ejemplo: 1250.50')
                ->send();

            return null;
        }

        if ($numero->esNegativo()) {
            Notification::make()
                ->danger()
                ->title('El precio no puede ser negativo')
                ->body('Un precio en cero sí se puede: es una cortesía declarada. Uno negativo sería el hospital pagándole al paciente.')
                ->send();

            return null;
        }

        try {
            app(AjustadorDeBaseDePrecios::class)->ajustar(
                item: $item,
                convenio: $this->baseActual(),
                precio: Monto::de($numero->redondeado(4)),
                motivo: 'Precio fijado desde la base de precios de '.$this->nombreDeLaBase().'.',
            );
        } catch (PrecioNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo fijar el precio')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return null;
        }

        /*
         * Corto y sin cuerpo: cargando una base se guardan decenas
         * seguidas, y un aviso de seis segundos por cada una tapaba la
         * pantalla. Los errores sí se quedan.
         */
        Notification::make()
            ->success()
            ->title('✓ '.$item->codigo)
            ->duration(1500)
            ->send();

        return $numero->redondeado(4);
    }

    private function diferencia(Item $item): string
    {
        $base = $item->getAttribute('precio_de_la_base');
        $lista = $item->getAttribute('precio_de_lista');

        if ($base === null) {
            return '—';
        }

        if ($lista === null) {
            return 'lista pendiente';
        }

        if (Decimal::de((string) $lista)->esCero()) {
            return '—';
        }

        $porcentaje = Decimal::de((string) $base)
            ->entre(Decimal::de((string) $lista))
            ->restar('1')
            ->por('100');

        $signo = $porcentaje->esNegativo() ? '' : '+';

        return $signo.$porcentaje->redondeado(1).' %';
    }

    private function colorDeLaDiferencia(Item $item): string
    {
        $texto = $this->diferencia($item);

        if ($texto === '—' || $texto === 'lista pendiente') {
            return 'gray';
        }

        return str_starts_with($texto, '-') ? 'warning' : 'success';
    }

    // ── Sumar un ítem a la base de un pagador ─────────────────────────

    /**
     * «Agregar ítem»: la única puerta para que algo entre a la base de un
     * pagador.
     *
     * Antes esa puerta era cada fila vacía de una tabla de cien —lo cual
     * significa que no había puerta, había cien—. Acá se elige el ítem y
     * se escribe su precio en el mismo acto, que es como se firma un
     * tarifario: renglón por renglón y a propósito.
     *
     * ⚠️ El desplegable solo ofrece lo que TODAVÍA NO está en la base.
     * Ofrecer algo que ya tiene precio invitaría a pisarlo desde acá, sin
     * ver el número anterior ni la diferencia contra la lista, que es
     * justo para lo que sirve la tabla de al lado.
     */
    public function agregarItemAction(): Action
    {
        return Action::make('agregarItem')
            ->label('Agregar ítem')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn (): bool => $this->convenioId !== null && Gate::allows('create', Item::class))
            ->modalHeading(fn (): string => 'Agregar a la base de '.$this->nombreDeLaBase())
            ->modalDescription(
                'Se suma el ítem a esta base con el precio que se le pactó a este pagador. '
                .'Lo que no está en la base se sigue cobrando al precio de lista.'
            )
            ->modalSubmitActionLabel('Agregar')
            ->schema([
                Select::make('item_id')
                    ->label('¿Qué ítem?')
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->options(fn (): array => $this->opcionesParaAgregar())
                    ->helperText('Solo aparece lo que todavía no tiene precio en esta base.')
                    /*
                     * Elegido el ítem, el cursor ya queda en el precio:
                     * se elige, se escribe, Enter. Del lado del navegador,
                     * sin ir al servidor.
                     */
                    ->afterStateUpdatedJs(<<<'JS'
                        setTimeout(() => document.getElementById('precio-nuevo-de-la-base')?.focus(), 50)
                        JS),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 TEXTO, NO EL CAMPO NUMÉRICO DEL NAVEGADOR (8-nov-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Mauricio: «si se quiere agregar algo no deja colocarle
                 * precio» — tecleaba y el campo quedaba vacío. El campo
                 * numérico del navegador decide qué teclas acepta según
                 * el idioma de Chrome y del teclado, y lo que rechaza no
                 * lo muestra ni avisa. Como texto se escribe siempre, y lo
                 * escrito lo interpreta `agregar()`: si no se entiende, lo
                 * dice.
                 */
                TextInput::make('precio')
                    ->id('precio-nuevo-de-la-base')
                    ->label('Precio para este pagador')
                    ->required()
                    ->inputMode('decimal')
                    ->autocomplete(false)
                    ->placeholder('Ej. 1250.50')
                    ->prefix('L')
                    ->helperText('Cero se puede: es una cortesía declarada. Negativo no.'),
            ])
            ->action(function (array $data): void {
                $this->agregar($data);
            });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function agregar(array $data): void
    {
        $convenio = $this->baseActual();

        if (! $convenio instanceof Convenio) {
            return;
        }

        $item = Item::query()->find(is_numeric($data['item_id'] ?? null) ? (int) $data['item_id'] : 0);

        if (! $item instanceof Item) {
            return;
        }

        abort_unless(Gate::allows('update', $item), 403);

        $numero = NumeroDeFormulario::aDecimal(self::sinSeparadorDeMiles($data['precio'] ?? null));

        if (! $numero instanceof Decimal || $numero->esNegativo()) {
            Notification::make()
                ->danger()
                ->title('Ese precio no se entiende')
                ->body('Escribí solo números, con punto para los decimales. Ejemplo: 1250.50')
                ->send();

            return;
        }

        try {
            app(AjustadorDeBaseDePrecios::class)->ajustar(
                item: $item,
                convenio: $convenio,
                precio: Monto::de($numero->redondeado(4)),
                motivo: 'Ítem agregado a la base de '.$convenio->nombre.'.',
            );
        } catch (PrecioNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo agregar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->resetTable();

        Notification::make()
            ->success()
            ->title($item->codigo.' agregado')
            ->body('Ya tiene precio propio en '.$convenio->nombre.'.')
            ->send();
    }

    /**
     * «L 1,250.50» → «1250.50». El campo es de texto, y escrito así la
     * coma es de miles: sin quitarla, `NumeroDeFormulario` —que acepta la
     * coma decimal— leería «1,000» como L 1. Solo se quita cuando separa
     * miles de verdad (grupos de tres); «1250,50» sigue siendo decimal.
     */
    private static function sinSeparadorDeMiles(mixed $escrito): mixed
    {
        if (! is_string($escrito)) {
            return $escrito;
        }

        $limpio = trim(str_ireplace(['L.', 'L', ' '], '', $escrito));

        return preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $limpio) === 1
            ? str_replace(',', '', $limpio)
            : $limpio;
    }

    private function quitar(Item $item): void
    {
        $convenio = $this->baseActual();

        if (! $convenio instanceof Convenio) {
            return;
        }

        abort_unless(Gate::allows('update', $item), 403);

        $quitado = app(AjustadorDeBaseDePrecios::class)->quitar(
            item: $item,
            convenio: $convenio,
            motivo: 'Retirado de la base de '.$convenio->nombre.'.',
        );

        $this->resetTable();

        if (! $quitado) {
            Notification::make()
                ->warning()
                ->title('No había nada que quitar')
                ->body($item->codigo.' ya no tenía precio vigente en '.$convenio->nombre.'.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($item->codigo.' quitado')
            ->body('Desde hoy se cobra al precio de lista cuando el paciente viene por '.$convenio->nombre.'.')
            ->send();
    }

    /**
     * Lo que se puede agregar: vigente, de lo que no lleva existencia, y
     * todavía sin precio en esta base.
     *
     * ⚠️ Farmacia no entra, por lo mismo que no se lista: a un seguro no
     * se le pacta el precio de un medicamento, que se recalcula solo con
     * cada compra. `FijadorDePrecio` lo rechazaría igual; esto es para
     * que no haga falta intentarlo.
     *
     * @return array<int, string>
     */
    private function opcionesParaAgregar(): array
    {
        return Item::query()
            ->vigentesEn(now())
            ->where('se_almacena', false)
            ->when($this->convenioId === null, fn (Builder $q): Builder => $q->whereRaw(Item::VA_EN_LA_LISTA))
            ->whereNotExists(fn ($sub) => $this->subconsultaDePrecio($sub, $this->convenioId))
            ->orderBy('codigo')
            ->get()
            ->mapWithKeys(fn (Item $item): array => [
                $item->getKey() => $item->codigo.' · '.$item->nombre,
            ])
            ->all();
    }

    // ── Copiar una base entera ────────────────────────────────────────

    public function copiarBaseAction(): Action
    {
        return Action::make('copiarBase')
            ->label('Copiar desde otra base')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('primary')
            ->visible(fn (): bool => $this->convenioId !== null && Gate::allows('create', Item::class))
            ->modalHeading(fn (): string => 'Armar la base de '.$this->nombreDeLaBase())
            ->modalDescription(
                'Crea los precios que falten a partir de otra base, aplicándoles un porcentaje. '
                .'Lo que ya tenga precio cargado NO se toca.'
            )
            ->modalSubmitActionLabel('Copiar')
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('origen')
                            ->label('¿De qué base se parte?')
                            ->native(false)
                            ->required()
                            ->default(CopiadorDeBaseDePrecios::ORIGEN_LISTA)
                            ->options(fn (): array => $this->opcionesDeOrigen())
                            ->helperText('El precio de lista es el del hospital, sin pagador de por medio.'),

                        TextInput::make('porcentaje')
                            ->label('¿Qué porcentaje del origen?')
                            ->numeric()
                            ->required()
                            ->default(100)
                            ->minValue(1)
                            ->maxValue(500)
                            ->suffix('%')
                            ->helperText('100 = el mismo precio. 85 = un 15 % menos. 120 = un 20 % más.'),
                    ]),
            ])
            ->action(function (array $data): void {
                $destino = $this->baseActual();

                if (! $destino instanceof Convenio) {
                    return;
                }

                abort_unless(Gate::allows('create', Item::class), 403);

                $porcentaje = NumeroDeFormulario::aDecimal($data['porcentaje'] ?? null);

                if (! $porcentaje instanceof Decimal || $porcentaje->esCero() || $porcentaje->esNegativo()) {
                    Notification::make()
                        ->danger()
                        ->title('El porcentaje no se entiende')
                        ->body('Escribí un número mayor que cero. Ejemplo: 85 para un 15 % menos.')
                        ->send();

                    return;
                }

                $origen = app(CopiadorDeBaseDePrecios::class)->origenDesde($data['origen'] ?? null);

                $resultado = app(CopiadorDeBaseDePrecios::class)->copiar(
                    origen: $origen,
                    destino: $destino,
                    factor: $porcentaje->entre('100'),
                    motivo: sprintf(
                        'Copiado desde %s al %s %%, al armar la base de %s.',
                        $origen->nombre ?? 'el precio de lista',
                        $porcentaje->redondeado(2),
                        $destino->nombre,
                    ),
                );

                $this->resetTable();

                Notification::make()
                    ->success()
                    ->title($resultado['creados'].' precios creados')
                    ->body($this->resumenDeLaCopia($resultado))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * @param array{creados: int, respetados: int, sinPrecioEnElOrigen: int} $resultado
     */
    private function resumenDeLaCopia(array $resultado): string
    {
        $partes = [];

        if ($resultado['respetados'] > 0) {
            $partes[] = $resultado['respetados'].' ya tenían precio y no se tocaron';
        }

        if ($resultado['sinPrecioEnElOrigen'] > 0) {
            $partes[] = $resultado['sinPrecioEnElOrigen'].' quedaron sin precio porque tampoco lo tenían en el origen';
        }

        return $partes === []
            ? 'Todo el catálogo quedó con precio en esta base.'
            : ucfirst(implode('; ', $partes)).'.';
    }

    /**
     * Las opciones del selector de origen. Salen del servicio y no de
     * acá: la misma lista la usa el alta de un pagador, y dos copias se
     * desincronizan.
     *
     * @return array<string, string>
     */
    private function opcionesDeOrigen(): array
    {
        return app(CopiadorDeBaseDePrecios::class)->opcionesDeOrigen(excluyendo: $this->convenioId);
    }
}
