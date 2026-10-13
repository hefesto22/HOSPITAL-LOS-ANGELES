<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Schemas;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrincipioActivo;
use App\Models\Proveedor;
use App\Models\Unidad;
use App\Services\AvisoDeLoQueSeDebe;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
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
 * Cada lectura agrega una línea con el producto y la presentación ya
 * puestos. Solo queda teclear cuántas y el lote.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 SOLO LO QUE ENTRÓ, SIN COSTOS (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: «que solo coloquen el registro de lo que entró, sin
 * costos ni nada de eso». Quien recibe en bodega no maneja facturas —la
 * matriz de permisos ya le niega Compras— y el precio de farmacia ya no
 * sale del costo: se escribe como precio mínimo del envase.
 *
 * Las líneas son una TABLA: qué llegó, cuántas, lo que entra, lote y
 * vence. La columna «Entran» dice lo que va a entrar al kardex —«200
 * TAB»—, que es lo que hace notar ANTES de guardar que se eligió el envase
 * equivocado. Después de guardar, el kardex ya se movió.
 *
 * Desaparecieron «Costo por caja», «Trae» y «Entra al kardex». El
 * contenido del envase lo pone la presentación; si el laboratorio cambió
 * la caja, se carga otra presentación en la ficha.
 */
final class RecepcionForm
{
    /**
     * Lo que se debe de los productos de la recepción, respondido una
     * sola vez por request y por juego de productos. Ver `loQueSeDebe()`.
     *
     * @var array<string, string|null>
     */
    private static array $deudaPorProductos = [];

    /**
     * Lo que encontró cada nombre buscado, respondido una sola vez por
     * request: cada botón de resultado pregunta su rótulo y si se ve, y
     * sin esto eran dos búsquedas al catálogo por botón.
     *
     * @var array<string, array<int, string>>
     */
    private static array $encontradosPorTexto = [];

    /** Cuántos botones de resultado se ofrecen debajo del buscador. */
    private const RESULTADOS_A_LA_VISTA = 8;

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
                    ->helperText('Lo del proveedor normalmente entra a BODEGA; de ahí se baja a farmacia.'),

                DatePicker::make('fecha_recepcion')
                    ->label('¿Cuándo llegó?')
                    ->columnSpan(2)
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->helperText('La fecha con la que quedan los movimientos.'),

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
     * almacén, fecha, producto, cantidad y lote. Los dos campos de acá
     * estorbaban en la pantalla del día a día y por eso se pliegan.
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
            ->description('Opcional. Sirve para saber a quién reclamar si ARSA retira un lote.')
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

                CampoMayusculas::make('referencia')
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
                    ->label('Escaneá el código o escribí el nombre')
                    ->columnSpanFull()
                    ->dehydrated(false)
                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 SE BUSCA AL APRETAR ENTER, NO MIENTRAS SE ESCRIBE
                     * ─────────────────────────────────────────────────
                     *
                     * Mauricio (11-oct-2026): «no me deja escribir ahí».
                     * El campo buscaba solo en cada pausa del teclado, y
                     * la búsqueda lo limpia siempre —para que la pistola
                     * pueda leer la caja siguiente sin borrar a mano—.
                     * Quien escribía «MED-101» a mano y paraba en «MED-1»
                     * se quedaba con el campo vacío y un aviso de «no
                     * está en el catálogo». Con o sin pausa, el problema
                     * era el mismo: buscar un código a medio escribir.
                     *
                     * Ahora el campo NO es `live()`: solo se manda al
                     * apretar Enter. La pistola ya manda Enter sola al
                     * final de cada lectura, y a mano es lo natural.
                     *
                     * ⚠️ El Enter se ataja con `.prevent`: sin eso,
                     * además de buscar, mandaba a guardar la recepción
                     * entera y abría el recuadro de confirmación con una
                     * línea a medio llenar. `$wire.set` dispara igual el
                     * `afterStateUpdated` de abajo, que no depende de
                     * `live()`. La cámara del plugin hace lo mismo
                     * (`$wire.set` al leer), así que sigue funcionando.
                     */
                    ->extraInputAttributes(fn (BarcodeInput $component): array => [
                        'x-on:keydown.enter.prevent' => '$wire.set('.Js::from($component->getStatePath()).', $event.target.value)',
                    ])
                    ->autofocus()
                    ->placeholder('Disparale a la caja, o escribí el nombre y apretá Enter')
                    ->helperText('Con la pistola o la cámara cada lectura agrega una línea. Por nombre, elegí abajo cuál.')
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::agregarLoEscaneado($state, $get, $set)),

                /*
                 * Lo que se buscó por nombre, para pintar los botones de
                 * abajo. De pantalla: no se guarda.
                 */
                Hidden::make('busqueda')->dehydrated(false),

                /*
                 * ─────────────────────────────────────────────────────
                 * POR NOMBRE: UN BOTÓN POR PRODUCTO, DEBAJO DEL CAMPO
                 * ─────────────────────────────────────────────────────
                 *
                 * Mauricio (11-oct-2026): «si la busco por nombre no la
                 * encuentra para agregarla». El campo solo entendía
                 * códigos. Ahora, si lo escrito no es el código de nada,
                 * se busca por nombre: con uno solo se agrega directo, con
                 * varios aparecen acá para tocar el que es — igual que en
                 * el cobro de Cuentas abiertas.
                 *
                 * ⚠️ Casilleros fijos y no una lista armada con lo
                 * escrito: Filament guarda en memoria los hijos de un
                 * componente durante la petición, y una lista que cambia
                 * se pintaría con los resultados de la búsqueda anterior
                 * (§9.M-7).
                 */
                Actions::make(self::botonesDeLaBusqueda())
                    ->key('resultados_de_la_busqueda')
                    ->columnSpanFull(),

                Repeater::make('lineas')
                    ->hiddenLabel()
                    ->addActionLabel('Agregar a mano')
                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 UNA TABLA: LOS TÍTULOS UNA VEZ, UNA FILA POR CAJA
                     * ─────────────────────────────────────────────────
                     *
                     * Mauricio (11-oct-2026): «que sea más fácil y rápido
                     * registrar». Cada línea era una tarjeta con su
                     * encabezado —que repetía el nombre que ya decía el
                     * desplegable— y los cuatro títulos de los campos
                     * repetidos en cada una. Veinte cajas eran veinte
                     * tarjetas. En tabla, los títulos van una sola vez
                     * arriba y cada caja es un renglón.
                     *
                     * ⚠️ Las columnas van en el MISMO orden que los campos
                     * visibles de `camposDeLaLinea()`: la tabla las empareja
                     * por posición, no por nombre, y los `Hidden` no
                     * cuentan. Un campo de más se queda sin columna y no se
                     * dibuja.
                     */
                    ->table([
                        TableColumn::make('¿Qué llegó?')->width('42%')->markAsRequired(),
                        TableColumn::make('¿Cuántas?')->width('15%')->markAsRequired(),
                        TableColumn::make('Entran')->width('11%'),
                        TableColumn::make('Lote')->width('16%'),
                        TableColumn::make('Vence el')->width('16%'),
                    ])
                    /*
                     * ─────────────────────────────────────────────────
                     * OTRO LOTE DE LO MISMO, A UN CLIC
                     * ─────────────────────────────────────────────────
                     *
                     * Regla de Mauricio: la misma caja puede venir dos
                     * veces «siempre y cuando el lote y la fecha de
                     * vencimiento no sean los mismos». Es lo normal: el
                     * proveedor manda dos producciones del mismo
                     * producto. El botón repite la fila con lo mismo
                     * elegido y el lote y el vencimiento VACÍOS: copiarlos
                     * obligaría a borrarlos, y olvidarse de borrarlos es
                     * justo el duplicado que se rechaza al guardar.
                     */
                    ->extraItemActions([
                        Action::make('otro_lote')
                            ->icon('heroicon-m-square-2-stack')
                            ->color('gray')
                            ->tooltip('Otro lote de lo mismo')
                            ->action(function (array $arguments, Get $get, Set $set): void {
                                self::otroLoteDe($arguments['item'] ?? null, $get, $set);
                            }),
                    ])

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
                     * El mismo lote con OTRA fecha tampoco pasa, pero lo
                     * frena el registrador y no esta regla: un lote tiene
                     * un solo vencimiento, impreso en la caja, así que dos
                     * fechas para el mismo número son un error de tipeo.
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
                    ->schema(self::camposDeLaLinea()),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 SI DE ESTO SE LE DEBE A ALGUIEN, SE DICE ACÁ
                 * ─────────────────────────────────────────────────────
                 *
                 * Regla de Mauricio: «cuando a nosotros nos entre de ese
                 * medicamento a bodega o farmacia, que aparezca que hay
                 * que devolverle x cantidad a x empresa o persona».
                 *
                 * Un préstamo se pide un martes a las once de la noche
                 * porque no había, y solo se puede devolver el día que
                 * llega la compra. La pantalla de Préstamos sirve para
                 * consultar, pero nadie la abre por las mañanas: este
                 * aviso, con la caja en la mano, es el momento.
                 *
                 * Va UNA vez debajo de la tabla, por todos los productos
                 * de la recepción, y no dentro de cada fila: en una tabla
                 * no hay renglón a lo ancho, y el aviso dentro de una
                 * celda angosta no se leería. Se repite en la
                 * confirmación y después de guardar (`CreateRecepcion`).
                 */
                Placeholder::make('se_debe')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => self::loQueSeDebe($get) !== null)
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="border-left:3px solid rgb(217 119 6);padding:0.5rem 0.75rem;'
                        .'background:rgb(254 243 199 / 0.45);border-radius:0.25rem;font-size:0.8125rem;'
                        .'line-height:1.35;">'
                        .'<strong>Prestado.</strong> '
                        .e((string) self::loQueSeDebe($get))
                        .' Devolvelo desde Préstamos cuando esta recepción quede guardada.'
                        .'</div>'
                    )),

                Placeholder::make('resumen')
                    ->hiddenLabel()
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
             * 🔴 «SIN ENVASE» TAMBIÉN está en la lista, y no es un
             * adorno. Al cargar el inventario que ya existe, de una caja
             * de 100 tabletas quedan 20 porque las otras se vendieron
             * antes de que hubiera sistema. «1 caja» sería mentira y
             * «0.2 cajas» peor: se registran 20 tabletas sueltas. Sin
             * esta opción ese saldo no se puede cargar.
             *
             * ⚠️ Se llama así y no «suelto, por fco» por lo que vio
             * Mauricio: puesto donde va el nombre del envase, ese texto
             * se leía como un envase más. Ver `asiLlegaSuelto()`.
             *
             * El valor es `item:presentacion` —con 0 para lo suelto—
             * porque un Select guarda un escalar, y las dos columnas que
             * de verdad se guardan viajan en los `Hidden` de abajo.
             */
            Select::make('que_llego')
                ->hiddenLabel()
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

                    foreach (self::datosDeLaLinea($item, $presentacion) as $campo => $valor) {
                        $set($campo, $valor);
                    }
                }),

            Hidden::make('item_id'),
            Hidden::make('item_presentacion_id'),

            /*
             * El contenido del envase viaja escondido: lo pone la
             * presentación y el registrador lo congela en la línea.
             */
            Hidden::make('unidades_por_presentacion')->default('1'),

            /*
             * Cómo se lee la línea —«CAJA» al lado del número, «= 200
             * TAB» arriba—, guardado al elegir. De pantalla: no se guarda.
             * Leerlo de la base en cada pintada eran tres consultas por
             * línea y por tecla.
             */
            Hidden::make('se_cuenta_en')->dehydrated(false),
            Hidden::make('entra_en')->dehydrated(false),
            Hidden::make('pide_lote')->dehydrated(false),

            /* A qué acotó el escaneo. De pantalla: no se guarda. */
            Hidden::make('acotado_a')->dehydrated(false),

            TextInput::make('cantidad_presentacion')
                ->hiddenLabel()
                ->numeric()
                ->required()
                ->minValue(0.0001)
                ->default('1')
                ->live(onBlur: true)
                /*
                 * 🔴 En qué se cuenta, pegado al número —«CAJA», «FRASCO»,
                 * «TAB» si llegó suelto— y en la columna de al lado lo que
                 * eso pone en el kardex. «2» escrito en una caja de cien y «2» escrito
                 * en tabletas son el mismo carácter y son 198 tabletas de
                 * diferencia en el estante.
                 */
                ->suffix(fn (Get $get): ?string => self::textoONulo($get('se_cuenta_en'))),

            Placeholder::make('entran')
                ->hiddenLabel()
                ->content(fn (Get $get): HtmlString => new HtmlString(
                    '<span style="font-size:.875rem;white-space:nowrap">'
                    .e(self::loQueEntra($get) ?? '—')
                    .'</span>'
                )),

            TextInput::make('numero_lote')
                ->hiddenLabel()
                ->maxLength(60)
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
                ->placeholder('Impreso en la caja')
                /*
                 * Obligatorio cuando el producto lo exige —un medicamento
                 * por trazabilidad ante ARSA—. El registrador lo vuelve a
                 * exigir al guardar; acá es para que el asterisco avise
                 * antes de apretar Crear.
                 */
                ->required(fn (Get $get): bool => $get('pide_lote') === true),

            /*
             * ⚠️ El selector NATIVO del navegador, y es por velocidad: la
             * fecha se copia de la caja, y en el nativo se teclea de un
             * saque —30, 06, 2027—. En el calendario de Filament había
             * que ir mes por mes hasta un vencimiento que suele estar a
             * dos o tres años.
             */
            DatePicker::make('fecha_vencimiento')
                ->hiddenLabel()
                ->requiredWith('numero_lote'),
        ];
    }

    private static function lasNotas(): Section
    {
        return Section::make('Notas')
            ->collapsed()
            ->schema([
                CampoMayusculas::area('notas')
                    ->hiddenLabel()
                    ->rows(3)
                    ->placeholder('Opcional: llegó una caja golpeada, faltó una del pedido, lo que sea.'),
            ]);
    }

    // ── El escaneo ────────────────────────────────────────────────────

    /**
     * Lo que pasa por el lector, y las TRES cosas que puede ser.
     *
     * El campo se limpia cuando lo leído se resolvió, para que el
     * siguiente escaneo entre sin borrar a mano. Con la pistola eso es la
     * diferencia entre recibir un camión en dos minutos o en veinte.
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
     *
     * Y si no es ninguna de las tres, es un NOMBRE escrito a mano
     * (11-oct-2026): ver `buscarPorNombre()`.
     *
     * ⚠️ El campo se limpia solo cuando lo leído se resolvió. Un nombre
     * con varios resultados se queda escrito, para poder afinarlo.
     */
    private static function agregarLoEscaneado(mixed $state, Get $get, Set $set): void
    {
        $codigo = trim(is_string($state) ? $state : '');

        $set('busqueda', null);

        if ($codigo === '') {
            return;
        }

        if (str_starts_with(mb_strtoupper($codigo), PrincipioActivo::PREFIJO)) {
            $set('escaneo', null);
            self::escaneoDeGaveta($codigo, $get, $set);

            return;
        }

        $presentacion = ItemPresentacion::query()->where('codigo_barras', $codigo)->first();

        if ($presentacion instanceof ItemPresentacion) {
            $set('escaneo', null);
            self::abrirLinea($get, $set, presentacion: $presentacion);

            Notification::make()
                ->success()
                ->title($presentacion->etiqueta())
                ->body('Agregado. Poné cuántas y el lote.')
                ->send();

            return;
        }

        /*
         * El código del producto —el de la caja del fabricante, que
         * heredan sus envases— o el interno. Dice QUÉ llegó, no en qué
         * envase: eso se elige en la línea.
         */
        $item = Item::porCodigo($codigo);

        if ($item instanceof Item) {
            $set('escaneo', null);
            self::abrirLineaDelProducto($item, $get, $set);

            return;
        }

        self::buscarPorNombre($codigo, $get, $set);
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
                    .'vieja: reimprimí la etiqueta desde Farmacia y servicios → Principios activos.'
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
     * El PRODUCTO, sin decir en qué envase: lo que dicen el código de la
     * caja, la etiqueta del hospital y el nombre elegido de la lista.
     *
     * La línea queda acotada a sus envases y el envase lo elige quien
     * recibe —salvo que haya uno solo posible—. Un producto que no se
     * almacena se avisa aparte: no es un error de código, es que no hay
     * nada que poner en un estante.
     */
    private static function abrirLineaDelProducto(Item $item, Get $get, Set $set): void
    {
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
     * Lo escrito no era el código de nada: se busca por nombre.
     *
     *   · Un producto  → se agrega la línea directo.
     *   · Varios       → aparecen como botones debajo del campo, y lo
     *                    escrito se queda para poder afinarlo.
     *   · Ninguno      → se dice, y depende de qué parecía:
     *
     * ⚠️ Lo que tiene números y no tiene espacios se lee como un CÓDIGO
     * que no existe —lo más común es la pistola leyendo una caja que no
     * está cargada— y el campo se limpia para que la caja siguiente entre
     * limpia. Un nombre mal escrito, en cambio, se deja para corregirlo:
     * borrarlo es obligar a escribirlo de nuevo entero.
     */
    private static function buscarPorNombre(string $texto, Get $get, Set $set): void
    {
        $encontrados = mb_strlen($texto) >= 3 ? self::buscarEnElCatalogo($texto) : [];

        if (count($encontrados) === 1) {
            $item = Item::query()->find((int) array_key_first($encontrados));

            if ($item instanceof Item) {
                $set('escaneo', null);
                self::abrirLineaDelProducto($item, $get, $set);

                return;
            }
        }

        if ($encontrados !== []) {
            $set('busqueda', $texto);

            return;
        }

        $pareceCodigo = preg_match('/\d/', $texto) === 1 && ! str_contains($texto, ' ');

        if ($pareceCodigo) {
            $set('escaneo', null);
        }

        Notification::make()
            ->warning()
            ->title($pareceCodigo ? 'Ese código no está en el catálogo' : 'No hay nada con «'.$texto.'»')
            ->body($pareceCodigo
                ? 'Ningún producto lo tiene. Revisá que sea el correcto, o cargáselo al producto en su '
                    .'ficha, en Identificación.'
                : 'Ni por código ni por nombre. Probá con menos letras, o con el principio activo.')
            ->send();
    }

    /**
     * Productos que se almacenan y se llaman parecido, ya con su rótulo.
     *
     * @return array<int, string>
     */
    private static function buscarEnElCatalogo(string $texto): array
    {
        if (! array_key_exists($texto, self::$encontradosPorTexto)) {
            self::$encontradosPorTexto[$texto] = Item::buscar($texto, limite: 25, soloVigentes: true)
                ->filter(fn (Item $item): bool => (bool) $item->se_almacena)
                ->take(self::RESULTADOS_A_LA_VISTA)
                ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                ->all();
        }

        return self::$encontradosPorTexto[$texto];
    }

    /**
     * Un botón por resultado, en casilleros fijos. Tocar uno agrega la
     * línea de ese producto y limpia el buscador para el siguiente.
     *
     * @return list<Action>
     */
    private static function botonesDeLaBusqueda(): array
    {
        $botones = [];

        for ($casillero = 0; $casillero < self::RESULTADOS_A_LA_VISTA; $casillero++) {
            $botones[] = Action::make('agregar_resultado_'.$casillero)
                ->label(fn (Get $get): string => self::resultadoEn($get, $casillero)['nombre'] ?? '')
                ->visible(fn (Get $get): bool => self::resultadoEn($get, $casillero) !== null)
                ->icon('heroicon-m-plus')
                ->size(Size::Small)
                ->color('gray')
                ->outlined()
                ->action(function (Get $get, Set $set) use ($casillero): void {
                    $resultado = self::resultadoEn($get, $casillero);
                    $item = $resultado === null ? null : Item::query()->find($resultado['id']);

                    if (! $item instanceof Item) {
                        return;
                    }

                    $set('escaneo', null);
                    $set('busqueda', null);
                    self::abrirLineaDelProducto($item, $get, $set);
                });
        }

        return $botones;
    }

    /**
     * @return array{id: int, nombre: string}|null
     */
    private static function resultadoEn(Get $get, int $casillero): ?array
    {
        $texto = self::textoONulo($get('busqueda'));

        if ($texto === null) {
            return null;
        }

        $encontrados = self::buscarEnElCatalogo($texto);
        $id = array_keys($encontrados)[$casillero] ?? null;

        return $id === null ? null : ['id' => $id, 'nombre' => $encontrados[$id]];
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
        $elegido = $presentacion?->item;

        /* «Uno solo no es una elección». Ver el encabezado del escaneo. */
        if (! $presentacion instanceof ItemPresentacion && $candidatos instanceof ColeccionDeModelos) {
            $posibles = self::opcionesDeItems($candidatos);

            if (count($posibles) === 1) {
                /*
                 * ⚠️ El producto también, no solo la presentación. Un
                 * producto sin envases cargados tiene una sola opción —la
                 * suelta—, y quedándose solo con la presentación (nula)
                 * la línea se abría vacía, como si el escaneo no hubiera
                 * servido.
                 */
                [$elegido, $presentacion] = self::partirLoQueLlego((string) array_key_first($posibles));
                $acotadoA = null;
            }
        }

        $item = $elegido?->id;

        /** @var array<string, mixed> $lineas */
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        $lineas[(string) Str::uuid()] = [
            /*
             * ⚠️ `que_llego` también, aunque no se guarde: es el campo
             * que se VE. Sin él la línea escaneada aparecía con el
             * selector vacío y parecía que el escaneo no había servido.
             */
            'que_llego'             => $item === null ? null : $item.':'.($presentacion->id ?? 0),
            'acotado_a'             => $acotadoA,
            'cantidad_presentacion' => '1',
            'numero_lote'           => null,
            'fecha_vencimiento'     => null,
            ...self::datosDeLaLinea($elegido, $presentacion),
        ];

        $set('lineas', $lineas);
    }

    /**
     * Lo que se le debe a alguien de los productos de la recepción, o
     * null si no se debe nada.
     *
     * ⚠️ `item_id` y no `que_llego`: el selector guarda
     * «item:presentacion» y el producto ya viene derivado en el `Hidden`
     * de al lado. Partir la cadena otra vez acá sería una segunda copia
     * de esa regla, y la segunda copia es la que se olvida de actualizar.
     *
     * ⚠️ Memorizado por juego de productos: `visible()` y `content()`
     * preguntan lo mismo en cada pintada. `array_key_exists` y no `??=`:
     * la respuesta normal es null —no se debe nada— y con `??=` ese caso
     * volvería a consultar siempre.
     */
    private static function loQueSeDebe(Get $get): ?string
    {
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        $ids = [];

        foreach ($lineas as $linea) {
            if (is_array($linea) && is_numeric($linea['item_id'] ?? null)) {
                $ids[] = (int) $linea['item_id'];
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return null;
        }

        sort($ids);
        $clave = implode(',', $ids);

        if (! array_key_exists($clave, self::$deudaPorProductos)) {
            self::$deudaPorProductos[$clave] = app(AvisoDeLoQueSeDebe::class)->frase($ids);
        }

        return self::$deudaPorProductos[$clave];
    }

    /**
     * Repite la fila con lo mismo elegido y el lote vacío, justo debajo.
     *
     * Debajo y no al final: las dos producciones de la misma caja se leen
     * juntas, y es donde la vista las va a buscar para comparar lotes.
     */
    private static function otroLoteDe(mixed $clave, Get $get, Set $set): void
    {
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        if (! is_string($clave) || ! is_array($lineas[$clave] ?? null)) {
            return;
        }

        $nuevas = [];

        foreach ($lineas as $cual => $linea) {
            $nuevas[$cual] = $linea;

            if ((string) $cual === $clave && is_array($linea)) {
                $nuevas[(string) Str::uuid()] = [
                    ...$linea,
                    'cantidad_presentacion' => '1',
                    'numero_lote'           => null,
                    'fecha_vencimiento'     => null,
                ];
            }
        }

        $set('lineas', $nuevas);
    }

    /**
     * Lo que se deriva de la caja elegida, listo para escribir en la
     * línea: los dos ids que se guardan, el contenido que se congela, y
     * los dos textos con que la línea se lee.
     *
     * Vive en UN lugar porque la línea nace por dos caminos —elegida en
     * el desplegable o abierta por el escaneo— y los dos tienen que
     * dejarla igual. Antes cada uno armaba su propio arreglo y el día que
     * se agregaba un campo se agregaba en uno solo.
     *
     * @return array<string, bool|int|string|null>
     */
    private static function datosDeLaLinea(?Item $item, ?ItemPresentacion $presentacion): array
    {
        $unidad = $item instanceof Item ? self::codigoDeLaUnidad($item) : null;

        return [
            'item_id'                   => $item?->id,
            'item_presentacion_id'      => $presentacion?->id,
            'unidades_por_presentacion' => $presentacion->unidades_por_presentacion ?? '1',
            'se_cuenta_en'              => $presentacion instanceof ItemPresentacion
                ? $presentacion->unidad->codigo
                : $unidad,
            'entra_en'  => $unidad,
            'pide_lote' => $item?->requiere_lote === true,
        ];
    }

    /**
     * «TAB», «ML»: el código de la unidad del kardex. El código y no el
     * nombre, porque va pegado a un número en un campo angosto.
     */
    private static function codigoDeLaUnidad(Item $item): ?string
    {
        $unidad = $item->unidadDispensacion;

        if (! $unidad instanceof Unidad) {
            return null;
        }

        return $unidad->codigo !== '' ? $unidad->codigo : $unidad->nombre;
    }

    /**
     * «200 TAB» — lo que la línea pone en el kardex, en la columna de al
     * lado de la cantidad. Null si todavía no hay con qué calcularlo.
     */
    private static function loQueEntra(Get $get): ?string
    {
        $cajas = self::comoNumero($get('cantidad_presentacion'));
        $porCaja = self::comoNumero($get('unidades_por_presentacion'));
        $unidad = self::textoONulo($get('entra_en'));

        if ($cajas === null || $porCaja === null || $unidad === null) {
            return null;
        }

        $unidades = Decimal::de($cajas)->por($porCaja);

        if ($unidades->esCero()) {
            return null;
        }

        return self::sinCerosSobrantes($unidades->redondeado(4)).' '.$unidad;
    }

    /**
     * Cuántas líneas van a entrar, para revisar de un vistazo antes de
     * guardar. Las unidades no se suman entre líneas: 200 tabletas y 3
     * frascos de jarabe no son «203» de nada. Cada línea dice lo suyo
     * arriba de su cantidad.
     */
    private static function resumen(Get $get): string
    {
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        if ($lineas === []) {
            return 'Todavía no agregaste nada.';
        }

        $cuantas = count($lineas);

        return $cuantas === 1
            ? '1 línea lista para entrar al inventario.'
            : "{$cuantas} líneas listas para entrar al inventario.";
    }

    private static function textoONulo(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    // ── Ayudantes ─────────────────────────────────────────────────────

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
                $opciones[$item->id.':'.$presentacion->id] = $item->etiqueta().' · '.$presentacion->envase();
            }

            $opciones[$item->id.':0'] = $item->etiqueta().' · '.self::asiLlegaSuelto($item);
        }

        return $opciones;
    }

    /**
     * «SIN ENVASE (por FRASCO)» — la fila de lo que no llega en su caja.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 NO PARECE UNA PRESENTACIÓN, PORQUE NO LO ES
     * ─────────────────────────────────────────────────────────────────
     *
     * Observación de Mauricio (3-sep-2026): «aparece la presentación de
     * 100 ml, pero el otro no es una presentación y aparece ahí».
     *
     * Tenía razón, y el problema era del texto y no de la opción. La fila
     * decía «· suelto, por fco», que ocupa exactamente el mismo lugar del
     * renglón donde va el nombre del envase:
     *
     *   ACETAMINOFEN JARABE · FRASCO 100 ML     ← una presentación
     *   ACETAMINOFEN JARABE · suelto, por fco   ← se lee como otra
     *
     * Así se leía como un envase llamado «suelto, por fco». Ahora ese
     * lugar dice «SIN ENVASE», que no se puede confundir con el nombre de
     * una caja, y entre paréntesis va en qué se cuenta.
     *
     * ⚠️ La opción se queda —Mauricio: «deja eso»—. Es la única forma de
     * cargar un saldo que no es un envase entero: de una caja de 100
     * quedan 20 porque las otras se vendieron antes de que hubiera
     * sistema, y «1 caja» sería mentira y «0.2 cajas» peor.
     *
     * ⚠️ El CÓDIGO de la unidad y no el símbolo: «FRASCO» y no «fco». Es
     * el mismo que se ve en la etiqueta del estante y en el kardex, y en
     * un renglón que ya está en mayúsculas un símbolo en minúscula se lee
     * como otra cosa.
     *
     * ⚠️ Público a propósito, y es lo único público de esta clase además
     * de `configure()`. Lo que hay que poder verificar es el TEXTO —es lo
     * que se lee con el paciente esperando— y la alternativa era una
     * prueba que llega por reflexión a un método privado: eso deja el
     * diseño rehén de la prueba y encima se rompe al renombrarlo.
     */
    public static function asiLlegaSuelto(Item $item): string
    {
        $unidad = $item->unidadDispensacion;

        $como = $unidad instanceof Unidad
            ? ($unidad->codigo !== '' ? $unidad->codigo : $unidad->nombre)
            : 'UNIDAD';

        return 'SIN ENVASE (por '.$como.')';
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
            : self::asiLlegaSuelto($item));
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
