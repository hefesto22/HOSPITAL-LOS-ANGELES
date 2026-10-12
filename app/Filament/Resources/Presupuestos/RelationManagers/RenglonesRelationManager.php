<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\RelationManagers;

use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Exceptions\PresupuestoException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\ConsultorDeExistencias;
use App\Services\CotizadorDePresupuesto;
use App\Support\AlmacenesDelUsuario;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;
use Throwable;

/**
 * Los renglones del presupuesto de ESTE paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ ES DONDE EL PRESUPUESTO SE VUELVE DE ESTE CASO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La plantilla se copió UNA vez al crear. Estos renglones ya no le
 * pertenecen: se les cambia la cantidad, se borran los que no van, se
 * agregan los que aparecieron —la complicación que nadie esperaba, el
 * honorario que el cirujano acordó aparte—.
 *
 * ⚠️ Todo esto solo mientras el presupuesto está en BORRADOR. Después de
 * emitido lo rechaza un trigger de la base, y el camino es revisarlo.
 */
class RenglonesRelationManager extends RelationManager
{
    protected static string $relationship = 'detalle';

    protected static ?string $title = 'Renglones del presupuesto';

    protected static ?string $modelLabel = 'renglón';

    protected static ?string $pluralModelLabel = 'renglones';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('item:id,codigo,nombre,tipo,se_almacena'))
            /*
             * ─────────────────────────────────────────────────────────
             * EL RENGLÓN SE LEE COMO EN EL PAPEL (12-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Concepto grande y el código chico al lado; «opcional» y la
             * nota debajo del concepto, no en columnas sueltas que casi
             * siempre iban vacías. Los montos en lempiras como se
             * escriben acá —L 1,250.00—, no «1.250,00 HNL». Ajustar y
             * quitar, como íconos: dos palabras por renglón empujaban
             * la tabla hacia la derecha.
             *
             * Y sin barra de búsqueda, sin columnas ocultables y sin
             * páginas: un presupuesto son unas decenas de renglones y se
             * lee entero, de corrido, como el papel. El descuento de ley
             * solo aparece cuando hay alguno.
             *
             * Cantidad y precio se escriben en la tabla misma, sin abrir
             * nada (Mauricio, 12-oct-2026). Medicamentos y materiales
             * dejan cambiar la cantidad pero no el precio: van con el de
             * lista.
             */
            ->columns([
                TextColumn::make('item.codigo')
                    ->label('Código')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->placeholder('—')
                    ->grow(false),

                TextColumn::make('texto')
                    ->label('Concepto')
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (PresupuestoLinea $record): ?string => $this->debajoDelConcepto($record)),

                TextColumn::make('origen')
                    ->label('Precio')
                    ->badge()
                    ->formatStateUsing(fn (OrigenLineaPresupuesto $state): string => $state->etiqueta())
                    ->color(fn (OrigenLineaPresupuesto $state): string => $state->color())
                    ->grow(false),

                $this->cantidadEnLaTabla(),

                TextColumn::make('cantidad_leida')
                    ->label('Cant.')
                    ->alignEnd()
                    ->grow(false)
                    ->visible(fn (): bool => ! $this->esBorrador())
                    ->state(fn (PresupuestoLinea $record): string => self::cantidadLegible($record->cantidad)),

                $this->precioEnLaTabla(),

                TextColumn::make('precio_leido')
                    ->label('P. unit.')
                    ->alignEnd()
                    ->grow(false)
                    ->visible(fn (): bool => ! $this->esBorrador())
                    ->state(fn (PresupuestoLinea $record): string => self::lempiras($record->precio_unitario)),

                TextColumn::make('descuento')
                    ->label('Desc. ley')
                    ->alignEnd()
                    ->grow(false)
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->placeholder('—')
                    ->visible(fn (): bool => Decimal::de($this->presupuesto()->total_descuento)->mayorQue('0')),

                TextColumn::make('total')
                    ->label('Importe')
                    ->alignEnd()
                    ->grow(false)
                    ->weight('bold')
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))),
            ])
            ->headerActions([
                $this->agregarDelCatalogo(),
                $this->agregarAMano(),
                $this->agregarHolgura(),
            ])
            ->striped()
            ->paginated(false)
            ->recordActions([
                $this->cambiarNombre(),
                $this->quitar(),
            ])
            ->emptyStateHeading('Sin renglones')
            ->emptyStateDescription('Agregá lo que lleva este caso. Si elegiste una plantilla al crear el presupuesto, deberían estar acá.');
    }

    // ── Acciones ──────────────────────────────────────────────────────

    private function agregarDelCatalogo(): Action
    {
        return Action::make('agregar_del_catalogo')
            ->label('Agregar del catálogo')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->esBorrador())
            ->schema([
                /*
                 * El mismo escaneo de la pantalla de cuentas: la pistola
                 * o la cámara. El código del envase resuelve el producto
                 * y lo deja elegido abajo — nadie teclea un MED-101 con
                 * el paciente esperando.
                 *
                 * ⚠️ El escaneo dice QUÉ es; cuánto lo dice la persona.
                 */
                BarcodeInput::make('escaneo')
                    ->label('Escaneá el código')
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('presentacion_id', null);

                        /*
                         * 🔴 EL CÓDIGO DE BARRAS ES DE LA PRESENTACIÓN,
                         * NO DEL PRODUCTO.
                         *
                         * La caja de 100 tabletas y el blíster de 10
                         * tienen códigos distintos porque SON envases
                         * distintos, y de eso depende el precio. Cargar
                         * solo el producto y dejar el envase vacío
                         * obligaba a elegirlo a mano el dato que el
                         * escaneo acababa de decir.
                         */
                        $presentacion = $this->presentacionPorCodigo($state);

                        if ($presentacion instanceof ItemPresentacion) {
                            $set('item_id', $presentacion->item_id);
                            $set('presentacion_id', $presentacion->id);
                            $set('escaneo', null);

                            return;
                        }

                        $item = $this->itemPorCodigo($state);

                        if (! $item instanceof Item) {
                            return;
                        }

                        $set('item_id', $item->id);
                        $set('escaneo', null);
                    })
                    ->helperText('Con la pistola o con la cámara. También sirve teclear el código del ítem.'),

                Select::make('item_id')
                    ->label('Ítem')
                    ->required()
                    ->searchable(['codigo', 'nombre'])
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('presentacion_id', null))
                    ->options(fn (): array => Item::query()
                        ->orderBy('codigo')
                        ->limit(200)
                        ->get()
                        ->mapWithKeys(fn (Item $i): array => [$i->id => "{$i->codigo} — {$i->nombre}"])
                        ->all())
                    ->helperText('El precio sale del tarifario del pagador de este presupuesto, no se escribe.'),

                /*
                 * 🔴 EN UN MEDICAMENTO, EL ENVASE ES EL PRECIO.
                 *
                 * El frasco de 60 ML y el de 120 ML costaron distinto el
                 * mililitro, y `ResolutorDePrecio` resuelve por
                 * presentación. Cotizar sin envase toma el precio del
                 * producto entero y el número sale mal.
                 *
                 * Solo aparece si el ítem tiene presentaciones: para una
                 * habitación o un honorario no significa nada.
                 */
                Select::make('presentacion_id')
                    ->label('¿De qué presentación sale?')
                    ->options(function (Get $get): array {
                        $item = $get('item_id');

                        if (! is_numeric($item)) {
                            return [];
                        }

                        return ItemPresentacion::query()
                            ->where('item_id', (int) $item)
                            ->orderByDesc('es_predeterminada')
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all();
                    })
                    ->visible(function (Get $get): bool {
                        $item = $get('item_id');

                        return is_numeric($item)
                            && ItemPresentacion::query()->where('item_id', (int) $item)->exists();
                    })
                    ->helperText('El precio sale del envase del que se sirve. Vacío = el precio del producto entero.'),

                TextInput::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.0001)
                    ->default(1)
                    ->required()
                    /*
                     * 🔴 SE MUESTRA EL STOCK, PERO NO SE BLOQUEA.
                     *
                     * El presupuesto es una PROYECCIÓN: se cotiza hoy una
                     * cirugía que puede ser la semana que viene, con
                     * medicamentos que farmacia todavía no compró.
                     *
                     * Toparlo en la existencia de hoy haría que lo que
                     * falta quede FUERA del presupuesto — y el día que se
                     * use se cobre como excedente, a una familia que solo
                     * tiene lo presupuestado. El estante manda al
                     * ENTREGAR, no al cotizar.
                     */
                    /*
                     * 🔴 TOPADO EN LA EXISTENCIA (decisión de Mauricio,
                     * 26-ago-2026).
                     *
                     * No se puede presupuestar más de lo que hay hoy en
                     * el estante.
                     *
                     * ⚠️ Queda escrito el costo, que se discutió y se
                     * aceptó: una cirugía PROGRAMADA para la semana que
                     * viene no se puede cotizar completa si farmacia
                     * todavía no compró. Lo que no entre al presupuesto
                     * se cobrará después como excedente — a una familia
                     * que a veces solo tiene lo presupuestado.
                     *
                     * Si algún día eso duele, la alternativa discutida
                     * era avisar en vez de bloquear.
                     */
                    ->maxValue(function (Get $get): float|int {
                        $item = $this->itemDe($get('item_id'));

                        if (! $item instanceof Item || ! $item->mueveInventario()) {
                            return PHP_INT_MAX;
                        }

                        return (float) $this->cuantoHayDe($item)->redondeado(4);
                    })
                    ->helperText(function (Get $get): string {
                        $item = $this->itemDe($get('item_id'));

                        if (! $item instanceof Item || ! $item->mueveInventario()) {
                            return 'Cuánto lleva este caso.';
                        }

                        $hay = $this->cuantoHayDe($item);

                        return $hay->esCero()
                            ? '⚠️ No hay existencia de este producto: no se puede presupuestar hasta que farmacia lo tenga.'
                            : "Hay {$hay->redondeado(2)} en existencia. No se puede presupuestar más que eso.";
                    }),

                Toggle::make('opcional')
                    ->label('Es opcional')
                    ->helperText('Igual suma al total: el presupuesto dice el techo, no el piso.'),
            ])
            ->action(function (array $data): void {
                $presupuesto = $this->presupuesto();

                abort_unless(Gate::allows('update', $presupuesto), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                $item = Item::query()->find($data['item_id']);

                if (! $item instanceof Item) {
                    return;
                }

                $cotizador = app(CotizadorDePresupuesto::class);

                $presentacion = isset($data['presentacion_id']) && is_numeric($data['presentacion_id'])
                    ? ItemPresentacion::query()->find((int) $data['presentacion_id'])
                    : null;

                $cantidad = $this->comoCantidad($data['cantidad'] ?? null);

                /*
                 * El `maxValue` ya lo frena en el formulario, pero la
                 * acción es invocable desde el cliente: lo que protege es
                 * esta verificación, no el campo.
                 */
                if (! $this->hayParaCotizar($item, $cantidad)) {
                    return;
                }

                $cotizador->agregarDelCatalogo(
                    presupuesto: $presupuesto,
                    item: $item,
                    cantidad: $cantidad,
                    fecha: now(),
                    orden: $this->siguienteOrden(),
                    opcional: (bool) ($data['opcional'] ?? false),
                    presentacion: $presentacion instanceof ItemPresentacion ? $presentacion : null,
                );

                $cotizador->recalcular($presupuesto);
                $this->sincronizarConLaCuenta();
            });
    }

    private function agregarAMano(): Action
    {
        return Action::make('agregar_a_mano')
            ->label('A mano')
            ->icon(Heroicon::OutlinedPencil)
            ->color('gray')
            ->tooltip('Lo que no tiene precio de tarifario')
            ->visible(fn (): bool => $this->esBorrador())
            ->modalDescription(
                'Para lo que no tiene precio de tarifario: el honorario que el cirujano acordó, algo que no está en el catálogo.'
            )
            ->schema([
                CampoMayusculas::make('texto')
                    ->label('Concepto')
                    ->required()
                    ->maxLength(200)
                    ->helperText('Como va a salir impreso: HONORARIOS CIRUJANO DR. X.'),

                TextInput::make('precio_unitario')
                    ->label('Precio')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('L'),

                TextInput::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.0001)
                    ->default(1)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $presupuesto = $this->presupuesto();

                abort_unless(Gate::allows('update', $presupuesto), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                $cotizador = app(CotizadorDePresupuesto::class);

                $cotizador->agregarLineaManual(
                    presupuesto: $presupuesto,
                    texto: is_string($data['texto'] ?? null) ? $data['texto'] : 'CONCEPTO',
                    cantidad: $this->comoCantidad($data['cantidad'] ?? null),
                    precioUnitario: $this->comoCantidad($data['precio_unitario'] ?? null, '0.0000'),
                    orden: $this->siguienteOrden(),
                );

                $cotizador->recalcular($presupuesto);
                $this->sincronizarConLaCuenta();
            });
    }

    private function agregarHolgura(): Action
    {
        return Action::make('agregar_holgura')
            ->label('Holgura')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->tooltip('El colchón, como renglón visible')
            ->visible(fn (): bool => $this->esBorrador())
            ->modalHeading('Agregar holgura')
            ->modalDescription(
                'El colchón, como renglón visible y con el nombre que se le explique a la familia. Repartirlo dentro de los precios dejaría un papel donde cada renglón miente un poco.'
            )
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Agregar')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    CampoMayusculas::make('texto')
                        ->label('Nombre')
                        ->default(CotizadorDePresupuesto::HOLGURA_POR_DEFECTO)
                        ->required()
                        ->minLength(2)
                        ->maxLength(200)
                        ->columnSpan(['default' => 1, 'sm' => 2])
                        ->helperText('Como sale en el papel: IMPREVISTOS, MARGEN POR COMPLICACIONES.'),

                    TextInput::make('monto')
                        ->label('Monto')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->prefix('L'),
                ]),
            ])
            ->action(function (array $data): void {
                $presupuesto = $this->presupuesto();

                abort_unless(Gate::allows('update', $presupuesto), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                $cotizador = app(CotizadorDePresupuesto::class);

                $cotizador->agregarHolgura(
                    presupuesto: $presupuesto,
                    monto: $this->comoCantidad($data['monto'] ?? null, '0.00'),
                    orden: $this->siguienteOrden() + 1000,
                    texto: is_string($data['texto'] ?? null) ? $data['texto'] : null,
                );

                $cotizador->recalcular($presupuesto);
                $this->sincronizarConLaCuenta();
            });
    }

    /**
     * La cantidad, escrita en la tabla misma.
     *
     * 🔴 En lo que sale del estante, topada en la existencia: la misma
     * regla que al agregar del catálogo (decisión de Mauricio,
     * 26-ago-2026). Si no alcanza, la casilla se pone en rojo y dice
     * cuánto hay; no se guarda.
     *
     * La holgura no tiene cantidad que cambiar: es un monto, va una vez.
     */
    private function cantidadEnLaTabla(): TextInputColumn
    {
        return TextInputColumn::make('cantidad')
            ->label('Cant.')
            ->visible(fn (): bool => $this->esBorrador())
            ->state(fn (PresupuestoLinea $record): string => self::cantidadLegible($record->cantidad))
            ->inputMode('decimal')
            ->width('6.5rem')
            ->extraInputAttributes(['style' => 'text-align: right'])
            ->disabled(fn (PresupuestoLinea $record): bool => $record->origen === OrigenLineaPresupuesto::Holgura)
            ->tooltip(fn (PresupuestoLinea $record): ?string => $record->origen === OrigenLineaPresupuesto::Holgura
                ? 'La holgura es un monto: va una vez'
                : null)
            ->rules(fn (PresupuestoLinea $record): array => [
                'required',
                'numeric',
                'gt:0',
                $this->alcanzaLaExistencia($record),
            ])
            ->validationAttribute('cantidad')
            ->updateStateUsing(function (PresupuestoLinea $record, mixed $state): mixed {
                $this->ajustarDesdeLaTabla($record, cantidad: $this->comoCantidad($state));

                return $state;
            });
    }

    /**
     * El precio, escrito en la tabla misma.
     *
     * 🔴 Medicamentos y materiales NO: van con el precio de lista
     * (`PresupuestoLinea::precioEsDeLista()`). Tampoco la holgura que
     * completa el monto acordado, que se calcula sola. Las dos casillas
     * quedan apagadas y dicen por qué al pasar el mouse — y el cotizador
     * lo rechaza igual si alguien llama a la acción por fuera.
     *
     * Pisar el precio de un renglón del tarifario lo deja como «precio
     * acordado a mano»: el reporte de presupuestado contra real deja de
     * culpar a la cotización por una diferencia que puso una persona.
     */
    private function precioEnLaTabla(): TextInputColumn
    {
        return TextInputColumn::make('precio_unitario')
            ->label('P. unit.')
            ->visible(fn (): bool => $this->esBorrador())
            ->state(fn (PresupuestoLinea $record): string => Decimal::de($record->precio_unitario)->redondeado(2))
            ->prefix('L')
            ->inputMode('decimal')
            ->width('9.5rem')
            ->extraInputAttributes(['style' => 'text-align: right'])
            ->disabled(fn (PresupuestoLinea $record): bool => $record->precioEsDeLista() || $record->completa_el_monto)
            ->tooltip(fn (PresupuestoLinea $record): ?string => match (true) {
                $record->precioEsDeLista() => 'Medicamento o material: va con el precio de lista',
                $record->completa_el_monto => 'Se calcula sola para llegar a lo que se le cobra',
                default                    => null,
            })
            ->rules(['required', 'numeric', 'gte:0'])
            ->validationAttribute('precio')
            ->updateStateUsing(function (PresupuestoLinea $record, mixed $state): mixed {
                $this->ajustarDesdeLaTabla($record, precio: $this->comoCantidad($state, '0.0000'));

                return $state;
            });
    }

    /**
     * El nombre, solo en lo que escribió una persona: la holgura —también
     * la que puso la plantilla y la que completa el monto— y lo agregado
     * a mano. Un renglón del catálogo dice lo que dice su ítem.
     */
    private function cambiarNombre(): Action
    {
        return Action::make('cambiar_nombre')
            ->label('Cambiar nombre')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->tooltip('Cambiar el nombre con que sale en el papel')
            ->visible(fn (PresupuestoLinea $record): bool => $this->esBorrador() && $record->seLePuedeCambiarElNombre())
            ->modalHeading('Cambiar el nombre')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (PresupuestoLinea $record): array => ['texto' => $record->texto])
            ->schema([
                CampoMayusculas::make('texto')
                    ->label('Nombre')
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->helperText('Como sale en el papel: IMPREVISTOS, MARGEN POR COMPLICACIONES.'),
            ])
            ->action(function (PresupuestoLinea $record, array $data): void {
                abort_unless(Gate::allows('update', $this->presupuesto()), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                app(CotizadorDePresupuesto::class)->ajustarLinea(
                    linea: $record,
                    cantidad: $record->cantidad,
                    texto: is_string($data['texto'] ?? null) ? $data['texto'] : null,
                );

                $this->sincronizarConLaCuenta();
            });
    }

    private function quitar(): Action
    {
        return Action::make('quitar')
            ->label('Quitar')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->tooltip('Quitar el renglón')
            ->color('danger')
            /*
             * La holgura que completa el monto no se quita: volvería a
             * aparecer en el acto, porque falta lo que falta. Se va sola
             * cuando los renglones llegan al monto, y deja de completar
             * —y se puede quitar— si se borra el monto acordado.
             */
            ->visible(fn (PresupuestoLinea $record): bool => $this->esBorrador() && ! $record->completa_el_monto)
            ->requiresConfirmation()
            ->modalHeading('Quitar el renglón')
            ->modalDescription('Se borra del presupuesto. Todavía está en borrador, así que no lo vio nadie.')
            ->action(function (PresupuestoLinea $record): void {
                abort_unless(Gate::allows('update', $this->presupuesto()), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                app(CotizadorDePresupuesto::class)->quitarLinea($record);

                $this->sincronizarConLaCuenta();
            });
    }

    // ── Interno ───────────────────────────────────────────────────────

    /**
     * Lo que va debajo del concepto: si completa el monto acordado, si es
     * opcional y su nota.
     */
    private function debajoDelConcepto(PresupuestoLinea $linea): ?string
    {
        $partes = [];

        $acordado = $this->presupuesto()->monto_acordado;

        if ($linea->completa_el_monto && $acordado !== null) {
            $partes[] = 'Completa los '.self::lempiras($acordado).' que se le cobran · se ajusta sola';
        }

        if ($linea->opcional === true) {
            $partes[] = 'Opcional · suma igual al total';
        }

        if (is_string($linea->nota) && trim($linea->nota) !== '') {
            $partes[] = $linea->nota;
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    private static function lempiras(mixed $monto): string
    {
        return is_numeric($monto) ? 'L '.number_format((float) $monto, 2) : '—';
    }

    /**
     * «3» y no «3.0000»; «2.5» y no «2.5000».
     */
    private static function cantidadLegible(string $cantidad): string
    {
        return str_contains($cantidad, '.') ? rtrim(rtrim($cantidad, '0'), '.') : $cantidad;
    }

    /**
     * La regla de existencia de la casilla de cantidad: en lo que sale del
     * estante, no más de lo que hay en los almacenes de este usuario.
     */
    private function alcanzaLaExistencia(PresupuestoLinea $linea): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($linea): void {
            $item = $linea->item;

            if (! $item instanceof Item || ! $item->mueveInventario() || ! is_numeric($value)) {
                return;
            }

            $hay = $this->cuantoHayDe($item);

            if ($hay->menorQue(Decimal::de($this->comoCantidad($value)))) {
                $fail("Hay {$hay->redondeado(2)} en existencia: no se puede presupuestar más que eso.");
            }
        };
    }

    /**
     * Lo que se escribe en la tabla entra por la misma puerta que todo:
     * el cotizador, que recalcula, ajusta la holgura que completa el monto
     * y rechaza el precio de lo que va con precio de lista.
     *
     * @param numeric-string|null $cantidad
     * @param numeric-string|null $precio
     */
    private function ajustarDesdeLaTabla(PresupuestoLinea $linea, ?string $cantidad = null, ?string $precio = null): void
    {
        abort_unless(Gate::allows('update', $this->presupuesto()), 403);

        if (! $this->esBorrador()) {
            $this->avisarQueYaNoEsBorrador();

            return;
        }

        try {
            app(CotizadorDePresupuesto::class)->ajustarLinea(
                linea: $linea,
                cantidad: $cantidad ?? $linea->cantidad,
                precioUnitario: $precio,
            );
        } catch (PresupuestoException $e) {
            Notification::make()
                ->danger()
                ->title('No se cambió')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->sincronizarConLaCuenta();
    }

    /**
     * Cuando la página cambia lo que se le cobra, la holgura que completa
     * cambió: se vuelve a dibujar la tabla para que diga el monto nuevo.
     */
    #[On('lo-que-se-cobra-cambio')]
    public function alCambiarLoQueSeCobra(): void
    {
        $this->presupuesto()->refresh();
    }

    /**
     * El ítem detrás de un código escaneado.
     *
     * Primero por código de barras de la PRESENTACIÓN —es lo que trae el
     * envase— y después por el del producto o su código interno
     * (`Item::porCodigo()`).
     */
    private function itemPorCodigo(mixed $codigo): ?Item
    {
        if (! is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        $presentacion = $this->presentacionPorCodigo($codigo);

        if ($presentacion instanceof ItemPresentacion && $presentacion->item instanceof Item) {
            return $presentacion->item;
        }

        return Item::porCodigo($codigo);
    }

    /**
     * La presentación cuyo código de barras coincide: el envase que se
     * acaba de escanear.
     */
    private function presentacionPorCodigo(mixed $codigo): ?ItemPresentacion
    {
        if (! is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        return ItemPresentacion::query()
            ->with('item')
            ->where('codigo_barras', trim($codigo))
            ->first();
    }

    /**
     * Cuánto hay del ítem en los almacenes que este usuario puede operar.
     */
    private function cuantoHayDe(Item $item): Decimal
    {
        $consultor = app(ConsultorDeExistencias::class);
        $total = Decimal::cero();

        foreach (AlmacenesDelUsuario::elegibles()->get() as $almacen) {
            $total = $total->sumar($consultor->totalEn($item, $almacen));
        }

        return $total;
    }

    /**
     * ¿Alcanza la existencia para cotizar esta cantidad?
     *
     * @param numeric-string $cantidad
     */
    private function hayParaCotizar(Item $item, string $cantidad): bool
    {
        if (! $item->mueveInventario()) {
            return true;
        }

        $hay = $this->cuantoHayDe($item);

        if (! $hay->menorQue(Decimal::de($cantidad))) {
            return true;
        }

        Notification::make()
            ->danger()
            ->title('No hay esa cantidad en existencia')
            ->body(
                "Se piden {$cantidad} de {$item->nombre} y hay {$hay->redondeado(2)}. "
                .'Cotizá hasta lo que hay, o pedile a farmacia que lo reciba primero.'
            )
            ->persistent()
            ->send();

        return false;
    }

    private function itemDe(mixed $id): ?Item
    {
        return is_numeric($id) ? Item::query()->find((int) $id) : null;
    }

    private function presupuesto(): Presupuesto
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Presupuesto) {
            abort(500, 'El panel de renglones se montó sobre algo que no es un presupuesto.');
        }

        return $duenio;
    }

    /**
     * Los renglones se tocan en borrador Y con el paquete ya agregado a
     * la cuenta: mientras el paciente está internado la cirugía se
     * complica, la familia pide otra habitación, y eso pasa TODOS los
     * días (ADR-0009).
     */
    private function esBorrador(): bool
    {
        return $this->presupuesto()->estado->esEditable();
    }

    /**
     * 🔴 EL MONTO DE LA CUENTA SIGUE AL PRESUPUESTO, EN EL ACTO.
     *
     * Cada cinco horas el hospital le dice a la familia cuánto va, y con
     * ese número deciden si siguen, piden el alta o piden traslado. Un
     * total desactualizado no es un detalle de pantalla: es una decisión
     * tomada sobre una cifra que no era.
     *
     * ⚠️ Resincronizar es anular y volver a asentar —un cargo no se
     * edita, §9.0.3—. El Service no hace nada si el monto no cambió.
     */
    private function sincronizarConLaCuenta(): void
    {
        /*
         * El resumen de la página —total, tope, consumo— vive en otro
         * componente: se le avisa para que relea el presupuesto.
         */
        $this->dispatch('renglones-del-presupuesto-cambiaron');

        $presupuesto = $this->presupuesto()->refresh();

        if ($presupuesto->estado !== EstadoPresupuesto::Agregado) {
            return;
        }

        try {
            app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($presupuesto);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('El renglón se guardó, pero la cuenta no se pudo actualizar')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function avisarQueYaNoEsBorrador(): void
    {
        Notification::make()
            ->danger()
            ->title('Este presupuesto ya fue emitido')
            ->body('Sus renglones no se tocan. Para cambiarlo, usá «Revisar»: crea uno nuevo y deja este como sustituido.')
            ->send();
    }

    private function siguienteOrden(): int
    {
        $ultimo = $this->presupuesto()->detalle()->where('completa_el_monto', false)->max('orden');

        return (is_numeric($ultimo) ? (int) $ultimo : 0) + 10;
    }

    /**
     * ⚠️ Un `<input type="number">` viaja por Livewire como NÚMERO de
     * JavaScript y llega a PHP como **float**. Devolver '0' ante lo que
     * no se entiende es el bug que ya costó dos rondas en recepciones y
     * compras (§ conversores de formulario).
     *
     * @param numeric-string $porDefecto
     *
     * @return numeric-string
     */
    private function comoCantidad(mixed $valor, string $porDefecto = '1.0000'): string
    {
        $texto = match (true) {
            is_int($valor)    => (string) $valor,
            is_float($valor)  => number_format($valor, 4, '.', ''),
            is_string($valor) => trim($valor),
            default           => '',
        };

        /*
         * UNA sola salida, y validada. `number_format()` devuelve `string`
         * a secas, así que sin este `is_numeric()` final nada garantiza
         * que lo que sale sea un número — y lo que hay del otro lado es
         * `Decimal::de()`, que revienta con lo que no entiende.
         */
        return is_numeric($texto) ? $texto : $porDefecto;
    }
}
