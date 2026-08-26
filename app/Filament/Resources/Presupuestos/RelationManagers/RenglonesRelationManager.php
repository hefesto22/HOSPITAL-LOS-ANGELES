<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\RelationManagers;

use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\ConsultorDeExistencias;
use App\Services\CotizadorDePresupuesto;
use App\Support\AlmacenesDelUsuario;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('item:id,codigo,nombre'))
            ->columns([
                TextColumn::make('orden')
                    ->label('#')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('item.codigo')
                    ->label('Código')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                TextColumn::make('texto')
                    ->label('Concepto')
                    ->wrap()
                    ->searchable()
                    ->description(fn (PresupuestoLinea $record): ?string => $record->nota),

                TextColumn::make('origen')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn (OrigenLineaPresupuesto $state): string => $state->etiqueta())
                    ->color(fn (OrigenLineaPresupuesto $state): string => $state->color()),

                TextColumn::make('cantidad')
                    ->label('Cant.')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => is_scalar($state)
                        ? rtrim(rtrim((string) $state, '0'), '.')
                        : '—'),

                TextColumn::make('precio_unitario')
                    ->label('P. unit.')
                    ->alignEnd()
                    ->money('HNL'),

                TextColumn::make('descuento')
                    ->label('Desc. ley')
                    ->alignEnd()
                    ->money('HNL')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Importe')
                    ->alignEnd()
                    ->money('HNL')
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')->money('HNL')),

                TextColumn::make('opcional')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state === true ? 'Opcional' : '')
                    ->color('gray'),
            ])
            ->headerActions([
                $this->agregarDelCatalogo(),
                $this->agregarAMano(),
                $this->agregarHolgura(),
            ])
            ->recordActions([
                $this->ajustar(),
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
            ->label('Agregar a mano')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (): bool => $this->esBorrador())
            ->modalDescription(
                'Para lo que no tiene precio de tarifario: el honorario que el cirujano acordó, algo que no está en el catálogo.'
            )
            ->schema([
                TextInput::make('texto')
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
            ->label('Agregar holgura')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('info')
            ->visible(fn (): bool => $this->esBorrador())
            ->modalDescription(
                'El colchón, como línea visible. Repartirlo dentro de los precios dejaría un papel donde cada renglón miente un poco.'
            )
            ->schema([
                TextInput::make('monto')
                    ->label('Monto')
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->prefix('L'),
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
                );

                $cotizador->recalcular($presupuesto);
                $this->sincronizarConLaCuenta();
            });
    }

    private function ajustar(): Action
    {
        return Action::make('ajustar')
            ->label('Ajustar')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible(fn (): bool => $this->esBorrador())
            ->fillForm(fn (PresupuestoLinea $record): array => [
                'cantidad'        => $record->cantidad,
                'precio_unitario' => $record->precio_unitario,
            ])
            ->schema([
                TextInput::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.0001)
                    ->required(),

                /*
                 * 🔴 El precio SE PUEDE pisar, incluso en una línea del
                 * tarifario: el honorario del cirujano cambia por médico
                 * y hay que poder corregirlo.
                 *
                 * Lo que NO se pierde es de dónde vino el número: al
                 * cambiarlo, el renglón queda marcado como «precio
                 * acordado a mano», y el reporte de presupuestado contra
                 * real deja de culpar a la cotización por una diferencia
                 * que puso una persona.
                 */
                TextInput::make('precio_unitario')
                    ->label('Precio')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('L')
                    ->helperText(fn (PresupuestoLinea $record): string => $record->origen === OrigenLineaPresupuesto::Catalogo
                        ? 'Salió del tarifario de este pagador. Si lo cambiás, el renglón pasa a «precio acordado a mano» — el ítem sigue siendo el mismo.'
                        : 'Este renglón lleva precio acordado a mano.'),
            ])
            ->action(function (PresupuestoLinea $record, array $data): void {
                abort_unless(Gate::allows('update', $this->presupuesto()), 403);

                if (! $this->esBorrador()) {
                    $this->avisarQueYaNoEsBorrador();

                    return;
                }

                app(CotizadorDePresupuesto::class)->ajustarLinea(
                    linea: $record,
                    cantidad: $this->comoCantidad($data['cantidad'] ?? null),
                    precioUnitario: $this->comoCantidad($data['precio_unitario'] ?? null, '0.0000'),
                );

                $this->sincronizarConLaCuenta();
            });
    }

    private function quitar(): Action
    {
        return Action::make('quitar')
            ->label('Quitar')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => $this->esBorrador())
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
     * El ítem detrás de un código escaneado.
     *
     * Primero por código de barras de la PRESENTACIÓN —es lo que trae el
     * envase— y después por el código del ítem, que es lo que alguien
     * teclea cuando no hay etiqueta.
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

        return Item::query()
            ->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])
            ->first();
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
        $ultimo = $this->presupuesto()->detalle()->max('orden');

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
