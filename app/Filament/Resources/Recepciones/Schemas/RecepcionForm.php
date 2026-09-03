<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Schemas;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrincipioActivo;
use App\Models\Proveedor;
use App\Services\AvisoDeLoQueSeDebe;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\HtmlString;
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
    /**
     * Lo que se debe de cada producto, respondido una sola vez por
     * request. Ver `loQueSeDebeDeLaLinea()`.
     *
     * @var array<int, string|null>
     */
    private static array $deudaPorItem = [];

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
                    /*
                     * ─────────────────────────────────────────────────
                     * AL CARRITO NO LE LLEGA EL PROVEEDOR
                     * ─────────────────────────────────────────────────
                     *
                     * Un stock de servicio se surte por TRASLADO desde
                     * bodega, nunca del camión — es lo que dice el texto
                     * de ayuda de abajo y hasta hoy no lo aplicaba nadie.
                     * Ofrecerlo acá es ofrecer el error.
                     *
                     * Se filtra por TIPO y no por una lista de nombres:
                     * así el día que abran una farmacia interna o
                     * activen el almacén único, aparecen solas (§1.1).
                     */
                    /*
                     * ⚠️ El parámetro se llama `$query` y NO es cosmético.
                     *
                     * Filament inyecta este closure POR NOMBRE:
                     * `evaluate($modifyRelationshipQueryUsing, ['query' => …])`.
                     * Con `$consulta` no lo encuentra, cae a resolver por
                     * TIPO, y el contenedor construye un `Eloquent\Builder`
                     * VACÍO —sin modelo—. El `where` se aplica sobre ese
                     * builder huérfano y la página revienta al montar con
                     * «Call to a member function hydrate() on null».
                     */
                    ->relationship(
                        'almacen',
                        'nombre',
                        fn (Builder $query): Builder => $query
                            ->where('tipo', '!=', TipoAlmacen::StockDeServicio->value),
                    )
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

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 LO QUE DISTINGUE DOS LÍNEAS ES EL LOTE
                     * ─────────────────────────────────────────────────
                     *
                     * Antes el selector de presentación escondía las que
                     * ya estaban en otra línea, para atajar el doble
                     * escaneo. Atajaba eso y de paso hacía **imposible**
                     * lo normal: el proveedor manda dos cajas del mismo
                     * frasco de producciones distintas, con dos lotes y
                     * dos vencimientos, y eso son dos líneas —es
                     * exactamente lo que FEFO necesita para sugerir el
                     * que vence primero—.
                     *
                     * Así que la lista ya no esconde nada y el duplicado
                     * se revisa acá, sobre lo que de verdad lo define:
                     * misma presentación Y mismo lote. Dos líneas sin
                     * lote también son duplicado: sin número no hay con
                     * qué distinguirlas.
                     *
                     * ⚠️ Al guardar y no mientras se teclea: el lote se
                     * escribe DESPUÉS de elegir la caja, así que avisar
                     * en el momento pintaría de rojo cada línea a medio
                     * llenar.
                     */
                    ->rule(static fn (): Closure => self::sinLineasRepetidas())
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
     * @return array<int, mixed>
     */
    private static function camposDeLaLinea(): array
    {
        return [
            /*
             * ─────────────────────────────────────────────────────────
             * UN SOLO CAMPO: LA CAJA QUE SE TIENE EN LA MANO
             * ─────────────────────────────────────────────────────────
             *
             * Antes eran dos selectores encadenados —producto y después
             * presentación—. Quien recibe no piensa en un producto
             * abstracto: mira la caja. Así que se elige directo
             * «ACETAMINOFEN JARABE · Frasco 120 ml» y el producto se
             * deriva.
             *
             * 🔴 «Suelto» TAMBIÉN está en la lista, y no es un adorno.
             * Al cargar el inventario que ya existe, de una caja de 100
             * tabletas quedan 20 porque las otras se vendieron antes de
             * que hubiera sistema. «1 caja» sería mentira y «0.2 cajas»
             * peor: se registran 20 tabletas sueltas. Sin esta opción
             * ese saldo no se puede cargar.
             *
             * El valor es `item:presentacion` —con 0 para lo suelto—
             * porque un Select guarda un escalar, y las dos columnas que
             * de verdad se guardan viajan en los `Hidden` de abajo.
             */
            Select::make('que_llego')
                ->label('¿Qué llegó?')
                ->columnSpan(4)
                ->required()
                ->searchable()
                ->native(false)
                ->live()
                /* Es un campo de pantalla: lo que se guarda son los dos Hidden. */
                ->dehydrated(false)
                /*
                 * La lista de arriba puede venir ACOTADA por lo que se
                 * escaneó —la gaveta, o un producto con varias cajas—.
                 * La BÚSQUEDA nunca se acota: escribir sigue viendo el
                 * catálogo entero, así que acotar no deja a nadie
                 * encerrado en una lista corta.
                 */
                ->options(fn (Get $get): array => self::opcionesDeLaLinea($get))
                ->getSearchResultsUsing(fn (string $search): array => self::loQuePuedeLlegar($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::comoSeLlama($value))
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    [$item, $presentacion] = self::partirLoQueLlego($state);

                    $set('item_id', $item?->id);
                    $set('item_presentacion_id', $presentacion?->id);
                    $set('unidades_por_presentacion', $presentacion->unidades_por_presentacion ?? '1');
                }),

            Hidden::make('item_id'),
            Hidden::make('item_presentacion_id'),

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 SI DE ESTO SE LE DEBE A ALGUIEN, SE DICE ACÁ
             * ─────────────────────────────────────────────────────────
             *
             * Regla de Mauricio: «cuando a nosotros nos entre de ese
             * medicamento a bodega o farmacia, que aparezca que hay que
             * devolverle x cantidad a x empresa o persona».
             *
             * Un préstamo de medicamento se pide un martes a las once de
             * la noche porque no había, y solo se puede devolver el día
             * que llega la compra. Entre esos dos momentos no hay nada
             * que hacer, así que la pantalla de «lo que se debe» sirve
             * para consultar pero no es donde la deuda se salda: nadie la
             * abre por las mañanas.
             *
             * Este renglón es el momento. La caja está entrando, el
             * producto está en la mano, y el aviso convierte un
             * recordatorio en un acto. Un mes después esas 20 tabletas ya
             * se despacharon y la deuda se paga en efectivo, más caro.
             *
             * Va acá arriba, pegado a «¿Qué llegó?», y no al final de la
             * línea: al final ya se tecleó la cantidad y el costo, y
             * volver a subir a leer algo que apareció abajo es lo que no
             * pasa cuando hay una fila de cajas esperando.
             */
            Placeholder::make('se_debe')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::loQueSeDebeDeLaLinea($get) !== null)
                ->content(fn (Get $get): HtmlString => new HtmlString(
                    '<div style="border-left:3px solid rgb(217 119 6);padding:0.5rem 0.75rem;'
                    .'background:rgb(254 243 199 / 0.45);border-radius:0.25rem;font-size:0.8125rem;'
                    .'line-height:1.35;">'
                    .'<strong>Prestado.</strong> '
                    .e((string) self::loQueSeDebeDeLaLinea($get))
                    .' Devolvelo desde Préstamos cuando esta recepción quede guardada.'
                    .'</div>'
                )),

            /* A qué acotó el escaneo. De pantalla: no se guarda. */
            Hidden::make('acotado_a')->dehydrated(false),

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
    /**
     * Lo que pasa por el lector, y las TRES cosas que puede ser.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL ESCANEO PROPONE; ELEGIR POR ALGUIEN ES OTRA COSA
     * ─────────────────────────────────────────────────────────────────
     *
     *   1. **El EAN del fabricante**, impreso en la caja. Identifica una
     *      presentación exacta: no hay nada que decidir, se pone.
     *   2. **La etiqueta de la gaveta** (`PA-0001`). Identifica un
     *      PRINCIPIO ACTIVO, no un envase: acota la lista a lo que lo
     *      lleva y deja elegir.
     *   3. **La etiqueta del hospital** (`MED-101`). Identifica el
     *      PRODUCTO, no el envase: acota a sus presentaciones.
     *
     * Antes, el caso 3 elegía la presentación predeterminada. Con un
     * producto que viene en caja de 100 y en caja de 50 eso es adivinar
     * —y el campo quedaba lleno, así que nadie lo revisaba—: la
     * existencia entraba en el envase equivocado sin que se notara.
     *
     * ⚠️ La excepción, tomada del mismo criterio que usa la pantalla de
     * cargos: **uno solo no es una elección.** Si de lo escaneado sale
     * una única presentación posible, se pone, porque abrir un
     * desplegable de un renglón es un clic que no decide nada.
     */
    private static function agregarLoEscaneado(mixed $state, Get $get, Set $set): void
    {
        $codigo = trim(is_string($state) ? $state : '');

        if ($codigo === '') {
            return;
        }

        $set('escaneo', null);

        if (str_starts_with(mb_strtoupper($codigo), PrincipioActivo::PREFIJO)) {
            self::escaneoDeGaveta($codigo, $get, $set);

            return;
        }

        $presentacion = ItemPresentacion::query()->where('codigo_barras', $codigo)->first();

        if ($presentacion instanceof ItemPresentacion) {
            self::abrirLinea($get, $set, presentacion: $presentacion);

            Notification::make()
                ->success()
                ->title($presentacion->etiqueta())
                ->body('Agregado. Poné la cantidad, el costo y el lote.')
                ->send();

            return;
        }

        self::escaneoDeProducto($codigo, $get, $set);
    }

    /**
     * La etiqueta de la gaveta: acota a lo que lleva ese principio.
     *
     * Los dos avisos son distintos a propósito, igual que en la pantalla
     * de cargos: una gaveta vieja se arregla reimprimiendo la etiqueta;
     * un principio sin productos vigentes se arregla en la ficha del
     * producto. Un solo mensaje mandaría a la mitad al lugar equivocado.
     */
    private static function escaneoDeGaveta(string $codigo, Get $get, Set $set): void
    {
        $principio = PrincipioActivo::query()
            ->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])
            ->first();

        if (! $principio instanceof PrincipioActivo) {
            Notification::make()
                ->warning()
                ->title('Esa etiqueta no está en el catálogo')
                ->body(
                    'El código arranca con «'.PrincipioActivo::PREFIJO.'», así que es una etiqueta '
                    .'de gaveta, pero ningún principio activo la tiene. Puede ser de una gaveta '
                    .'vieja: reimprimí la etiqueta desde Farmacia → Principios activos.'
                )
                ->persistent()
                ->send();

            return;
        }

        $items = $principio->productosVigentes()
            ->filter(fn (Item $item): bool => (bool) $item->se_almacena);

        if ($items->isEmpty()) {
            Notification::make()
                ->warning()
                ->title('Nada que recibir con '.$principio->nombre)
                ->body(
                    'La gaveta está etiquetada pero hoy ningún producto vigente del catálogo lo '
                    .'lleva. Se vincula desde la ficha del producto, en «Principios activos».'
                )
                ->persistent()
                ->send();

            return;
        }

        self::abrirLinea($get, $set, acotadoA: 'pa:'.$principio->getKey(), candidatos: $items);

        Notification::make()
            ->success()
            ->title($principio->nombre)
            ->body('Elegí en «¿Qué llegó?» cuál de sus presentaciones entró.')
            ->send();
    }

    /**
     * La etiqueta del hospital: el PRODUCTO, sin decir en qué envase.
     *
     * Los dos avisos vuelven a ser distintos: el código que no existe en
     * ningún lado, y el producto que existe pero todavía no tiene en qué
     * envase viene. El segundo se soluciona en veinte segundos y no hay
     * que confundirlo con el primero.
     */
    private static function escaneoDeProducto(string $codigo, Get $get, Set $set): void
    {
        $item = Item::query()->where('codigo', $codigo)->first();

        if (! $item instanceof Item) {
            Notification::make()
                ->warning()
                ->title('Ese código no está en el catálogo')
                ->body(
                    'Ni como código de barras de un envase ni como código de producto. Revisá que '
                    .'sea el correcto, o dalo de alta en Farmacia → Productos.'
                )
                ->persistent()
                ->send();

            return;
        }

        if (! $item->se_almacena) {
            Notification::make()
                ->warning()
                ->title($item->nombre.' no se almacena')
                ->body(
                    'No lleva existencia ni kardex, así que no se recibe: no hay nada que guardar '
                    .'en un estante.'
                )
                ->persistent()
                ->send();

            return;
        }

        self::abrirLinea($get, $set, acotadoA: 'item:'.$item->getKey(), candidatos: new ColeccionDeModelos([$item]));

        Notification::make()
            ->success()
            ->title($item->nombre)
            ->body('Elegí en «¿Qué llegó?» en qué envase vino.')
            ->send();
    }

    /**
     * Agrega la línea: con la presentación puesta cuando no había nada
     * que decidir, y si no acotada y esperando que elijan.
     *
     * @param ColeccionDeModelos<int, Item>|null $candidatos
     */
    private static function abrirLinea(
        Get $get,
        Set $set,
        ?ItemPresentacion $presentacion = null,
        ?string $acotadoA = null,
        ?ColeccionDeModelos $candidatos = null,
    ): void {
        /* «Uno solo no es una elección». Ver el encabezado del escaneo. */
        if (! $presentacion instanceof ItemPresentacion && $candidatos instanceof ColeccionDeModelos) {
            $posibles = self::opcionesDeItems($candidatos);

            if (count($posibles) === 1) {
                [, $presentacion] = self::partirLoQueLlego((string) array_key_first($posibles));
                $acotadoA = null;
            }
        }

        $item = $presentacion?->item_id;

        /** @var array<string, mixed> $lineas */
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        $lineas[(string) Str::uuid()] = [
            /*
             * ⚠️ `que_llego` también, aunque no se guarde: es el campo
             * que se VE. Sin él la línea escaneada aparecía con el
             * selector vacío y parecía que el escaneo no había servido.
             */
            'que_llego'                 => $item === null ? null : $item.':'.$presentacion?->id,
            'acotado_a'                 => $acotadoA,
            'item_id'                   => $item,
            'item_presentacion_id'      => $presentacion?->id,
            'cantidad_presentacion'     => '1',
            'unidades_por_presentacion' => $presentacion->unidades_por_presentacion ?? '1',
            'costo_por_presentacion'    => '0',
            'numero_lote'               => null,
            'fecha_vencimiento'         => null,
        ];

        $set('lineas', $lineas);
    }

    /**
     * Lo que se le debe a alguien del producto de ESTA línea, o null.
     *
     * Se pregunta por el producto de la línea y no por la recepción
     * entera porque el aviso vive dentro de la línea: lo que hace falta
     * ahí es la deuda de la caja que se acaba de elegir.
     *
     * ⚠️ `item_id` y no `que_llego`: el selector guarda
     * «item:presentacion» y el producto ya viene derivado en el `Hidden`
     * de al lado. Partir la cadena otra vez acá sería una segunda copia
     * de esa regla, y la segunda copia es la que se olvida de actualizar.
     */
    private static function loQueSeDebeDeLaLinea(Get $get): ?string
    {
        $itemId = $get('item_id');

        if (! is_numeric($itemId)) {
            return null;
        }

        $id = (int) $itemId;

        /*
         * ⚠️ Memorizado por producto. `visible()` y `content()` preguntan
         * lo mismo, y el repetidor los llama a los dos por cada línea: una
         * compra de veinte renglones son sesenta consultas idénticas
         * mientras alguien teclea. El caché vive lo que vive el request
         * de Livewire, así que saldar un préstamo se ve en el siguiente
         * ida y vuelta.
         *
         * `array_key_exists` y no `??=`: la respuesta normal es null —no
         * se debe nada— y con `??=` justamente ese caso volvería a
         * consultar siempre.
         */
        if (! array_key_exists($id, self::$deudaPorItem)) {
            self::$deudaPorItem[$id] = app(AvisoDeLoQueSeDebe::class)->delItem($id);
        }

        return self::$deudaPorItem[$id];
    }

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

    /**
     * Todo lo que puede entrar por la puerta: cada presentación del
     * catálogo, y la unidad suelta de cada producto.
     *
     * ⚠️ Solo lo que SE ALMACENA. Antes listaba `Item::query()` entero,
     * así que se podía «recibir» una consulta externa o una hora de
     * quirófano — cosas que no tienen estante ni kardex.
     *
     * @return array<string, string>
     */
    private static function loQuePuedeLlegar(?string $termino = null): array
    {
        $items = $termino === null || trim($termino) === ''
            ? Item::query()->where('se_almacena', true)->orderBy('nombre')->limit(25)->get()
            : Item::buscar($termino, soloVigentes: true)->where('se_almacena', true);

        return self::opcionesDeItems($items);
    }

    /**
     * Las opciones que le tocan a ESTA línea.
     *
     * Acotada por lo que se escaneó, si se escaneó algo que no alcanzaba
     * para decidir: la gaveta acota al principio activo, la etiqueta del
     * hospital al producto.
     *
     * @return array<string, string>
     */
    private static function opcionesDeLaLinea(Get $get): array
    {
        $acotado = $get('acotado_a');

        if (! is_string($acotado) || ! str_contains($acotado, ':')) {
            return self::loQuePuedeLlegar();
        }

        [$que, $cual] = explode(':', $acotado, 2);

        if ($que === 'item') {
            $item = self::itemDe($cual);

            return $item instanceof Item
                ? self::opcionesDeItems(new ColeccionDeModelos([$item]))
                : self::loQuePuedeLlegar();
        }

        $principio = PrincipioActivo::query()->find($cual);

        if (! $principio instanceof PrincipioActivo) {
            return self::loQuePuedeLlegar();
        }

        return self::opcionesDeItems(
            $principio->productosVigentes()->filter(fn (Item $item): bool => (bool) $item->se_almacena)
        );
    }

    /**
     * Cada presentación de esos productos, más su unidad suelta.
     *
     * ⚠️ Pide una colección de ELOQUENT y no una cualquiera: `load()`
     * —el que evita las cincuenta consultas de abajo— no existe en una
     * `Support\Collection`. Un `collect([$item])` acá reventaba en el
     * primer escaneo de un producto.
     *
     * @param ColeccionDeModelos<int, Item> $items
     *
     * @return array<string, string>
     */
    private static function opcionesDeItems(ColeccionDeModelos $items): array
    {
        /* Sin esto, veinticinco productos son cincuenta consultas. */
        $items->load(['presentaciones', 'unidadDispensacion']);

        $opciones = [];

        foreach ($items as $item) {
            foreach ($item->presentaciones->sortBy('nombre') as $presentacion) {
                $opciones[$item->id.':'.$presentacion->id] = $item->etiqueta().' · '.$presentacion->nombre;
            }

            $opciones[$item->id.':0'] = $item->etiqueta()
                .' · suelto, por '.($item->unidadDispensacion->simbolo ?? 'unidad');
        }

        return $opciones;
    }

    /** Cómo se lee `item:presentacion` en la pantalla. */
    private static function comoSeLlama(mixed $valor): ?string
    {
        [$item, $presentacion] = self::partirLoQueLlego($valor);

        if (! $item instanceof Item) {
            return null;
        }

        return $item->etiqueta().' · '.($presentacion instanceof ItemPresentacion
            ? $presentacion->envase()
            : 'suelto, por '.($item->unidadDispensacion->simbolo ?? 'unidad'));
    }

    /**
     * Parte `item:presentacion` en sus dos mitades. Presentación 0 —o
     * ausente— es lo suelto.
     *
     * @return array{0: Item|null, 1: ItemPresentacion|null}
     */
    private static function partirLoQueLlego(mixed $valor): array
    {
        if (! is_string($valor) || ! str_contains($valor, ':')) {
            return [null, null];
        }

        [$item, $presentacion] = explode(':', $valor, 2);

        return [self::itemDe($item), self::presentacionDe($presentacion)];
    }

    /**
     * 🔴 Dos líneas con la misma caja Y el mismo lote son la misma cosa
     * contada dos veces.
     *
     * Si de verdad llegaron dos cajas de ese lote, van sumadas en una
     * línea: para el kardex son la misma existencia. Con lotes distintos
     * no se toca nada, que es todo el punto de este cambio.
     */
    private static function sinLineasRepetidas(): Closure
    {
        return static function (string $atributo, mixed $valor, Closure $fallar): void {
            if (! is_array($valor)) {
                return;
            }

            $vistas = [];

            foreach ($valor as $linea) {
                if (! is_array($linea)) {
                    continue;
                }

                $item = is_numeric($linea['item_id'] ?? null) ? (int) $linea['item_id'] : 0;
                $presentacion = is_numeric($linea['item_presentacion_id'] ?? null)
                    ? (int) $linea['item_presentacion_id']
                    : 0;

                $lote = mb_strtoupper(trim(is_string($linea['numero_lote'] ?? null)
                    ? $linea['numero_lote']
                    : ''));

                $clave = $item.':'.$presentacion.':'.$lote;

                if (! isset($vistas[$clave])) {
                    $vistas[$clave] = true;

                    continue;
                }

                $que = self::comoSeLlama($item.':'.$presentacion) ?? 'Un renglón';

                $fallar($lote === ''
                    ? "«{$que}» está dos veces y ninguna tiene número de lote: sin lote no hay "
                        .'cómo distinguirlas. Sumalas en una sola línea, o poné el lote de cada una.'
                    : "«{$que}» está dos veces con el lote {$lote}. Si llegaron dos cajas de ese "
                        .'mismo lote, sumalas en una línea; si son lotes distintos, corregí el número.');

                return;
            }
        };
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
