<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\PrincipiosActivos\Schemas\PrincipioActivoForm;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\CategoriaItem;
use App\Models\Descuento;
use App\Models\Item;
use App\Models\PrincipioActivo;
use App\Models\Unidad;
use App\Services\AsignadorDeCodigoDeItem;
use App\Support\CodigoDeBarras;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Formulario del catálogo — patrón §10.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE, no por
 * tipo: `$get`, `$set`, `$state`, `$record`. Un parámetro con otro nombre
 * recibe un objeto vacío resuelto del contenedor y falla EN SILENCIO —
 * sin excepción y sin log. Ya costó un filtro que no filtraba.
 */
final class ItemForm
{
    /**
     * El catálogo de lo que se OFRECE. Es el que ve `ItemResource`.
     */
    public static function configure(Schema $schema): Schema
    {
        return self::para(AmbitoCatalogo::Servicios, $schema);
    }

    /**
     * Lo que se GUARDA. Es el que ve `ProductoResource`, en farmacia.
     */
    public static function paraProductos(Schema $schema): Schema
    {
        return self::para(AmbitoCatalogo::Productos, $schema);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * UN SOLO FORMULARIO, DOS PUERTAS
     * ─────────────────────────────────────────────────────────────────
     *
     * No se duplica el archivo por pantalla. Las reglas del catálogo
     * —qué ISV admite cada tipo, cuándo hace falta unidad de fracción,
     * qué códigos estándar aplican— son UNA sola cosa, y mantenerlas en
     * dos lugares termina como termina siempre: divergen, y la que
     * queda vieja es la que menos se usa.
     *
     * Lo que cambia por ámbito es qué se pregunta:
     *
     *   · Farmacia no elige «se almacena»: la pantalla ya lo respondió.
     *   · Servicios no ve la pestaña de control sanitario, porque un
     *     hemograma no tiene registro ARSA ni lote.
     *   · El selector de tipo solo ofrece los tipos de su lado, y el de
     *     categoría solo las categorías de su lado.
     */
    public static function para(AmbitoCatalogo $ambito, Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('item')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs(array_values(array_filter([
                    self::identificacion($ambito),
                    self::dineroYLey(),

                    /*
                     * En farmacia la pestaña «Unidades» no existe. Tenía
                     * un solo campo obligatorio —«se dispensa en»— que
                     * además repite lo que ya dice el nombre del producto
                     * («…500 MG TABLETA»), y obligaba a entrar a una
                     * pestaña aparte para contestar algo evidente.
                     *
                     * El campo se mudó a Identificación, al lado del tipo,
                     * y el fraccionamiento a la pestaña Farmacia, junto a
                     * lo demás que solo le pasa a un medicamento.
                     *
                     * En el catálogo de servicios se queda: ahí la unidad
                     * es de COBRO —UNIDAD, DÍA, HORA— y es lo que hace
                     * legible la línea de la cuenta.
                     */
                    $ambito === AmbitoCatalogo::Productos ? null : self::unidades(),
                    $ambito === AmbitoCatalogo::Productos ? self::farmacia() : null,

                    /*
                     * En farmacia esta pestaña no existe: de los tres
                     * códigos estándar, un medicamento solo usa el ATC, y
                     * el ATC pertenece al lado de la ficha donde ya están
                     * el principio activo y el registro ARSA. Una pestaña
                     * entera para un campo —con dos casillas contables
                     * vacías al lado— se lee como algo que falta llenar.
                     *
                     * En el catálogo de servicios sí se queda: ahí viven
                     * CIE-10 (SESAL y aseguradoras) y LOINC (los
                     * analizadores del laboratorio), que no tienen otro
                     * lugar.
                     */
                    $ambito === AmbitoCatalogo::Productos ? null : self::codigosYContabilidad(),
                    self::vigencia(),
                ]))),
        ]);
    }

    private static function identificacion(AmbitoCatalogo $ambito): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-rectangle-stack')
            ->schema([
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL CÓDIGO NO SE PIDE: LO PONE EL SISTEMA
                 * ─────────────────────────────────────────────────────
                 *
                 * Es un correlativo interno del hospital —HOS-013,
                 * LAB-0042— y nadie de afuera lo audita. Pedirlo era
                 * pedirle a quien carga el catálogo que resuelva algo
                 * que el sistema sabe mejor: cuál es el siguiente libre
                 * de esa categoría.
                 *
                 * Se genera al GUARDAR (`CreateItem`), no al elegir la
                 * categoría: dos personas cargando a la vez veían el
                 * mismo número propuesto y la segunda chocaba contra el
                 * índice único al grabar.
                 *
                 * En edición sí se muestra, y apagado: es lo que la
                 * gente se dicta por teléfono —«cargale el HOS-010»— y
                 * cambiarlo rompería lo que ya está impreso en cuentas
                 * viejas.
                 */
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->maxLength(30)
                    ->hiddenOn('create')
                    ->disabledOn('edit')
                    /*
                     * ⚠️ La regla apunta a `Item` y NO al modelo del
                     * Resource. Desde farmacia, `Producto` trae su global
                     * scope y solo vería los almacenables: dejaría crear
                     * un producto con el código de un servicio, y el
                     * índice único de la base lo rechazaría después con
                     * un error de SQL en la cara del usuario.
                     */
                    ->unique(Item::class, 'codigo', ignoreRecord: true)
                    ->helperText('Lo asigna el sistema con el prefijo de la categoría. No se cambia.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EN FARMACIA NO SE DIBUJA NINGÚN CÓDIGO ACÁ
                 * ─────────────────────────────────────────────────────
                 *
                 * «ACETAMINOFEN TABLETA 800 MG» es el nombre de un
                 * medicamento: no es nada que se pueda agarrar con la
                 * mano ni pegarle una etiqueta. Lo que existe en el
                 * estante es la caja de 100 y el blíster de 12 — y el
                 * código de barras va en ELLOS, en la presentación, que
                 * es donde el formulario ahora lo pide y lo genera.
                 *
                 * Los dos trabajos que hacía esta etiqueta tienen cada
                 * uno su lugar propio:
                 *
                 *   · el de la GAVETA es la etiqueta del PRINCIPIO
                 *     ACTIVO — se escanea y salen todos los productos
                 *     que lo llevan, en cualquier forma;
                 *   · el del ENVASE es la de la PRESENTACIÓN.
                 *
                 * En el catálogo de servicios se queda: un hemograma no
                 * viene en ninguna caja, y su código interno es lo único
                 * que hay para identificarlo.
                 *
                 * ⚠️ Las dos condiciones van en UN solo closure. Un
                 * `->visibleOn('edit')` seguido de un `->visible(...)` no
                 * se suman: el segundo pisa al primero sin decir nada, y
                 * la etiqueta volvería a aparecer en el alta mostrando un
                 * código que todavía puede cambiar.
                 */
                Placeholder::make('etiqueta_de_barras')
                    ->label('Código de barras del hospital')
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'edit'
                        && ! self::esFisico($get))
                    ->columnSpanFull()
                    ->content(fn (?Item $record): HtmlString => self::etiqueta($record)),

                Select::make('tipo')
                    ->label('Tipo')
                    /*
                     * Solo los tipos de este lado del catálogo. Ofrecer
                     * «Medicamento» en la pantalla de servicios es
                     * ofrecer un camino que termina en un CHECK de la
                     * base rechazando el guardado.
                     */
                    ->options(fn (): array => $ambito->opcionesDeTipo())
                    ->required()
                    ->native(false)
                    ->live()
                    /*
                     * Al elegir el tipo se proponen los campos que se
                     * derivan de él. Se PROPONEN: quien carga el catálogo
                     * los puede cambiar.
                     */
                    ->afterStateUpdated(function (?string $state, string $operation, Get $get, Set $set) use ($ambito): void {
                        self::alElegirElTipo($state, $operation, $get, $set, $ambito);
                    })
                    ->helperText('Propone cómo paga ISV, qué unidad usa y si su precio se deriva del costo.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * LA HOJA DEL TARIFARIO
                 * ─────────────────────────────────────────────────────
                 *
                 * Obligatoria acá aunque la columna sea nullable en la
                 * base: nullable existe solo para poder correr la
                 * migración sobre un catálogo ya cargado. Un ítem sin
                 * categoría no aparece agrupado en ningún lado y no suma
                 * en el reporte de ingresos por área — desaparece sin
                 * dar error, que es la peor forma de faltar.
                 *
                 * Solo las vigentes: una categoría retirada sigue
                 * explicando las facturas viejas, pero no debería poder
                 * recibir ítems nuevos.
                 */
                Select::make('categoria_id')
                    ->label('Categoría')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->live()
                    ->options(fn (): array => self::opcionesDeCategoria($ambito))
                    /*
                     * Elegir la categoría propone el código, porque el
                     * prefijo SALE de ella: `MED-0027`, `LAB-0049`.
                     *
                     * Solo en el alta y solo si lo que hay es vacío o
                     * tiene forma de autogenerado. Un `PARAC500` tecleado
                     * a mano no se pisa nunca — quien lo escribió tenía
                     * una razón, y perderlo de golpe al corregir la
                     * categoría es la clase de sorpresa que hace que la
                     * gente cargue el catálogo en un Excel aparte.
                     */
                    ->afterStateUpdated(function (mixed $state, string $operation, Get $get, Set $set): void {
                        self::proponerCodigo($state, $operation, $get, $set);
                    })
                    ->helperText($ambito === AmbitoCatalogo::Productos
                        ? 'Dónde se agrupa en farmacia: medicamentos, material de curación, descartables.'
                        : 'La hoja del tarifario: hospitalización, equipo médico, rayos X, laboratorio, consulta externa.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * «SE ALMACENA» YA NO SE PREGUNTA: LO DICE LA PANTALLA
                 * ─────────────────────────────────────────────────────
                 *
                 * Era un interruptor en medio del formulario y de él
                 * dependía media ficha. Ahora lo contesta la puerta por
                 * la que entrás: si estás en Farmacia, se almacena.
                 *
                 * Va como Hidden y no como campo omitido porque el resto
                 * del formulario lo lee con `$get('se_almacena')` para
                 * decidir qué pestañas mostrar, y porque la base lo
                 * necesita para el CHECK contra el ámbito de la
                 * categoría.
                 *
                 * Mover un ítem de un lado al otro se hace con la acción
                 * del listado, que cambia las tres columnas juntas.
                 */
                Hidden::make('se_almacena')
                    ->default($ambito->seAlmacena()),

                ...($ambito === AmbitoCatalogo::Productos ? [self::campoUnidadDelKardex()] : []),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    /*
                     * ─────────────────────────────────────────────────
                     * DÓNDE TERMINA EL NOMBRE Y DÓNDE EMPIEZA LA
                     * PRESENTACIÓN
                     * ─────────────────────────────────────────────────
                     *
                     * La línea no es de estilo, es de inventario:
                     *
                     *   · Al NOMBRE va lo que cambia qué ES una unidad.
                     *     Una tableta de 500 mg no es una de 750: cambia
                     *     la dosis y cambia el precio por unidad. Son dos
                     *     productos con dos kardex.
                     *   · A la PRESENTACIÓN va lo que solo cambia cuánto
                     *     viene en el envase. Un frasco de 60 ml y uno de
                     *     120 tienen el mismo jarabe: 1 ml es 1 ml en los
                     *     dos, y los dos suman al mismo kardex.
                     *
                     * Poner el tamaño del envase en el nombre parte un
                     * producto en tres y reparte su existencia entre los
                     * tres — y ninguno cuadra contra el estante.
                     */
                    ->helperText($ambito === AmbitoCatalogo::Productos
                        ? 'El principio activo, la forma y la concentración: ACETAMINOFEN JARABE, '
                        .'ACETAMINOFEN 500 MG TABLETA. El TAMAÑO del envase NO va acá — 60 ml, 120 ml '
                        .'y la caja de 100 son presentaciones del mismo producto.'
                        : 'Como debe salir impreso en la cuenta del paciente.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 LO QUE LLEVA ADENTRO, DEL CATÁLOGO
                 * ─────────────────────────────────────────────────────
                 *
                 * Antes era texto libre en la pestaña de Farmacia, y por
                 * eso «ACETAMINOFEN», «Acetaminofén» y «acetaminofen »
                 * eran tres cosas distintas que no se agrupaban entre
                 * sí. Ahora sale del catálogo, así que la etiqueta del
                 * estante puede listar todos los productos que lo llevan.
                 *
                 * Es una LISTA y no un desplegable porque un antigripal
                 * lleva acetaminofén + clorfenamina + fenilefrina, y
                 * amoxicilina viene con ácido clavulánico. Con uno solo,
                 * el segundo queda invisible — y el día que alguien
                 * pregunte «¿qué tengo con acetaminofén?» para no
                 * duplicar dosis, la respuesta sale incompleta sin que se
                 * note.
                 */
                Select::make('principiosActivos')
                    ->label('Principios activos')
                    ->relationship('principiosActivos', 'nombre')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::esFisico($get))
                    ->getOptionLabelFromRecordUsing(fn (PrincipioActivo $record): string => $record->etiqueta())
                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 SI NO EXISTE, SE DA DE ALTA ACÁ MISMO
                     * ─────────────────────────────────────────────────
                     *
                     * Antes, cargar un medicamento cuyo principio no
                     * estuviera en el catálogo terminaba en un callejón:
                     * «No se encontraron coincidencias» y nada que hacer
                     * salvo abandonar el producto a medio cargar, ir a
                     * Farmacia → Principios activos, darlo de alta y
                     * volver a empezar. Nadie hace eso con la carga del
                     * vademécum a medias: se deja el campo vacío, y un
                     * producto sin principio no aparece cuando alguien
                     * escanea la gaveta ni cuando se pregunta qué hay
                     * con acetaminofén para no duplicar dosis.
                     *
                     * El «+» de la derecha abre el MISMO formulario que
                     * la pantalla de principios —código correlativo
                     * propuesto, sinónimos, ATC—, guarda, y lo deja
                     * seleccionado. Queda dado de alta para siempre: la
                     * próxima vez ya sale en la lista.
                     *
                     * Es el formulario completo a propósito y no una
                     * versión recortada. Dos formularios que crean la
                     * misma fila divergen, y el que queda pobre es el que
                     * más se usa.
                     */
                    ->createOptionModalHeading('Nuevo principio activo')
                    ->createOptionForm(fn (Schema $schema): Schema => PrincipioActivoForm::configure($schema))
                    ->createOptionAction(fn (Action $action): Action => $action
                        ->modalWidth('xl')
                        ->modalDescription(
                            'Se guarda en el catálogo del hospital, no solo en este producto: '
                            .'la próxima vez ya aparece en la lista.'
                        ))
                    ->noSearchResultsMessage(
                        'Todavía no está en el catálogo. Tocá el + de la derecha para darlo de alta '
                        .'sin salir de acá.'
                    )
                    ->helperText(
                        'Lo que de verdad cura. Si no está, se agrega con el + y queda dado de alta '
                        .'para siempre. La búsqueda entiende sinónimos: escribir «paracetamol» '
                        .'encuentra el acetaminofén.'
                    ),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Opcional. Para aclarar qué incluye y qué no — se lee cuando alguien duda al cargar.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL PRECIO SE PIDE ACÁ SOLO PARA LO QUE NO SE
                 * ALMACENA
                 * ─────────────────────────────────────────────────────
                 *
                 * La regla que abrió este campo sigue en pie y es esta:
                 * un ítem sin precio existe, se puede buscar y se puede
                 * elegir en una cuenta — y recién ahí, con el paciente
                 * enfrente, aparece «este ítem no tiene precio para este
                 * pagador». El hueco no lo abre el que se olvida: lo abre
                 * un formulario que deja terminar sin preguntarlo.
                 *
                 * Por eso en el catálogo de SERVICIOS el campo sigue
                 * siendo obligatorio: una cesárea no se «recibe» nunca, y
                 * si nadie le pone precio acá no se lo pone nadie.
                 *
                 * ─────────────────────────────────────────────────────
                 * 🔴 EN FARMACIA NO, Y NO ES POR COMODIDAD
                 * ─────────────────────────────────────────────────────
                 *
                 * Un producto no tiene UN precio: tiene uno por
                 * presentación. El frasco de 60 ml y el de 120 no valen
                 * lo mismo ni por ml ni por frasco, y el precio de cada
                 * uno se calcula al RECIBIR la compra, del costo por el
                 * margen objetivo.
                 *
                 * Lo que escribía este campo era una fila de precio SIN
                 * presentación — la de respaldo, la que se borró en la
                 * migración `quitar_los_precios_de_respaldo_de_mas`. Y
                 * esa fila no es inofensiva: el resolvedor cae en ella en
                 * silencio cuando no encuentra la del envase, y cobra el
                 * número equivocado sin avisar. L 45.83 en vez de L 91.67
                 * el ml, y nadie se entera.
                 *
                 * El hueco que queda —un producto cargado y todavía sin
                 * precio— lo cubre el contador de «sin precio» de Bases
                 * de precios, que es donde se ve de un vistazo y donde se
                 * arregla. No es un descuido: es el mismo control, movido
                 * al lugar donde el precio de verdad se decide.
                 *
                 * ⚠️ Las dos condiciones van en UN solo closure, por lo
                 * mismo que la etiqueta de barras de arriba: un
                 * `->hiddenOn('edit')` seguido de un `->visible(...)` no
                 * se suman, el segundo pisa al primero sin decir nada.
                 */
                TextInput::make('precio_de_lista')
                    ->label('Precio de lista')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->prefix('L')
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                        && ! self::esFisico($get))
                    ->columnSpanFull()
                    ->required()
                    ->helperText(
                        'Es el precio del hospital, el que se le cobra al paciente particular. '
                        .'Los seguros se cargan después desde Bases de precios.'
                    ),
            ])
            ->columns(2);
    }

    private static function dineroYLey(): Tab
    {
        return Tab::make('ISV y descuentos')
            ->icon('heroicon-o-scale')
            ->schema([
                Section::make('Impuesto sobre ventas')
                    ->description(
                        'El ISV se determina POR LÍNEA, nunca por factura: una misma cuenta mezcla '
                        .'hospitalización exenta con una liposucción gravada y con la cafetería. '
                        .'La mayor parte de este negocio es exenta por el Art. 15 de la Ley del ISV.'
                    )
                    ->schema([
                        Select::make('regimen_isv')
                            ->label('Régimen de ISV')
                            ->options(fn (): array => collect(RegimenIsv::cases())
                                ->mapWithKeys(fn (RegimenIsv $r): array => [$r->value => $r->etiqueta()])
                                ->all())
                            ->required()
                            ->default(RegimenIsv::Exento->value)
                            ->native(false)
                            ->helperText(
                                'Medicamentos, material de curación, hospitalización, laboratorio e '
                                .'imagen son EXENTOS. Tratamiento de belleza estética, cafetería y '
                                .'parqueo van gravados.'
                            ),
                    ]),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 LO QUE COBRA EL DE AFUERA
                 * ─────────────────────────────────────────────────────
                 *
                 * Hay exámenes que el hospital no hace: toma la muestra y
                 * la manda. Al paciente se le cobra el precio del
                 * hospital, pero de ese precio la mayor parte se va al
                 * otro laboratorio.
                 *
                 * Sin este número, el reporte de laboratorio suma lo que
                 * se hace adentro —donde todo queda— con lo que se manda
                 * afuera —donde queda la diferencia—, y dirección lee una
                 * utilidad que no existe.
                 *
                 * Va vacío en todo lo que se hace adentro. Vacío
                 * significa «no aplica»; cero significa «me lo hacen
                 * gratis», que pasa de verdad en algunos convenios y no
                 * es lo mismo.
                 */
                Section::make('Servicio prestado por un tercero')
                    ->icon('heroicon-o-building-storefront')
                    ->description(
                        'Solo para lo que el hospital NO hace y manda afuera: laboratorio externo, '
                        .'imagen referida. Lo que se le cobra al paciente sigue siendo el precio de '
                        .'la lista; esto es lo que hay que pagarle al que lo hace.'
                    )
                    ->schema([
                        TextInput::make('costo_referencia')
                            ->label('Lo que cobra el tercero')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.0001')
                            ->prefix('L')
                            ->placeholder('Vacío — este servicio se hace en el hospital')
                            ->helperText(
                                'Dejalo vacío si el examen se hace acá. La diferencia contra el '
                                .'precio de lista es lo único que gana el hospital por intermediar.'
                            ),
                    ]),

                /*
                 * ─────────────────────────────────────────────────────
                 * LOS DESCUENTOS CON NOMBRE, QUE SE ELIGEN
                 * ─────────────────────────────────────────────────────
                 *
                 * Acá no se escribe ningún porcentaje: se marcan los que
                 * ya existen en «Descuentos». Es a propósito.
                 *
                 * 🔴 Antes esta pantalla tenía un campo para teclear el
                 * porcentaje de la tercera edad, y era una trampa: el
                 * porcentaje es de la CATEGORÍA, así que escribir 30 %
                 * desde una radiografía se lo cambiaba también a las
                 * otras cuarenta radiografías y a la hospitalización.
                 * Un campo que puede reescribir la ley no puede estar
                 * en la ficha de un producto.
                 *
                 * Marcar una casilla, en cambio, solo afecta a este
                 * ítem. El porcentaje se cambia en un solo lugar y con
                 * fecha, y desde ahí le llega a todos los que lo tengan
                 * marcado.
                 */
                Section::make('Descuentos del hospital')
                    /*
                     * Desaparece con la pantalla que los crea: un
                     * selector que solo puede quedar vacío enseña a
                     * ignorar la pestaña entera, y esta pestaña además
                     * lleva el régimen de ISV.
                     */
                    ->visible(fn (): bool => (bool) config('sihla.inventario.usa_descuentos_propios', false))
                    ->description(
                        'Los que se crean en «Descuentos», con su nombre y su porcentaje. Marcá los '
                        .'que apliquen a este ítem. Lo del Artículo 30 se aplica solo aunque no '
                        .'marques nada: esto suma, nunca resta.'
                    )
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Select::make('descuentos')
                            ->label('Descuentos que aplican')
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->columnSpanFull()
                            ->placeholder('Ninguno: solo lo que manda el Artículo 30')
                            /*
                             * 🔴 El primer parámetro se llama `$query` y
                             * no se puede llamar de otra forma: Filament
                             * resuelve los argumentos POR NOMBRE. Con
                             * otro nombre llega un Builder vacío del
                             * contenedor y el selector ofrece TODAS las
                             * filas, incluidas las vencidas — sin
                             * excepción y sin log.
                             */
                            ->relationship(
                                name: 'descuentos',
                                titleAttribute: 'nombre',
                                modifyQueryUsing: function (Builder $query, ?Item $record): void {
                                    self::descuentosQueSePuedenMarcar($query, $record);
                                },
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Descuento $record): string => self::etiquetaDelDescuento($record),
                            )
                            ->helperText(
                                'Se ofrecen los vigentes hoy. Cuando a uno le cambien el porcentaje '
                                .'no hay que volver a marcarlo: el ítem queda pegado al nombre, no '
                                .'al número que tiene hoy.'
                            ),

                        Placeholder::make('aviso_de_descuentos')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (): HtmlString => self::avisoDeDescuentos()),
                    ]),
            ]);
    }

    /**
     * Los que se ofrecen para marcar: los vigentes hoy, MÁS los que este
     * ítem ya tenga marcados.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 POR QUÉ EL «MÁS LOS QUE YA TENÍA»
     * ─────────────────────────────────────────────────────────────────
     *
     * Filament usa esta misma consulta para dos cosas: llenar la lista
     * de opciones Y buscar la etiqueta de lo que ya está seleccionado.
     * Si solo devolviera los vigentes, un ítem marcado con una fila
     * vencida —cosa normal: el pivote apunta a la fila que estaba
     * vigente el día que alguien la marcó— se quedaría sin etiqueta, y
     * un valor sin etiqueta desaparece del campo. Guardar después lo
     * borraría del pivote **sin decir nada**.
     *
     * Que la fila esté vencida no significa que el ítem haya dejado de
     * tener el descuento: el motor de cargos lo resuelve por nombre y le
     * da la fila vigente. Perder la marca sí se lo quitaría de verdad.
     *
     * Vive en un método propio y no dentro del closure porque el closure
     * recibe un `Builder` sin genérico, y encadenarle un scope del modelo
     * ahí adentro es lo que PHPStan no puede verificar.
     *
     * @param Builder<Descuento> $query
     */
    private static function descuentosQueSePuedenMarcar(Builder $query, ?Item $record): void
    {
        $yaMarcados = $record instanceof Item && $record->exists
            ? $record->descuentos()->pluck('descuentos.id')->all()
            : [];

        $query->where(function (Builder $sub) use ($yaMarcados): void {
            $sub->vigentesEn(now());

            if ($yaMarcados !== []) {
                $sub->orWhereIn('descuentos.id', $yaMarcados);
            }
        });
    }

    /**
     * «Tercera edad — 25 % · Pacientes de la tercera edad (60–79 años)»,
     * y con el aviso adelante si la fila ya venció.
     */
    private static function etiquetaDelDescuento(Descuento $descuento): string
    {
        $etiqueta = $descuento->etiquetaCompleta().' · '.$descuento->aplica_a->etiquetaConTramo();

        return $descuento->vigenteEn(now())
            ? $etiqueta
            : '⚠️ '.$etiqueta.' (esta versión venció el '
                .$descuento->vigencia_hasta?->format('d/m/Y').'; se aplica la vigente)';
    }

    private static function avisoDeDescuentos(): HtmlString
    {
        if (Descuento::query()->vigentesEn(now())->exists()) {
            return new HtmlString(
                '<span class="text-sm text-gray-500">Los porcentajes se cambian en '
                .'<strong>Descuentos</strong>, no acá: cambiarlos ahí les llega a todos los ítems '
                .'que los tengan marcados, y queda el historial de qué regía cada día.</span>'
            );
        }

        return new HtmlString(
            '<span class="text-sm text-warning-600">⚠️ Todavía no hay ningún descuento creado. '
            .'Se crean en <strong>Catálogos y precios → Descuentos</strong>, con nombre y '
            .'porcentaje. Mientras tanto, a este ítem solo se le aplica lo del Artículo 30.</span>'
        );
    }

    private static function unidades(): Tab
    {
        return Tab::make('Unidades')
            ->icon('heroicon-o-beaker')
            /*
             * Un honorario no se mide: es uno, o son dos. La pestaña
             * entera desaparece en vez de mostrar un campo opcional que
             * nadie puede contestar.
             *
             * Lo que se almacena la ve siempre, sea del tipo que sea: la
             * unidad del kardex es obligatoria y sin ella no se puede
             * costear ni descontar.
             */
            ->visible(fn (Get $get): bool => self::esFisico($get)
                || (self::tipoElegido($get)?->usaUnidadDeCobro() ?? true))
            ->schema([
                Section::make(fn (Get $get): string => self::esFisico($get)
                    ? 'Unidad del kardex'
                    : 'Unidad de cobro')
                    ->description(fn (Get $get): string => self::esFisico($get)
                        ? 'La existencia se lleva SIEMPRE en la unidad mínima en la que se dispensa. '
                        .'Llevarla en unidad de compra obliga a fracciones en cada salida y hace '
                        .'imposible cuadrar. Cuántas caben en una caja lo dice cada presentación.'
                        : 'En qué se cuenta lo que se cobra: una consulta es UNIDAD, una estancia es '
                        .'DÍA, un quirófano es HORA. No lleva kardex, pero la unidad es lo que hace '
                        .'legible la línea de la cuenta.')
                    ->schema([
                        Select::make('unidad_dispensacion_id')
                            ->label(fn (Get $get): string => self::esFisico($get) ? 'Se dispensa en' : 'Se cobra en')
                            ->options(fn (Get $get): array => self::esFisico($get)
                                ? self::opcionesDeUnidad()
                                : self::opcionesDeUnidadDeCobro())
                            ->searchable()
                            ->native(false)
                            ->required(fn (Get $get): bool => self::esFisico($get))
                            ->helperText(fn (Get $get): string => self::esFisico($get)
                                ? 'Obligatorio: sin esto no se puede costear ni descontar del kardex.'
                                : 'Opcional, pero es lo que se imprime al lado de la cantidad.'),
                    ]),
            ]);
    }

    private static function farmacia(): Tab
    {
        return Tab::make('Farmacia')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (Get $get): bool => self::esFisico($get))
            ->schema([
                Section::make('Control sanitario')
                    ->schema([
                        Toggle::make('requiere_lote')
                            ->label('Exige lote y vencimiento')
                            ->helperText('Obligatorio en medicamentos por trazabilidad ante ARSA.'),

                        Toggle::make('es_controlado')
                            ->label('Estupefaciente o psicotrópico')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                /*
                                 * Un controlado sin receta es una
                                 * infracción ante ARSA. La base lo
                                 * rechaza y el modelo lo corrige; acá se
                                 * ahorra el viaje y se ve en pantalla.
                                 */
                                if ($state === true) {
                                    $set('requiere_receta', true);
                                }
                            })
                            ->helperText('Activa libro con saldo corrido y reporte mensual a ARSA.'),

                        Toggle::make('requiere_receta')
                            ->label('Exige receta para dispensar')
                            ->disabled(fn (Get $get): bool => $get('es_controlado') === true)
                            ->dehydrated()
                            ->helperText('Un controlado siempre la exige y no se puede desmarcar.'),
                    ])
                    ->columns(3),

                Section::make('Identificación del producto')
                    ->schema([
                        CampoMayusculas::make('presentacion_comercial')
                            ->label('Presentación comercial')
                            ->maxLength(255)
                            ->helperText('Como aparece en la caja: "TABLETA 500 MG", "SOLUCIÓN INYECTABLE 2 ML".'),

                        TextInput::make('registro_arsa')
                            ->label('Registro sanitario ARSA')
                            ->maxLength(50),

                        /*
                         * El ATC vive acá y no en una pestaña aparte: es
                         * la clasificación del MEDICAMENTO, hermana del
                         * principio activo. Es lo que agrupa las tres
                         * formas del acetaminofén cuando alguien pregunta
                         * qué hay para la fiebre.
                         */
                        TextInput::make('codigo_atc')
                            ->label('Código ATC')
                            ->maxLength(10)
                            ->helperText('Clasificación internacional del medicamento. El acetaminofén es N02BE01.'),
                    ])
                    ->columns(2),

                Section::make('Fraccionamiento')
                    ->visible(fn (Get $get): bool => self::esFisico($get))
                    ->description(
                        'Un frasco de nebulización se puede fraccionar; una ampolla no. Para un ítem '
                        .'fraccionable, quien dispensa elige entre cobrar la dosis aplicada o el envase '
                        .'completo — y el sobrante que se descarta sale del kardex como merma, no como venta.'
                    )
                    ->schema([
                        Toggle::make('fraccionable')
                            ->label('Se puede fraccionar')
                            ->live()
                            ->columnSpanFull(),

                        Select::make('unidad_fraccion_id')
                            ->label('Se fracciona en')
                            ->options(fn (): array => self::opcionesDeUnidad())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->required(fn (Get $get): bool => $get('fraccionable') === true),

                        TextInput::make('fracciones_por_unidad')
                            ->label('Fracciones por unidad')
                            ->numeric()
                            ->minValue(0.0001)
                            ->step(0.0001)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->required(fn (Get $get): bool => $get('fraccionable') === true)
                            ->helperText('Una ampolla de 2 ml lleva 2.'),

                        TextInput::make('horas_caducidad_post_apertura')
                            ->label('Horas de vida una vez abierto')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->placeholder('Usar el valor por defecto de la instalación')
                            ->helperText(
                                'Muchos multidosis vencen a las 24-48 h de abiertos, sin importar la '
                                .'fecha impresa en el frasco.'
                            ),
                    ])
                    ->columns(3),

                Section::make('Contabilidad')
                    ->description(
                        'Se mapea desde el día uno. Hacerlo dos años después es un proyecto de meses '
                        .'sobre millones de filas de cargo.'
                    )
                    ->icon('heroicon-o-calculator')
                    ->collapsed()
                    ->schema([
                        TextInput::make('cuenta_contable')
                            ->label('Cuenta contable')
                            ->maxLength(30),

                        TextInput::make('centro_de_costo')
                            ->label('Centro de costo')
                            ->maxLength(30),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * SE ESCONDE AL CREAR CUANDO NO APLICA NINGÚN CÓDIGO
     * ─────────────────────────────────────────────────────────────────
     *
     * Al dar de alta un honorario, ni CIE-10 ni LOINC ni ATC significan
     * nada, y la cuenta contable se mapea después con el contador
     * delante. Lo urgente al crear es que el ítem se pueda cobrar.
     *
     * Al EDITAR sí aparece siempre: el mapeo contable hay que poder
     * hacerlo, y esconderlo para siempre sería cambiar «no lo pido
     * ahora» por «no existe».
     */
    private static function codigosYContabilidad(): Tab
    {
        return Tab::make('Códigos y contabilidad')
            ->icon('heroicon-o-hashtag')
            ->visible(fn (Get $get, ?Item $record): bool => $record instanceof Item
                || self::tipoElegido($get)?->usaAlgunCodigoEstandar() === true)
            ->schema([
                Section::make('Códigos estándar')
                    ->description(
                        'Opcionales y nunca llave: el ítem se identifica por su código interno. Estos '
                        .'sirven para hablar con afuera — CIE-10 con SESAL y las aseguradoras, LOINC con '
                        .'los analizadores, ATC para clasificar el medicamento.'
                    )
                    ->visible(fn (Get $get): bool => self::tipoElegido($get)?->usaAlgunCodigoEstandar() === true)
                    ->schema([
                        TextInput::make('codigo_cie10')
                            ->label('CIE-10')
                            ->maxLength(10)
                            ->placeholder('J18.9')
                            ->visible(fn (Get $get): bool => self::tipoElegido($get)?->usaCie10() === true),

                        TextInput::make('codigo_loinc')
                            ->label('LOINC')
                            ->maxLength(20)
                            ->placeholder('718-7')
                            ->visible(fn (Get $get): bool => self::tipoElegido($get)?->usaLoinc() === true),

                        TextInput::make('codigo_atc')
                            ->label('ATC')
                            ->maxLength(10)
                            ->placeholder('N02BE01')
                            ->visible(fn (Get $get): bool => self::tipoElegido($get)?->usaAtc() === true),

                        TextInput::make('version_codificacion')
                            ->label('Versión de la codificación')
                            ->maxLength(20)
                            ->placeholder('CIE-10 2019')
                            ->helperText('Migrar a CIE-11 tiene que ser cambio de datos, no de esquema.'),
                    ])
                    ->columns(4),

                Section::make('Contabilidad')
                    ->description(
                        'Se mapea desde el día uno. Hacerlo dos años después es un proyecto de meses '
                        .'sobre millones de filas de cargo.'
                    )
                    ->schema([
                        TextInput::make('cuenta_contable')
                            ->label('Cuenta contable')
                            ->maxLength(30),

                        TextInput::make('centro_de_costo')
                            ->label('Centro de costo')
                            ->maxLength(30),
                    ])
                    ->columns(2),
            ]);
    }

    private static function vigencia(): Tab
    {
        return Tab::make('Vigencia')
            ->icon('heroicon-o-calendar-days')
            ->schema([
                Section::make('Desde cuándo y hasta cuándo se ofrece')
                    ->description(
                        'El catálogo tiene vigencia, no un botón de activo. Un servicio que deja de '
                        .'ofrecerse sigue teniendo que explicar la factura donde aparece.'
                    )
                    ->schema([
                        DatePicker::make('vigencia_desde')
                            ->label('Vigente desde')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        DatePicker::make('vigencia_hasta')
                            ->label('Vigente hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('vigencia_desde')
                            ->helperText('Dejar vacío mientras se siga ofreciendo.'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creado por')
                            ->placeholder('Sistema'),

                        TextEntry::make('updated_at')
                            ->label('Última modificación')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    /**
     * El código dibujado, con los dos enlaces para mandarlo a imprimir.
     *
     * Devuelve `HtmlString` porque el SVG se inserta tal cual. No hay
     * dato del usuario sin escapar acá adentro: el código pasa por
     * `CodigoDeBarras::codificable()`, que solo deja ASCII imprimible, y
     * el nombre va por `e()`.
     */
    private static function etiqueta(?Item $record): HtmlString
    {
        if (! $record instanceof Item) {
            return new HtmlString('');
        }

        $svg = CodigoDeBarras::svg($record->codigo, modulo: 2, alto: 50);

        if ($svg === '') {
            return new HtmlString(
                '<p class="text-sm text-gray-500">Este código no se puede imprimir en barras: '
                .'tiene caracteres fuera del ASCII imprimible.</p>'
            );
        }

        $una = route('etiquetas.item', ['item' => $record->getKey(), 'formato' => 'media']);
        $hoja = route('etiquetas.item', [
            'item'    => $record->getKey(),
            'formato' => 'hoja',
            'copias'  => 30,
        ]);

        /*
         * ⚠️ Estilos EN LÍNEA y no clases de Tailwind. Este HTML se
         * inyecta como `HtmlString` y nunca pasa por el compilador de
         * CSS, así que las clases no existen en la hoja final: el
         * `flex gap-4` no separaba nada y los dos enlaces salían pegados
         * —«Imprimir unaImprimir hoja de 30»—.
         */
        $enlace = 'style="text-decoration:underline;font-size:.875rem;font-weight:500" '
            .'target="_blank" rel="noopener"';

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:.75rem;align-items:flex-start">'
            .'<div style="display:inline-block;background:#fff;color:#000;padding:.75rem;border-radius:.5rem">'
            .$svg
            .'</div>'
            .'<div style="display:flex;gap:1.5rem">'
            .'<a href="'.e($una).'" '.$enlace.'>Etiqueta grande (media A4)</a>'
            .'<a href="'.e($hoja).'" '.$enlace.'>Hoja de 30 chicas</a>'
            .'</div>'
            .'</div>'
        );
    }

    /**
     * Elegir el tipo llena lo que se deduce de él.
     *
     * ─────────────────────────────────────────────────────────────────
     * PROPONE TRES COSAS Y NO IMPONE NINGUNA
     * ─────────────────────────────────────────────────────────────────
     *
     *   1. Si exige lote — un medicamento sí, una gasa no.
     *   2. La categoría, si todavía está vacía. Un medicamento va a
     *      MEDICAMENTOS y un estudio de laboratorio a LABORATORIO: que
     *      haya que elegirlo dos veces es un trámite, no una decisión.
     *   3. Y al escribir la categoría, el código — porque `$set()` NO
     *      dispara el `afterStateUpdated` del campo que escribe. Sin esta
     *      llamada explícita la categoría aparecería llena y el código
     *      vacío, y nadie entendería por qué a veces se propone y a
     *      veces no.
     *
     * ⚠️ La categoría solo se escribe si está VACÍA: alguien que ya la
     * eligió a mano y después corrige el tipo no pierde su elección.
     */
    private static function alElegirElTipo(
        ?string $state,
        string $operation,
        Get $get,
        Set $set,
        AmbitoCatalogo $ambito,
    ): void {
        if ($state === null) {
            return;
        }

        $tipo = TipoItem::tryFrom($state);

        if (! $tipo instanceof TipoItem) {
            return;
        }

        $set('requiere_lote', $tipo->requiereLote());

        if ($get('categoria_id') !== null && $get('categoria_id') !== '') {
            return;
        }

        $categoria = self::categoriaSugeridaPara($tipo, $ambito);

        if (! $categoria instanceof CategoriaItem) {
            return;
        }

        $set('categoria_id', $categoria->getKey());
        self::proponerCodigo($categoria->getKey(), $operation, $get, $set);
    }

    /**
     * La categoría que el mapa de configuración propone para este tipo.
     *
     * Devuelve null cuando el tipo no está en el mapa, cuando el código
     * configurado no existe, o cuando la categoría es del otro lado del
     * catálogo. Los tres casos significan lo mismo para quien carga: no
     * se propone nada y elige.
     */
    private static function categoriaSugeridaPara(TipoItem $tipo, AmbitoCatalogo $ambito): ?CategoriaItem
    {
        $mapa = config('sihla.inventario.categoria_por_tipo', []);

        if (! is_array($mapa)) {
            return null;
        }

        $codigo = $mapa[$tipo->value] ?? null;

        if (! is_string($codigo) || $codigo === '') {
            return null;
        }

        return CategoriaItem::query()
            ->delAmbito($ambito)
            ->vigentesEn(now())
            ->where('codigo', $codigo)
            ->first();
    }

    /**
     * Escribe el código sugerido cuando cambia la categoría.
     *
     * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE:
     * `$state`, `$operation`, `$get` y `$set` se llaman así porque así
     * los inyecta. Con otro nombre llega un objeto vacío del contenedor
     * y esto deja de hacer nada, sin error y sin log.
     */
    private static function proponerCodigo(mixed $state, string $operation, Get $get, Set $set): void
    {
        if ($operation !== 'create' || ! is_numeric($state)) {
            return;
        }

        $categoria = CategoriaItem::query()->find((int) $state);

        if (! $categoria instanceof CategoriaItem) {
            return;
        }

        $asignador = app(AsignadorDeCodigoDeItem::class);
        $actual = $get('codigo');

        if (is_string($actual) && $actual !== '' && ! $asignador->pareceAutogenerado($actual)) {
            return;
        }

        $set('codigo', $asignador->siguiente($categoria));
    }

    /**
     * «Se dispensa en» — la unidad del kardex, en la ficha de farmacia.
     *
     * Vive en Identificación y no en una pestaña propia: es UN campo, y
     * uno que se contesta con el nombre del producto delante. La regla
     * que hay detrás sigue siendo la misma y está en el texto de ayuda —
     * la existencia se lleva SIEMPRE en la unidad mínima de dispensación,
     * nunca en cajas—, porque de eso depende que el inventario cuadre.
     *
     * Obligatorio: el CHECK `items_unidad_obligatoria_si_se_almacena` lo
     * exige, y sin unidad no se puede costear ni descontar.
     */
    private static function campoUnidadDelKardex(): Select
    {
        return Select::make('unidad_dispensacion_id')
            ->label('Se dispensa en')
            ->options(fn (): array => self::opcionesDeUnidad())
            ->searchable()
            ->native(false)
            ->required()
            ->helperText(
                'La unidad mínima en la que sale al paciente: TABLETA, ML, AMPOLLA. El inventario se '
                .'lleva en esta unidad, nunca en cajas — cuántas trae la caja lo dice cada presentación.'
            );
    }

    /**
     * Las categorías vivas de un lado del catálogo, en el orden en que
     * están impresas en el tarifario y no en orden alfabético: es el
     * orden que el personal ya conoce.
     *
     * @return array<int, string>
     */
    private static function opcionesDeCategoria(AmbitoCatalogo $ambito): array
    {
        return CategoriaItem::query()
            ->delAmbito($ambito)
            ->vigentesEn(now())
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (CategoriaItem $c): array => [$c->getKey() => $c->nombre])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function opcionesDeUnidad(): array
    {
        return Unidad::query()
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
            ->all();
    }

    /**
     * En qué se cobra algo que NO se almacena.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN VENDAJE NO SE COBRA EN UNIDADES INTERNACIONALES
     * ─────────────────────────────────────────────────────────────────
     *
     * El desplegable era el mismo que el de farmacia, así que al crear un
     * procedimiento ofrecía UNIDAD INTERNACIONAL, MILILITRO y MILIGRAMO.
     * UI es potencia de fármaco —lo que lleva la insulina y las
     * vitaminas—; cobrar un bendaje en UI no significa nada. Y estaba a
     * un clic, mientras la descripción del campo prometía otra cosa:
     * «una consulta es UNIDAD, una estancia es DÍA, un quirófano es
     * HORA».
     *
     * Un campo que ofrece respuestas sin sentido no es neutral: enseña
     * que la lista no hay que leerla.
     *
     * Quedan conteo y tiempo. Se van masa, volumen y longitud, que miden
     * lo que hay ADENTRO de un frasco y no lo que se hace.
     *
     * ⚠️ Se siguen colando TABLETA y AMPOLLA, que también son de conteo.
     * Son inofensivas —se imprimen al lado de la cantidad y se ven— y hoy
     * nada en `unidades` distingue «UNIDAD» de «TABLETA». El día que
     * estorbe, la respuesta es una bandera `sirve_para_cobro` en la
     * tabla, NO una lista de códigos escrita acá: la pantalla de Unidades
     * de medida deja crear unidades nuevas, y una SESIÓN dada de alta
     * mañana tiene que aparecer sola.
     *
     * @return array<int, string>
     */
    private static function opcionesDeUnidadDeCobro(): array
    {
        return Unidad::query()
            ->whereIn('magnitud', [
                MagnitudDeMedida::Conteo->value,
                MagnitudDeMedida::Tiempo->value,
            ])
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
            ->all();
    }

    /**
     * ¿Este ítem se almacena?
     *
     * Se lee del FORMULARIO y no del modelo: en el alta todavía no hay
     * modelo, y en la edición el usuario puede estar cambiando la
     * respuesta justo ahora.
     *
     * ⚠️ Mientras el interruptor no se haya tocado, `$get` devuelve nulo
     * —no falso—, y ahí se cae al tipo, que es lo que proponía antes de
     * que la columna existiera. Tratar el nulo como «no se almacena»
     * escondería la unidad de dispensación de un medicamento recién
     * empezado a cargar.
     */
    /**
     * El tipo que el formulario tiene elegido ahora mismo.
     *
     * Se lee del formulario y no del modelo por la misma razón que
     * `esFisico()`: en el alta no hay modelo, y en la edición el usuario
     * puede estar cambiándolo justo ahora.
     */
    private static function tipoElegido(Get $get): ?TipoItem
    {
        $valor = $get('tipo');

        return is_string($valor) ? TipoItem::tryFrom($valor) : null;
    }

    private static function esFisico(Get $get): bool
    {
        $almacena = $get('se_almacena');

        if (is_bool($almacena)) {
            return $almacena;
        }

        $tipo = $get('tipo');

        return is_string($tipo)
            ? (TipoItem::tryFrom($tipo)?->mueveInventario() ?? false)
            : false;
    }
}
