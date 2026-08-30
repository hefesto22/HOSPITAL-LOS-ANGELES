<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Schemas;

use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Proveedor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

/**
 * Recibir mercadería — la pantalla que se usa parado frente al camión.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ESCANEO ES LA ENTRADA PRINCIPAL, NO UN ADORNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El código de barras identifica la **presentación**, no el ítem: la caja
 * de 100 tabletas y la de 50 del mismo acetaminofén tienen códigos
 * distintos, y eso es exactamente lo que hace falta saber para convertir
 * a unidades. Por eso `codigo_barras` vive en `item_presentaciones`.
 *
 * El campo sirve para las dos formas de escanear sin cambiar nada:
 *
 *   · **la pistola láser** de bodega teclea el código y manda Enter —el
 *     campo es un `TextInput`, así que funciona sin más;
 *   · **la cámara del teléfono**, con el botón del plugin.
 *
 * Cada lectura agrega una línea con el producto, la presentación y su
 * contenido ya puestos. Solo queda teclear cuántas cajas, a cuánto, y el
 * lote.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS TRES NÚMEROS A LA VISTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada línea muestra las cajas, las unidades que van a entrar al kardex y
 * el costo por unidad. No es decoración: es lo que hace que alguien note
 * ANTES de guardar que eligió la presentación equivocada. Después de
 * guardar, el kardex ya se movió.
 *
 * ⚠️ Acá NO hay casillas de impuesto. El costo que se teclea ya lo lleva
 * adentro: los servicios de salud son exentos, así que el ISV de las
 * compras no se acredita y por lo tanto es costo. El desglose fiscal vive
 * en Compras.
 */
final class RecepcionForm
{
    public static function configure(Schema $schema): Schema
    {
        /*
         * ─────────────────────────────────────────────────────────────
         * EL ANCHO ES PARA LAS LÍNEAS, NO PARA LA CABECERA
         * ─────────────────────────────────────────────────────────────
         *
         * Antes las cuatro secciones caían en una grilla de dos columnas
         * y el resultado era el peor reparto posible: la mitad izquierda
         * con dos campos y aire, y la mitad derecha con el repeater
         * —ocho campos por línea— aplastado en media pantalla, con el
         * nombre del producto partido en tres renglones.
         *
         * Recibir un camión es teclear muchas líneas rápido. Lo que tiene
         * que ser ancho es eso. La cabecera y las notas, que se llenan
         * una vez, van abajo y de a dos.
         */
        return $schema->components([
            self::dondeEntra(),
            self::loQueLlego(),
            self::deQuienVino(),
            self::lasNotas(),
        ]);
    }

    private static function dondeEntra(): Section
    {
        return Section::make('1 · ¿A dónde entra?')
            ->columnSpanFull()
            ->columns(4)
            ->schema([
                /*
                 * 🔴 SIN VALOR POR DEFECTO, Y ES A PROPÓSITO.
                 *
                 * Desde que hay FARMACIA y BODEGA, a cuál entra la
                 * mercadería es una decisión de quien recibe, no un
                 * trámite. Un valor preseleccionado se acepta sin leerlo
                 * —así es como se aceptan los valores preseleccionados—
                 * y la caja de gasas termina en el estante equivocado,
                 * con dos kardex descuadrados de una vez.
                 *
                 * Si después hay que moverlo, se mueve: Inventario →
                 * Existencias → Mover. Pero es mejor que entre bien.
                 */
                Select::make('almacen_id')
                    ->label('¿A qué almacén entra?')
                    ->columnSpan(2)
                    ->relationship('almacen', 'nombre')
                    ->getOptionLabelFromRecordUsing(
                        fn (Almacen $record): string => $record->nombre.' · '.$record->tipo->etiqueta()
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->helperText(
                        'El saldo y el costo son POR almacén: equivocarlo descuadra dos estantes '
                        .'de una vez. Lo que llega del proveedor normalmente entra a BODEGA y de '
                        .'ahí se baja a farmacia o al carrito.'
                    ),

                DatePicker::make('fecha_recepcion')
                    ->label('¿Cuándo llegó?')
                    ->columnSpan(2)
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->helperText('Es la fecha con la que quedan los movimientos, no la de hoy.'),

            ]);
    }

    /**
     * De quién vino y con qué papel — plegado, porque casi nunca se llena.
     *
     * ─────────────────────────────────────────────────────────────────
     * ⚠️ POR QUÉ SE ESCONDE Y NO SE BORRA
     * ─────────────────────────────────────────────────────────────────
     *
     * Recibir es registrar la entrada al kardex, y para eso alcanza con
     * almacén, fecha, producto, cantidad, costo y lote. Los dos campos de
     * acá estorbaban en la pantalla del día a día y por eso se pliegan.
     *
     * Pero no se van, y la razón no es de diseño sino de farmacovigilancia:
     * cuando ARSA saca un lote de circulación, la pregunta que hay que
     * poder contestar es **«¿a quién le compramos este lote?»**. Con el
     * proveedor escrito en la recepción se responde con una consulta; sin
     * él, revolviendo facturas en papel.
     *
     * Y el mismo dato es el que después contesta «¿quién nos vende más
     * barato?», que es la única forma de discutir un precio con un
     * proveedor sin adivinar.
     */
    private static function deQuienVino(): Section
    {
        return Section::make('De quién vino')
            ->description(
                'Opcional. Vale la pena llenarlo cuando viene con factura: es lo que permite '
                .'saber a quién reclamar si ARSA retira un lote, y comparar precios entre '
                .'proveedores.'
            )
            ->icon('heroicon-o-truck')
            ->collapsed()
            ->columns(2)
            ->schema([
                Select::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre', fn ($query) => $query->activos())
                    ->getOptionLabelFromRecordUsing(fn (Proveedor $record): string => $record->etiqueta())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Vacío en una donación anónima o un traslado.'),

                TextInput::make('referencia')
                    ->label('Referencia')
                    ->maxLength(120)
                    ->placeholder('Factura 000-001-01-00000657')
                    ->helperText(
                        'Texto libre: el número del papel que vino con la mercadería. No hace '
                        .'falta que la compra esté cargada todavía.'
                    ),
            ]);
    }

    private static function loQueLlego(): Section
    {
        return Section::make('2 · ¿Qué llegó?')
            ->columnSpanFull()
            ->schema([
                BarcodeInput::make('escaneo')
                    ->label('Escaneá el código de barras')
                    ->columnSpanFull()
                    ->dehydrated(false)
                    ->live()
                    ->autofocus()
                    ->helperText(
                        'Con la pistola de bodega o con la cámara del teléfono. Cada lectura '
                        .'agrega una línea con el producto y su presentación ya puestos.'
                    )
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::agregarLoEscaneado($state, $get, $set)),

                Repeater::make('lineas')
                    ->label('')
                    ->addActionLabel('Agregar a mano')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->columnSpanFull()
                    /*
                     * Seis columnas: producto y presentación se llevan dos
                     * cada uno —son los que traen texto largo— y los
                     * números entran en la misma fila. Así una línea se
                     * lee de un vistazo en vez de en cuatro renglones.
                     */
                    ->columns(6)
                    ->itemLabel(fn (array $state): ?string => self::etiquetaDeLinea($state))
                    ->schema(self::camposDeLaLinea()),

                Placeholder::make('resumen')
                    ->label('Total de la recepción')
                    ->content(fn (Get $get): string => self::resumen($get)),
            ]);
    }

    /**
     * La presentación que le toca a una línea nueva: la habitual si
     * todavía está libre, y si no la primera que nadie eligió.
     *
     * 🔴 Poner SIEMPRE la habitual es lo que creaba el duplicado. Agregar
     * dos líneas del mismo producto dejaba el mismo frasco de 60 ML en
     * las dos, y la segunda pasaba desapercibida porque el campo ya venía
     * lleno — hasta que la existencia decía 1200 ML donde había 600.
     */
    private static function primeraPresentacionLibre(?Item $item, Get $get): ?ItemPresentacion
    {
        if (! $item instanceof Item) {
            return null;
        }

        $usadas = collect(is_array($get('../../lineas')) ? $get('../../lineas') : [])
            ->filter(fn (mixed $linea): bool => is_array($linea))
            ->pluck('item_presentacion_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $habitual = $item->presentacionPredeterminada();

        if ($habitual instanceof ItemPresentacion && ! in_array($habitual->id, $usadas, true)) {
            return $habitual;
        }

        $libre = ItemPresentacion::query()
            ->where('item_id', $item->id)
            ->whereNotIn('id', $usadas)
            ->orderBy('nombre')
            ->first();

        return $libre instanceof ItemPresentacion ? $libre : null;
    }

    /**
     * @return array<int, mixed>
     */
    private static function camposDeLaLinea(): array
    {
        return [
            Select::make('item_id')
                ->label('Producto')
                ->options(fn (): array => Item::query()
                    ->orderBy('nombre')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                    ->all())
                /*
                 * Solo lo vigente: no se compra más de algo que se dejó
                 * de ofrecer. Lo retirado con existencia sí se sigue
                 * pudiendo contar y ajustar — esas pantallas no filtran.
                 */
                ->getSearchResultsUsing(fn (string $search): array => Item::buscar($search, soloVigentes: true)
                    ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                    ->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::itemDe($value)?->etiqueta())
                ->searchable()
                ->required()
                ->native(false)
                ->live()
                ->columnSpan(2)
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    $presentacion = self::primeraPresentacionLibre(self::itemDe($state), $get);

                    $set('item_presentacion_id', $presentacion?->id);
                    $set('unidades_por_presentacion', $presentacion->unidades_por_presentacion ?? '1');
                }),

            Select::make('item_presentacion_id')
                ->label('Presentación')
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 UNA PRESENTACIÓN NO SE OFRECE DOS VECES
                 * ─────────────────────────────────────────────────────
                 *
                 * Dos líneas del mismo frasco de 60 ML terminan en el
                 * mismo lote y se suman: la existencia queda en 1200 ML
                 * «20 envases» sin que nadie haya recibido veinte. No es
                 * un error del kardex —sumar es lo correcto cuando llegan
                 * dos cajas del mismo lote— es que la pantalla dejó
                 * elegir dos veces lo mismo por descuido.
                 *
                 * `../../lineas` sube los dos niveles del repetidor y lee
                 * el arreglo entero. La opción propia se conserva: si se
                 * excluyera, el campo aparecería vacío al reabrir la fila.
                 */
                ->options(function (Get $get): array {
                    /*
                     * ⚠️ Se CUENTA cuántas veces aparece cada una, no se
                     * compara contra «la mía».
                     *
                     * Dos filas con la misma presentación son
                     * indistinguibles por valor: al excluir «las que no
                     * son la mía», la que estaba repetida se salvaba a sí
                     * misma y seguía apareciendo en la lista. Contando,
                     * una presentación que aparece dos veces sobra en las
                     * dos filas y desaparece de las dos listas.
                     */
                    $veces = collect(is_array($get('../../lineas')) ? $get('../../lineas') : [])
                        ->filter(fn (mixed $linea): bool => is_array($linea))
                        ->pluck('item_presentacion_id')
                        ->filter(fn (mixed $id): bool => is_numeric($id))
                        ->map(fn (mixed $id): int => (int) $id)
                        ->countBy()
                        ->all();

                    $mia = is_numeric($get('item_presentacion_id'))
                        ? (int) $get('item_presentacion_id')
                        : null;

                    $ocupadas = [];

                    foreach ($veces as $id => $cuantas) {
                        if ((int) $id !== $mia || $cuantas > 1) {
                            $ocupadas[] = (int) $id;
                        }
                    }

                    return ItemPresentacion::query()
                        ->where('item_id', $get('item_id'))
                        ->whereNotIn('id', $ocupadas)
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (ItemPresentacion $p): array => [$p->id => $p->nombre])
                        ->all();
                })
                ->native(false)
                ->live()
                ->columnSpan(2)
                ->helperText('Vacío = llegó en unidad de dispensación.')
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $set(
                        'unidades_por_presentacion',
                        self::presentacionDe($state)->unidades_por_presentacion ?? '1',
                    );
                }),

            TextInput::make('cantidad_presentacion')
                ->label('¿Cuántas?')
                ->numeric()
                ->required()
                ->minValue(0.0001)
                ->default('1')
                ->live(onBlur: true)
                ->helperText('Cajas, frascos, bultos.'),

            TextInput::make('unidades_por_presentacion')
                ->label('Trae')
                ->numeric()
                ->required()
                ->minValue(0.0001)
                ->default('1')
                ->live(onBlur: true)
                ->helperText('Unidades por caja. Queda congelado en esta recepción.'),

            TextInput::make('costo_por_presentacion')
                ->label('Costo por caja')
                ->numeric()
                ->required()
                ->minValue(0)
                ->prefix('L')
                ->default('0')
                ->live(onBlur: true)
                ->helperText('Con el impuesto adentro. Cero si es donación.'),

            Placeholder::make('cuenta')
                ->label('Entra al kardex')
                ->content(fn (Get $get): string => self::laCuentaDeLaLinea($get)),

            TextInput::make('numero_lote')
                ->label('Lote')
                ->maxLength(60)
                ->columnSpan(2)
                /*
                 * ⚠️ El lote se canoniza a MAYÚSCULAS en tres lugares y
                 * los tres hacen falta.
                 *
                 * `ResolutorDeLote` ya lo hacía —es la garantía real—
                 * pero solo al guardar: quien teclea «lot-1» veía «lot-1»
                 * en pantalla y «LOT-1» en la existencia, y eso parece un
                 * error del sistema. El CSS lo muestra en mayúsculas
                 * mientras se escribe, `afterStateUpdated` lo deja escrito
                 * así al salir del campo, y `dehydrateStateUsing` es el
                 * que asegura que llegue así aunque el navegador no haya
                 * disparado el evento.
                 */
                ->live(onBlur: true)
                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                ->afterStateUpdated(fn (mixed $state, Set $set): mixed => $set(
                    'numero_lote',
                    is_string($state) ? mb_strtoupper(trim($state)) : $state,
                ))
                ->dehydrateStateUsing(fn (mixed $state): ?string => is_string($state) && trim($state) !== ''
                    ? mb_strtoupper(trim($state))
                    : null)
                ->helperText('Impreso en la caja. Se guarda en mayúsculas.'),

            DatePicker::make('fecha_vencimiento')
                ->label('Vence el')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->columnSpan(2)
                ->requiredWith('numero_lote')
                ->helperText('Vacío solo si el producto no caduca.'),
        ];
    }

    private static function lasNotas(): Section
    {
        return Section::make('Notas')
            ->collapsed()
            ->schema([
                Textarea::make('notas')
                    ->hiddenLabel()
                    ->rows(3)
                    ->placeholder('Opcional: llegó una caja golpeada, faltó una del pedido, lo que sea.'),
            ]);
    }

    // ── El escaneo ────────────────────────────────────────────────────

    /**
     * Una lectura del código de barras se convierte en una línea nueva.
     *
     * El campo se limpia siempre al final —haya encontrado o no— para que
     * el siguiente escaneo entre sin borrar a mano. Con la pistola eso es
     * la diferencia entre recibir un camión en dos minutos o en veinte.
     */
    private static function agregarLoEscaneado(mixed $state, Get $get, Set $set): void
    {
        $codigo = trim(is_string($state) ? $state : '');

        if ($codigo === '') {
            return;
        }

        $set('escaneo', null);

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 DOS CÓDIGOS DISTINTOS LLEGAN A LA MISMA LÍNEA
         * ─────────────────────────────────────────────────────────────
         *
         * Lo que pasa por el lector puede ser una de dos cosas, y las dos
         * son válidas:
         *
         *   1. El **EAN del fabricante**, impreso en la caja que trae el
         *      proveedor. Identifica una presentación exacta.
         *   2. La **etiqueta del hospital**, que lleva el código del
         *      PRODUCTO (`MED-101`) porque acá se reenvasa. Identifica el
         *      producto, no el envase.
         *
         * El primer intento buscaba solo lo primero, y por eso escanear la
         * etiqueta propia —la única que existe para casi todo el
         * inventario— contestaba «ese código no está en el catálogo»
         * teniendo el producto delante.
         *
         * Cuando entra por el código del producto se usa su presentación
         * habitual: es la que dice cuántas unidades trae el envase, y sin
         * ese número no hay forma de convertir a kardex.
         */
        $presentacion = ItemPresentacion::query()
            ->where('codigo_barras', $codigo)
            ->first();

        if (! $presentacion instanceof ItemPresentacion) {
            $presentacion = self::presentacionDelProducto($codigo);
        }

        if (! $presentacion instanceof ItemPresentacion) {
            return;
        }

        /** @var array<string, mixed> $lineas */
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        $lineas[(string) Str::uuid()] = [
            'item_id'                   => $presentacion->item_id,
            'item_presentacion_id'      => $presentacion->id,
            'cantidad_presentacion'     => '1',
            'unidades_por_presentacion' => $presentacion->unidades_por_presentacion,
            'costo_por_presentacion'    => '0',
            'numero_lote'               => null,
            'fecha_vencimiento'         => null,
        ];

        $set('lineas', $lineas);

        Notification::make()
            ->success()
            ->title($presentacion->nombre)
            ->body('Agregado. Poné la cantidad, el costo y el lote.')
            ->send();
    }

    /**
     * El producto por su código propio —el de la etiqueta del hospital—
     * con la presentación en la que se compra.
     *
     * Devuelve null y avisa en dos casos distintos, con dos mensajes
     * distintos: cuando el código no existe en ningún lado, y cuando el
     * producto existe pero todavía no tiene en qué envase viene. El
     * segundo es el que se soluciona en veinte segundos y no hay que
     * confundirlo con el primero.
     */
    private static function presentacionDelProducto(string $codigo): ?ItemPresentacion
    {
        $item = Item::query()->where('codigo', $codigo)->first();

        if (! $item instanceof Item) {
            Notification::make()
                ->warning()
                ->title('Ese código no está en el catálogo')
                ->body(
                    "Ningún producto tiene el código {$codigo}, ni ninguna caja registrada con "
                    .'ese código de barras. Revisá que el producto esté dado de alta, o agregá '
                    .'la línea a mano.'
                )
                ->persistent()
                ->send();

            return null;
        }

        $presentacion = $item->presentacionPredeterminada()
            ?? $item->presentaciones()->orderBy('id')->first();

        if (! $presentacion instanceof ItemPresentacion) {
            Notification::make()
                ->warning()
                ->title('Falta decir en qué viene')
                ->body(
                    "{$item->nombre} está en el catálogo, pero no tiene ninguna presentación de "
                    .'compra. Agregale la caja o el frasco en que llega —con cuántas unidades '
                    .'trae— y volvé a escanear.'
                )
                ->persistent()
                ->send();

            return null;
        }

        return $presentacion;
    }

    // ── Las cuentas a la vista ────────────────────────────────────────

    /**
     * «10.000 unidades · L 10,00 c/u» — con bcmath, no con float.
     */
    private static function laCuentaDeLaLinea(Get $get): string
    {
        $cajas = self::comoNumero($get('cantidad_presentacion'));
        $porCaja = self::comoNumero($get('unidades_por_presentacion'));
        $costo = self::comoNumero($get('costo_por_presentacion'));

        if ($cajas === null || $porCaja === null) {
            return '—';
        }

        $unidades = Decimal::de($cajas)->por($porCaja);

        if ($unidades->esCero()) {
            return '—';
        }

        $texto = self::sinCerosSobrantes($unidades->redondeado(4)).' unidades';

        if ($costo === null || Decimal::de($costo)->esCero()) {
            return $texto.' · sin costo';
        }

        $unitario = Decimal::de($costo)->entre($porCaja)->redondeado(2);

        return "{$texto} · L {$unitario} c/u";
    }

    /**
     * Lo que va a entrar en total, para revisar de un vistazo antes de
     * guardar. Después de guardar, el kardex ya se movió.
     */
    private static function resumen(Get $get): string
    {
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        if ($lineas === []) {
            return 'Todavía no agregaste nada.';
        }

        $unidades = Decimal::cero();
        $costo = Decimal::cero();

        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $cajas = self::comoNumero($linea['cantidad_presentacion'] ?? null);
            $porCaja = self::comoNumero($linea['unidades_por_presentacion'] ?? null);
            $porUnidad = self::comoNumero($linea['costo_por_presentacion'] ?? null);

            if ($cajas === null || $porCaja === null) {
                continue;
            }

            $unidades = $unidades->sumar(Decimal::de($cajas)->por($porCaja));
            $costo = $costo->sumar(Decimal::de($cajas)->por($porUnidad ?? '0'));
        }

        $cuantas = count($lineas);
        $productos = $cuantas === 1 ? '1 producto' : "{$cuantas} productos";

        return $productos
            .' · '.self::sinCerosSobrantes($unidades->redondeado(4)).' unidades'
            .' · L '.$costo->redondeado(2);
    }

    // ── Ayudantes ─────────────────────────────────────────────────────

    private static function etiquetaDeLinea(mixed $estado): ?string
    {
        if (! is_array($estado)) {
            return null;
        }

        return self::itemDe($estado['item_id'] ?? null)?->etiqueta();
    }

    private static function itemDe(mixed $valor): ?Item
    {
        return is_numeric($valor) ? Item::query()->find((int) $valor) : null;
    }

    private static function presentacionDe(mixed $valor): ?ItemPresentacion
    {
        return is_numeric($valor) ? ItemPresentacion::query()->find((int) $valor) : null;
    }

    /**
     * @return numeric-string|null
     */
    private static function comoNumero(mixed $valor): ?string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        /*
         * 🔴 Un `<input type="number">` llega desde Livewire como float.
         * Sin esta rama, el total de la recepción decía «—» con la
         * pantalla llena de números correctos.
         *
         * Cuatro decimales, que es la escala del kardex, y por texto:
         * un float nunca entra a `Decimal` como float (§8.6.2).
         */
        if (is_float($valor)) {
            return is_finite($valor) ? number_format($valor, 4, '.', '') : null;
        }

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return preg_match('/^-?\d+(\.\d+)?$/', $texto) === 1 && is_numeric($texto)
            ? $texto
            : null;
    }

    /**
     * En dos pasadas: con `rtrim($valor, '0.')` el «100.0000» perdería
     * también los ceros del entero y quedaría en «1».
     */
    private static function sinCerosSobrantes(string $valor): string
    {
        if (! str_contains($valor, '.')) {
            return $valor;
        }

        return rtrim(rtrim($valor, '0'), '.');
    }
}
