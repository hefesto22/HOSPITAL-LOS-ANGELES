<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\AjustadorDeBaseDePrecios;
use App\Services\CopiadorDeBaseDePrecios;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
 * Acá se elige el pagador arriba y se ve el catálogo entero con SU
 * precio, editable en la propia fila. Los que no tienen precio se ven
 * vacíos, que es la única forma de saber qué falta sin ir a buscarlo.
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
        return 'El catálogo completo con el precio de cada pagador. Se edita en la propia fila.';
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
            'cuantos' => $copiador->cuantosTienenPrecio(null),
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

        $this->resetTable();
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
            ->count();

        $conPrecio = app(CopiadorDeBaseDePrecios::class)
            ->cuantosTienenPrecio($this->baseActual());

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
                    ->label('Precio L.')
                    ->type('number')
                    ->extraInputAttributes(['step' => '0.0001', 'min' => '0', 'class' => 'text-right'])
                    ->updateStateUsing(fn (Item $record, mixed $state): mixed => $this->guardar($record, $state)),

                TextColumn::make('precio_de_lista')
                    ->label('Lista')
                    ->alignEnd()
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
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoItem::cases())
                        ->mapWithKeys(fn (TipoItem $t): array => [$t->value => $t->etiqueta()])
                        ->all()),

                /*
                 * El filtro que de verdad se usa: qué me falta cargar.
                 */
                Filter::make('sin_precio')
                    ->label('Solo los que no tienen precio en esta base')
                    ->query(fn (Builder $query): Builder => $query->whereNotExists(
                        fn ($sub) => $this->subconsultaDePrecio($sub, $this->convenioId)
                    )),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Item>
     */
    private function consulta(): Builder
    {
        /** @var Builder<Item> $query */
        $query = Item::query()->with('unidadDispensacion');

        /*
         * ─────────────────────────────────────────────────────────────
         * CON UN SEGURO ELEGIDO, FARMACIA NO SE LISTA
         * ─────────────────────────────────────────────────────────────
         *
         * A un seguro no se le pacta precio de medicamentos: paga el de
         * lista, que se recalcula solo con cada compra. Dejarlos en la
         * lista no era solo ruido — arruinaba el ÚNICO número accionable
         * de la pantalla. «38 sin precio» contaba medicamentos que nadie
         * va a pactar nunca, así que ese número no se podía bajar a cero
         * y dejaba de significar algo.
         *
         * La regla de verdad la impone `FijadorDePrecio`, que rechaza el
         * intento venga de donde venga. Esto es lo que hace que no haga
         * falta intentarlo.
         */
        if ($this->convenioId !== null) {
            $query->where('items.se_almacena', false);
        }

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
            ->vigentesEn(now())
            ->limit(1),
        ]);

        $query->addSelect(['precio_de_lista' => Tarifario::query()
            ->selectRaw('trim_scale(tarifarios.precio)')
            ->whereColumn('tarifarios.item_id', 'items.id')
            ->whereNull('tarifarios.convenio_id')
            ->whereNull('tarifarios.sede_id')
            ->vigentesEn(now())
            ->limit(1),
        ]);

        return $query;
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

        Notification::make()
            ->success()
            ->title($item->codigo)
            ->body('Precio actualizado en '.$this->nombreDeLaBase().'.')
            ->send();

        return $numero->redondeado(4);
    }

    private function diferencia(Item $item): string
    {
        $base = $item->getAttribute('precio_de_la_base');
        $lista = $item->getAttribute('precio_de_lista');

        if ($base === null || $lista === null || (float) $lista === 0.0) {
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

        if ($texto === '—') {
            return 'gray';
        }

        return str_starts_with($texto, '-') ? 'warning' : 'success';
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
