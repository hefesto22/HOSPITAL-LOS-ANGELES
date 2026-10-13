<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\PrincipiosActivos\Schemas\PrincipioActivoForm;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Almacen;
use App\Models\CategoriaItem;
use App\Models\Convenio;
use App\Models\Descuento;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrincipioActivo;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\AsignadorDeCodigoDeItem;
use App\Services\PreciosDeLaFicha;
use App\Services\UbicadorDeProducto;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

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
                    self::dineroYLey($ambito),

                    /*
                     * Ninguno de los dos lados tiene ya pestaña
                     * «Unidades»: en farmacia «se dispensa en» vive en
                     * Identificación, al lado del tipo, y desde el
                     * 13-oct-2026 en servicios también —«se cobra en»—:
                     * un campo solo no merece una pestaña aparte.
                     */
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

    /**
     * ─────────────────────────────────────────────────────────────────
     * LOS MISMOS CAMPOS, ACOMODADOS DISTINTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Cada campo vive en un solo lugar —`campoTipo()`, `campoNombre()`,
     * `campoCategoria()`…— y los dos lados del catálogo los usan. Lo que
     * cambia por ámbito es cómo se acomodan:
     *
     *   · Farmacia, en tres renglones de una grilla de seis
     *     (`identificacionDeProducto()`).
     *   · Servicios, en la misma grilla de seis: un renglón de datos, el
     *     nombre y los precios (`identificacionDeServicio()`).
     */
    private static function identificacion(AmbitoCatalogo $ambito): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-rectangle-stack')
            ->schema($ambito === AmbitoCatalogo::Productos
                ? self::identificacionDeProducto()
                : self::identificacionDeServicio())
            ->columns(6);
    }

    /**
     * La pestaña de farmacia: tres renglones, sin secciones.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 TIENE QUE CABER SIN SCROLL (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Hubo una versión con tres secciones numeradas —«1 · ¿Qué es?»…—,
     * cada una con su título, su descripción y su recuadro. Se leía bien,
     * pero ocho campos pedían tres pantallas de scroll antes de llegar a
     * las presentaciones, que es justo lo que se carga después. Mauricio:
     * «demasiado espacio, hay que hacer mucho scroll».
     *
     * Quedan tres renglones, y debajo las formas de venta:
     *
     *   Tipo                 | Categoría          | Código
     *   Nombre               | Principios activos
     *   Se dispensa en | Código de barras | Nota
     *   Formas de venta: TABLETA suelta · BLÍSTER de 10 · CAJA de 100
     *
     * Las formas de venta viven acá y no en una lista aparte desde el
     * 11-oct-2026: se cargan con el producto, también al crearlo
     * (`FormasDeVenta`).
     *
     * Lo que explicaban las descripciones de sección va en el ⓘ del
     * rótulo de cada campo: se lee al pasar el mouse y no ocupa renglón.
     * Debajo de los campos no va ningún texto de ayuda.
     *
     * El código solo existe en la edición. En el alta la categoría toma
     * su lugar para que el primer renglón no quede con un hueco.
     *
     * @return list<Component>
     */
    private static function identificacionDeProducto(): array
    {
        return [
            self::campoTipo(AmbitoCatalogo::Productos)
                ->columnSpan(3),
            self::campoCategoria(AmbitoCatalogo::Productos)
                ->columnSpan(fn (string $operation): array => [
                    'default' => 1,
                    'lg'      => $operation === 'edit' ? 2 : 3,
                ]),
            self::campoCodigo(AmbitoCatalogo::Productos)
                ->columnSpan(1),

            self::campoNombre(AmbitoCatalogo::Productos)
                ->columnSpan(3),
            self::campoPrincipiosActivos()
                ->columnSpan(3),

            self::campoUnidadDelKardex()
                ->columnSpan(2),
            self::campoCodigoDeBarras()
                ->columnSpan(2),
            self::campoDescripcion(AmbitoCatalogo::Productos)
                ->columnSpan(2),

            ...FormasDeVenta::componentes(),

            self::campoStockInfinito(),
            self::campoDondeHay(),

            self::campoSeAlmacena(AmbitoCatalogo::Productos),
        ];
    }

    /**
     * La pestaña del catálogo de servicios (13-oct-2026).
     *
     * ─────────────────────────────────────────────────────────────────
     * RÁPIDO DE REGISTRAR, Y CON LOS PRECIOS A LA VISTA
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «no debe tener el código de barras, no es necesario si es
     * un servicio; debe ser rápido de registrar y se deben poder editar
     * los precios desde acá».
     *
     *   Tipo        | Categoría        | Se cobra en | Código
     *   Nombre
     *   Precios: lista · PALIG · HOSPITAL MILITAR · …   (un campo cada uno)
     *   Por qué cambian (solo al editar) · Nota
     *
     * Sin la etiqueta de barras: un servicio no está en ningún estante, se
     * busca por nombre o por código. Sin la pestaña «Unidades»: «se cobra
     * en» es un campo, y vive en el primer renglón. Las explicaciones van
     * en el ⓘ de cada rótulo, como en farmacia: debajo de los campos no
     * va ningún párrafo.
     *
     * Los precios se escriben en la misma ficha, al crear y al editar
     * (`PreciosDeLaFicha`): el servicio nace con el precio de cada seguro
     * y no queda un hueco «sin precio para este pagador» que se descubre
     * con el paciente enfrente.
     *
     * @return list<Component>
     */
    private static function identificacionDeServicio(): array
    {
        return [
            self::campoTipo(AmbitoCatalogo::Servicios)
                ->columnSpan(['default' => 1, 'lg' => 2]),
            self::campoCategoria(AmbitoCatalogo::Servicios)
                ->columnSpan(fn (Get $get, string $operation): array => [
                    'default' => 1,
                    'lg'      => self::usaUnidadDeCobro($get) ? 2 : ($operation === 'edit' ? 3 : 4),
                ]),
            self::campoUnidadDeCobro()
                ->columnSpan(fn (string $operation): array => [
                    'default' => 1,
                    'lg'      => $operation === 'edit' ? 1 : 2,
                ]),
            self::campoCodigo(AmbitoCatalogo::Servicios)
                ->columnSpan(1),

            self::campoNombre(AmbitoCatalogo::Servicios),

            self::campoPrecios(),
            self::campoPorQueCambianLosPrecios(),

            self::campoDescripcion(AmbitoCatalogo::Servicios),

            self::campoSeAlmacena(AmbitoCatalogo::Servicios),
        ];
    }

    /**
     * «Se cobra en»: UNIDAD, DÍA, HORA. Un honorario o un paquete no se
     * miden —es uno, o son dos— y el campo ni aparece.
     */
    private static function campoUnidadDeCobro(): Select
    {
        return Select::make('unidad_dispensacion_id')
            ->label('Se cobra en')
            ->options(fn (): array => self::opcionesDeUnidadDeCobro())
            ->searchable()
            ->native(false)
            ->live()
            ->visible(fn (Get $get): bool => self::usaUnidadDeCobro($get))
            ->hintIcon(
                'heroicon-o-information-circle',
                'En qué se cuenta lo que se cobra: una consulta es UNIDAD, una estancia es DÍA, un '
                .'quirófano es HORA. Es lo que se imprime al lado de la cantidad.',
            );
    }

    private static function usaUnidadDeCobro(Get $get): bool
    {
        return self::tipoElegido($get)?->usaUnidadDeCobro() ?? true;
    }

    /**
     * Los precios del servicio, uno por pagador, en la misma ficha
     * (`filament.forms.precios-de-la-ficha`). El estado es «pagador =>
     * lo escrito»; lo guardan `CreateItem` y `EditItem` con
     * `PreciosDeLaFicha`, en la misma transacción que el servicio.
     */
    private static function campoPrecios(): ViewField
    {
        return ViewField::make('precios')
            ->hiddenLabel()
            ->columnSpanFull()
            ->view('filament.forms.precios-de-la-ficha')
            ->afterStateHydrated(function (ViewField $component, ?Item $record): void {
                $component->state(app(PreciosDeLaFicha::class)->estadoInicial($record));
            })
            ->viewData(fn (Get $get, ?Item $record, string $operation): array => self::datosDeLosPrecios($get, $record, $operation));
    }

    /**
     * Lo que pinta la lista de precios: cada pagador con su precio de hoy,
     * y en qué se cobra —«por DÍA, antes del ISV»—, que cambia mientras
     * se llena la ficha.
     *
     * @return array{filas: list<array{clave: string, nombre: string, detalle: string, actual: string|null, pendiente: bool, lista: bool}>, porQue: string, variantes: int}
     */
    private static function datosDeLosPrecios(Get $get, ?Item $record, string $operation): array
    {
        $precios = app(PreciosDeLaFicha::class);
        $vigentes = $precios->vigentes($record);
        $lista = $vigentes[PreciosDeLaFicha::LISTA] ?? null;
        $filas = [];

        foreach ($precios->pagadores() as $pagador) {
            $fila = $vigentes[$pagador['clave']] ?? null;
            $convenio = $pagador['convenio'];
            $esLista = ! $convenio instanceof Convenio;
            $pendiente = $fila instanceof Tarifario && PreciosDeLaFicha::esPendiente($fila);

            $detalle = match (true) {
                $pendiente                                                             => 'Pendiente: el L 10 no es un precio, escriba el de verdad',
                $fila instanceof Tarifario                                             => 'Desde el '.$fila->vigencia_desde->format('d/m/Y'),
                $esLista                                                               => 'Lo paga el particular y todo seguro sin precio propio',
                $lista instanceof Tarifario && ! PreciosDeLaFicha::esPendiente($lista) => 'Sin precio propio: paga el de lista, '.$lista->monto()->formateado(),
                default                                                                => 'Sin precio propio: paga el de lista',
            };

            $filas[] = [
                'clave'   => $pagador['clave'],
                'nombre'  => $convenio instanceof Convenio ? $convenio->nombre : 'Precio de lista',
                'detalle' => $detalle,
                'actual'  => $fila instanceof Tarifario && ! $pendiente
                    ? ItemPresentacion::sinCerosDeMas(Decimal::de($fila->precio)->redondeado(4))
                    : null,
                'pendiente' => $pendiente,
                'lista'     => $esLista,
            ];
        }

        $unidad = is_numeric($get('unidad_dispensacion_id'))
            ? Unidad::query()->find((int) $get('unidad_dispensacion_id'))
            : null;

        $regimen = $get('regimen_isv');
        $regimen = $regimen instanceof RegimenIsv ? $regimen : RegimenIsv::tryFrom(is_string($regimen) ? $regimen : '');

        $porQue = trim(implode(', ', array_filter([
            $unidad instanceof Unidad && self::usaUnidadDeCobro($get) ? 'Por '.$unidad->codigo : null,
            $regimen?->esGravado() ? 'antes del ISV' : 'exento de ISV',
            $operation === 'edit' ? 'el cambio rige desde hoy' : null,
        ])));

        return [
            'filas'     => $filas,
            'porQue'    => $porQue,
            'variantes' => $record instanceof Item && $record->exists
                ? ItemPresentacion::query()->where('item_id', $record->id)->count()
                : 0,
        ];
    }

    /**
     * El porqué de los precios que cambian. Opcional: si queda vacío, el
     * motivo dice que se cambió desde la ficha —y quién lo hizo queda en
     * la fila—. Solo al editar: al crear no hay nada que explicar.
     */
    private static function campoPorQueCambianLosPrecios(): TextInput
    {
        return CampoMayusculas::make('precios_motivo')
            ->label('Por qué cambian los precios (opcional)')
            /*
             * Sin autocompletar: al recargar, el navegador «restauraba» en
             * este campo lo que había en otro —salía «HOS-001», el código—
             * y eso habría quedado escrito como motivo del precio.
             */
            ->autocomplete(false)
            ->maxLength(150)
            ->placeholder('Tarifa nueva firmada con el seguro, ajuste de dirección…')
            ->visibleOn('edit')
            ->columnSpanFull();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL CÓDIGO NO SE PIDE: LO PONE EL SISTEMA
     * ─────────────────────────────────────────────────────────────────
     *
     * Es un correlativo interno del hospital —HOS-013, LAB-0042— y nadie
     * de afuera lo audita. Pedirlo era pedirle a quien carga el catálogo
     * que resuelva algo que el sistema sabe mejor: cuál es el siguiente
     * libre de esa categoría.
     *
     * Se genera al GUARDAR (`CreateItem`), no al elegir la categoría: dos
     * personas cargando a la vez veían el mismo número propuesto y la
     * segunda chocaba contra el índice único al grabar.
     *
     * En edición sí se muestra, y apagado: es lo que la gente se dicta
     * por teléfono —«cargale el HOS-010»— y cambiarlo rompería lo que ya
     * está impreso en cuentas viejas.
     *
     * En farmacia la explicación va en el ⓘ del rótulo y no debajo: el
     * campo ocupa un sexto de la fila y un párrafo abajo lo estiraría.
     */
    private static function campoCodigo(AmbitoCatalogo $ambito): TextInput
    {
        $campo = CampoMayusculas::make('codigo')
            ->label('Código del sistema')
            ->maxLength(30)
            ->hiddenOn('create')
            ->disabledOn('edit')
            /*
             * ⚠️ La regla apunta a `Item` y NO al modelo del Resource.
             * Desde farmacia, `Producto` trae su global scope y solo
             * vería los almacenables: dejaría crear un producto con el
             * código de un servicio, y el índice único de la base lo
             * rechazaría después con un error de SQL en la cara del
             * usuario.
             */
            ->unique(Item::class, 'codigo', ignoreRecord: true);

        $explicacion = 'Lo pone el sistema con el prefijo de la categoría. No se cambia: es el que '
            .'sale impreso en cuentas y etiquetas.';

        return $campo->hintIcon('heroicon-o-information-circle', $explicacion);
    }

    /**
     * El tipo: medicamento, insumo, laboratorio…
     *
     * En farmacia son tres y van como BOTONES: un clic, y se ve de
     * entrada cuál está elegido. En servicios son siete y se quedan en el
     * desplegable, que con siete botones partiría la fila en dos.
     *
     * Solo los tipos de este lado del catálogo. Ofrecer «Medicamento» en
     * la pantalla de servicios es ofrecer un camino que termina en un
     * CHECK de la base rechazando el guardado.
     *
     * Al elegirlo se PROPONEN los campos que se derivan de él —si exige
     * lote, la categoría—. Se proponen: quien carga el catálogo los puede
     * cambiar.
     */
    private static function campoTipo(AmbitoCatalogo $ambito): Select|ToggleButtons
    {
        $alElegir = function (?string $state, string $operation, Get $get, Set $set) use ($ambito): void {
            self::alElegirElTipo($state, $operation, $get, $set, $ambito);
        };

        if ($ambito === AmbitoCatalogo::Productos) {
            return ToggleButtons::make('tipo')
                ->label('Tipo')
                ->options(fn (): array => collect($ambito->tiposPermitidos())
                    ->mapWithKeys(fn (TipoItem $tipo): array => [$tipo->value => $tipo->etiquetaCorta()])
                    ->all())
                ->icons(fn (): array => collect($ambito->tiposPermitidos())
                    ->mapWithKeys(fn (TipoItem $tipo): array => [$tipo->value => $tipo->icono()])
                    ->all())
                /*
                 * `inline()` y no `grouped()`: agrupados no se parten de
                 * renglón, y a 360 px «Insumo o material» empuja el
                 * tercero fuera de la pantalla.
                 */
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated($alElegir);
        }

        return Select::make('tipo')
            ->label('Tipo')
            ->options(fn (): array => $ambito->opcionesDeTipo())
            ->required()
            ->native(false)
            ->live()
            ->afterStateUpdated($alElegir)
            ->hintIcon(
                'heroicon-o-information-circle',
                'Propone cómo paga ISV, en qué se cobra y si su precio se deriva del costo.',
            );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LA HOJA DEL TARIFARIO
     * ─────────────────────────────────────────────────────────────────
     *
     * Obligatoria acá aunque la columna sea nullable en la base: nullable
     * existe solo para poder correr la migración sobre un catálogo ya
     * cargado. Un ítem sin categoría no aparece agrupado en ningún lado y
     * no suma en el reporte de ingresos por área — desaparece sin dar
     * error, que es la peor forma de faltar.
     *
     * Solo las vigentes: una categoría retirada sigue explicando las
     * facturas viejas, pero no debería poder recibir ítems nuevos.
     */
    private static function campoCategoria(AmbitoCatalogo $ambito): Select
    {
        $campo = Select::make('categoria_id')
            ->label('Categoría')
            ->required()
            ->native(false)
            ->searchable()
            ->preload()
            ->live()
            ->options(fn (): array => self::opcionesDeCategoria($ambito))
            /*
             * Elegir la categoría propone el código, porque el prefijo
             * SALE de ella: `MED-0027`, `LAB-0049`.
             *
             * Solo en el alta y solo si lo que hay es vacío o tiene forma
             * de autogenerado. Un `PARAC500` tecleado a mano no se pisa
             * nunca — quien lo escribió tenía una razón, y perderlo de
             * golpe al corregir la categoría es la clase de sorpresa que
             * hace que la gente cargue el catálogo en un Excel aparte.
             */
            ->afterStateUpdated(function (mixed $state, string $operation, Get $get, Set $set): void {
                self::proponerCodigo($state, $operation, $get, $set);
            });

        return $campo->hintIcon(
            'heroicon-o-information-circle',
            $ambito === AmbitoCatalogo::Productos
                ? 'Se propone sola al elegir el tipo.'
                : 'La hoja del tarifario: hospitalización, equipo médico, rayos X, laboratorio, consulta externa.',
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * «SE ALMACENA» YA NO SE PREGUNTA: LO DICE LA PANTALLA
     * ─────────────────────────────────────────────────────────────────
     *
     * Era un interruptor en medio del formulario y de él dependía media
     * ficha. Ahora lo contesta la puerta por la que entrás: si estás en
     * Farmacia, se almacena.
     *
     * Va como Hidden y no como campo omitido porque el resto del
     * formulario lo lee con `$get('se_almacena')` para decidir qué
     * pestañas mostrar, y porque la base lo necesita para el CHECK contra
     * el ámbito de la categoría.
     *
     * Mover un ítem de un lado al otro se hace con la acción del listado,
     * que cambia las tres columnas juntas.
     */
    private static function campoSeAlmacena(AmbitoCatalogo $ambito): Hidden
    {
        return Hidden::make('se_almacena')
            ->default($ambito->seAlmacena());
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * STOCK INFINITO MIENTRAS SE CUADRA EL INVENTARIO (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * En la primera pestaña y no en «Farmacia»: se decide al crear el
     * producto, y ahí es donde se crea.
     *
     * Arranca MARCADO mientras el inventario se cuadra (Mauricio,
     * 13-oct-2026): sale de `sihla.inventario.stock_infinito_al_crear`,
     * que se pasa a false el día que el inventario esté al día. Solo
     * cuenta al crear; en la edición manda lo que ya tiene el producto.
     *
     * Deshabilitado para un controlado, con el porqué a la vista: su
     * libro exige saldo real ante ARSA (§9.F11). El modelo y la base lo
     * vuelven a impedir.
     */
    private static function campoStockInfinito(): Toggle
    {
        return Toggle::make('stock_infinito')
            ->label('Stock infinito mientras se cuadra el inventario')
            ->default(fn (): bool => config('sihla.inventario.stock_infinito_al_crear') === true)
            ->disabled(fn (Get $get): bool => $get('es_controlado') === true)
            ->dehydrated()
            ->live()
            /*
             * Al prenderlo en la ficha, «Dónde hay» arranca en los
             * almacenes donde tiene existencia —«si tenía stock pasa a
             * infinito», y queda donde estaba— o en farmacia si no tiene
             * en ninguno.
             */
            ->afterStateUpdated(function (mixed $state, Set $set, Get $get, ?Item $record): void {
                if ($state === true && blank($get('ubicaciones'))) {
                    $set('ubicaciones', self::dondeHayAhora($record));
                }
            })
            ->helperText(fn (Get $get): string => $get('es_controlado') === true
                ? 'Un estupefaciente o psicotrópico no puede: su libro exige existencia real ante ARSA.'
                : 'Se carga a las cuentas sin llevar existencia: no descuenta nada y queda registrado lo '
                    .'que se usó y de dónde. Mientras esté marcado no se le recibe mercadería. Al marcarlo, '
                    .'lo que había se da de baja; al quitarlo, arranca en cero y ya se le puede recibir.')
            ->columnSpanFull();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * DÓNDE HAY, MIENTRAS ES INFINITO (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «al crear un producto, por defecto va a farmacia, pero se
     * debe poder seleccionar si se crea en farmacia y bodega. Así, al
     * vender, saben de dónde lo sacaron».
     *
     * El infinito no se cuenta, pero sí se sabe dónde está: al cargarlo a
     * una cuenta solo se ofrecen estos almacenes, y el cargo guarda de
     * cuál salió. Después se cambia desde Existencias con «Mover», o acá.
     *
     * No es una columna del producto: lo guarda `UbicadorDeProducto`
     * desde las páginas de alta y de edición.
     */
    private static function campoDondeHay(): CheckboxList
    {
        return CheckboxList::make('ubicaciones')
            ->label('Dónde hay')
            ->options(fn (): array => Almacen::query()
                ->vigentes()
                ->orderBy('nombre')
                ->pluck('nombre', 'id')
                ->all())
            ->default(fn (): array => app(UbicadorDeProducto::class)->porDefecto())
            ->visible(fn (Get $get): bool => $get('stock_infinito') === true && $get('es_controlado') !== true)
            ->required(fn (Get $get): bool => $get('stock_infinito') === true && $get('es_controlado') !== true)
            ->validationMessages(['required' => 'Marque al menos un almacén: si no, nadie sabría de dónde sale.'])
            ->columns(['default' => 2, 'lg' => 4])
            ->helperText('Al cargarlo a una cuenta solo se ofrecen estos almacenes, y queda de cuál se sacó.')
            ->columnSpanFull();
    }

    /**
     * Dónde arranca «Dónde hay» al prender el infinito en la ficha: donde
     * tiene existencia hoy, o farmacia.
     *
     * @return list<int>
     */
    private static function dondeHayAhora(?Item $producto): array
    {
        $donde = $producto instanceof Item && $producto->exists
            ? array_values(Existencia::query()
                ->where('item_id', $producto->id)
                ->conSaldo()
                ->distinct()
                ->pluck('almacen_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            : [];

        return $donde !== [] ? $donde : app(UbicadorDeProducto::class)->porDefecto();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * DÓNDE TERMINA EL NOMBRE Y DÓNDE EMPIEZA LA PRESENTACIÓN
     * ─────────────────────────────────────────────────────────────────
     *
     * La línea no es de estilo, es de inventario:
     *
     *   · Al NOMBRE va lo que cambia qué ES una unidad. Una tableta de
     *     500 mg no es una de 750: cambia la dosis y cambia el precio por
     *     unidad. Son dos productos con dos kardex.
     *   · A la PRESENTACIÓN va lo que solo cambia cuánto viene en el
     *     envase. Un frasco de 60 ml y uno de 120 tienen el mismo jarabe:
     *     1 ml es 1 ml en los dos, y los dos suman al mismo kardex.
     *
     * Poner el tamaño del envase en el nombre parte un producto en tres y
     * reparte su existencia entre los tres — y ninguno cuadra contra el
     * estante.
     *
     * En farmacia esa regla va en el ⓘ del rótulo, y el campo lleva un
     * ejemplo adentro: se lee de un vistazo y desaparece al empezar a
     * escribir.
     */
    private static function campoNombre(AmbitoCatalogo $ambito): TextInput
    {
        $campo = CampoMayusculas::make('nombre')
            ->label('Nombre')
            ->required()
            ->maxLength(255)
            ->columnSpanFull();

        return $ambito === AmbitoCatalogo::Productos
            ? $campo
                ->placeholder('ACETAMINOFEN 500 MG TABLETA')
                ->hintIcon(
                    'heroicon-o-information-circle',
                    'Principio activo, concentración y forma. El tamaño del envase no va acá: la caja '
                    .'de 100 y el frasco de 120 ml se cargan en Presentaciones.',
                )
            : $campo
                ->placeholder('ALIMENTACION POR DIA')
                ->hintIcon('heroicon-o-information-circle', 'Como debe salir impreso en la cuenta del paciente.');
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LO QUE LLEVA ADENTRO, DEL CATÁLOGO
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes era texto libre en la pestaña de Farmacia, y por eso
     * «ACETAMINOFEN», «Acetaminofén» y «acetaminofen » eran tres cosas
     * distintas que no se agrupaban entre sí. Ahora sale del catálogo,
     * así que la etiqueta del estante puede listar todos los productos
     * que lo llevan.
     *
     * Es una LISTA y no un desplegable porque un antigripal lleva
     * acetaminofén + clorfenamina + fenilefrina, y amoxicilina viene con
     * ácido clavulánico. Con uno solo, el segundo queda invisible — y el
     * día que alguien pregunte «¿qué tengo con acetaminofén?» para no
     * duplicar dosis, la respuesta sale incompleta sin que se note.
     *
     * Solo en farmacia: un servicio no lleva principio activo.
     */
    private static function campoPrincipiosActivos(): Select
    {
        return Select::make('principiosActivos')
            ->label('Principios activos')
            ->relationship('principiosActivos', 'nombre')
            ->multiple()
            ->searchable()
            ->preload()
            ->native(false)
            ->columnSpanFull()
            ->getOptionLabelFromRecordUsing(fn (PrincipioActivo $record): string => $record->etiqueta())
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 SI NO EXISTE, SE DA DE ALTA ACÁ MISMO
             * ─────────────────────────────────────────────────────────
             *
             * Antes, cargar un medicamento cuyo principio no estuviera en
             * el catálogo terminaba en un callejón: «No se encontraron
             * coincidencias» y nada que hacer salvo abandonar el producto
             * a medio cargar, ir a Farmacia → Principios activos, darlo
             * de alta y volver a empezar. Nadie hace eso con la carga del
             * vademécum a medias: se deja el campo vacío, y un producto
             * sin principio no aparece cuando alguien escanea la gaveta
             * ni cuando se pregunta qué hay con acetaminofén para no
             * duplicar dosis.
             *
             * El «+» de la derecha abre el MISMO formulario que la
             * pantalla de principios —código correlativo propuesto,
             * sinónimos, ATC—, guarda, y lo deja seleccionado. Queda dado
             * de alta para siempre: la próxima vez ya sale en la lista.
             *
             * Es el formulario completo a propósito y no una versión
             * recortada. Dos formularios que crean la misma fila
             * divergen, y el que queda pobre es el que más se usa.
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
            ->hintIcon(
                'heroicon-o-information-circle',
                'Si no está, tocá el + y queda dado de alta. Entiende sinónimos: «paracetamol» '
                .'encuentra el acetaminofén.',
            );
    }

    /**
     * La nota libre del ítem. Opcional en los dos lados.
     *
     * Se llama «Nota», arranca de un renglón y crece al escribir: es lo
     * que menos se llena, y no tiene que pesar como los campos
     * obligatorios ni empujar la ficha hacia abajo. En servicios, desde
     * el 13-oct-2026, igual que en farmacia.
     */
    private static function campoDescripcion(AmbitoCatalogo $ambito): Textarea
    {
        return CampoMayusculas::area('descripcion')
            ->columnSpanFull()
            ->label('Nota (opcional)')
            ->rows(1)
            ->autosize()
            ->placeholder($ambito === AmbitoCatalogo::Productos
                ? 'En qué se diferencia de otro parecido'
                : 'Qué incluye y qué no: se lee cuando alguien duda al cargar');
    }

    private static function dineroYLey(AmbitoCatalogo $ambito): Tab
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
                /*
                 * ⚠️ EN FARMACIA ESTA SECCIÓN NO EXISTE, y no es cosmético.
                 *
                 * `costo_referencia` significa «esto lo hace alguien de
                 * afuera y me cobra tanto». Un medicamento no se presta:
                 * SE COMPRA, y lo que cuesta sale del kardex como costo
                 * promedio móvil, con su lote, su proveedor y su factura.
                 *
                 * Ofrecer los dos caminos para el mismo número es cómo se
                 * termina con productos que tienen un costo escrito a mano
                 * que nadie actualiza y otro real en el inventario, y con
                 * un reporte de margen que suma los dos.
                 *
                 * Acá se queda —catálogo de servicios— porque ahí sí hay
                 * cosas que el hospital nunca hace: la biopsia que se
                 * manda al laboratorio de afuera no entra a ningún estante
                 * y no tiene kardex del que sacar un costo.
                 */
                Section::make('Servicio prestado por un tercero')
                    ->visible($ambito !== AmbitoCatalogo::Productos)
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
                                    $set('stock_infinito', false);
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
     * El código de barras del producto: el de la caja del fabricante.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UNO POR PRODUCTO, Y LO HEREDAN SUS ENVASES (10-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Regla de Mauricio: el código se pide acá, en la ficha, y vale para
     * todas las presentaciones. El acetaminofén de la marca X y el de la
     * marca Y son dos productos con dos códigos de fábrica distintos, y
     * escanear la caja dice cuál de los dos se tiene en la mano.
     *
     * Heredar es que escanearlo encuentra este producto en cualquiera de
     * sus envases —al cobrar se elige después tableta, blíster o caja—.
     * NO se copia a cada presentación: el mismo código en la caja y en el
     * blíster dejaría a la recepción sin saber si entraron 100 o 10. Una
     * presentación lleva código propio solo si ese envase trae uno
     * distinto.
     *
     * ⚠️ Único contra los productos Y contra los envases, incluidos los
     * dados de baja: un código impreso en una caja es para siempre, y
     * dos lugares con el mismo código hacen que la pistola devuelva el
     * que el ORDER BY quiera.
     */
    private static function campoCodigoDeBarras(): BarcodeInput
    {
        return BarcodeInput::make('codigo_barras')
            ->label('Código de barras')
            ->maxLength(50)
            ->placeholder('Disparale a la caja o escribilo')
            ->dehydrateStateUsing(fn (mixed $state): ?string => is_string($state) && trim($state) !== ''
                ? trim($state)
                : null)
            ->rules([
                fn (?Item $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    self::verificarQueElCodigoEsteLibre($record, $value, $fail);
                },
            ])
            /*
             * La vista de `BarcodeInput` ocupa el sufijo con la cámara, así
             * que las acciones van arriba, en el rótulo.
             */
            ->live(onBlur: true)
            ->hintActions([
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL DEL HOSPITAL SE ASIGNA, Y RECIÉN AHÍ SE IMPRIME
                 * (11-oct-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Regla de Mauricio: «imprimir el del hospital» al lado
                 * de un campo vacío ofrecía imprimir algo que el producto
                 * no tenía. Ahora son dos pasos que se ven:
                 *
                 *   · Campo vacío → «Asignar el del hospital»: le pone
                 *     su código interno —`MED-101`— como código de
                 *     barras. Es para lo que llega sin código de fábrica.
                 *   · Campo con el del hospital → «Imprimir etiquetas».
                 *   · Campo con un código de fábrica → nada: la caja ya
                 *     lo trae impreso.
                 *
                 * Se usa el código interno y no uno inventado aparte
                 * porque ya es único, ya lo encuentra el escáner
                 * (`Item::porCodigo()` mira las dos columnas) y es el que
                 * la gente se dicta por teléfono. Un tercer número no
                 * resolvería nada que estos dos no resuelvan.
                 *
                 * Solo en la edición: en el alta el código interno
                 * todavía no existe —se asigna al guardar—, y guardar ya
                 * lleva a la ficha, donde el botón aparece.
                 */
                Action::make('asignar_el_del_hospital')
                    ->label('Asignar el del hospital')
                    ->tooltip(fn (?Item $record): string => 'Para lo que llega sin código de fábrica: le pone '
                        .'su código interno'.($record instanceof Item ? " ({$record->codigo})" : '')
                        .' para imprimirlo en etiquetas.')
                    ->icon('heroicon-o-tag')
                    ->color('gray')
                    ->visible(fn (?Item $record, mixed $state): bool => $record instanceof Item
                        && $record->exists
                        && self::estaVacio($state))
                    /*
                     * ⚠️ Se vuelve a mirar el campo al ejecutar, no solo
                     * al dibujar el botón: si alguien escribió un código
                     * de fábrica y tocó el botón sin salir del campo, el
                     * botón seguía en pantalla y lo habría pisado.
                     */
                    ->action(function (?Item $record, mixed $state, Set $set): void {
                        if (! $record instanceof Item || ! $record->exists || ! self::estaVacio($state)) {
                            return;
                        }

                        $set('codigo_barras', $record->codigo);

                        /*
                         * El campo cambia en pantalla, pero en la base
                         * todavía no: hasta guardar, la presentación lo
                         * sigue marcando como no escaneable.
                         */
                        Notification::make()
                            ->success()
                            ->title("Se le asignó {$record->codigo}")
                            ->body('Guardá los cambios para que quede. Después ya podés imprimir las etiquetas.')
                            ->send();
                    }),

                Action::make('etiqueta_del_hospital')
                    ->label('Imprimir etiquetas')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (?Item $record, mixed $state): bool => self::tieneElDelHospital($record, $state))
                    ->url(fn (?Item $record): ?string => $record instanceof Item && $record->exists
                        ? route('etiquetas.item', ['item' => $record->getKey(), 'formato' => 'hoja', 'copias' => 30])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->hintIcon(
                'heroicon-o-information-circle',
                'El de la caja del fabricante; cada marca tiene el suyo. Si no trae, se le asigna el del '
                .'hospital. Vale para todas las presentaciones del producto.',
            );
    }

    private static function estaVacio(mixed $state): bool
    {
        return ! is_string($state) || trim($state) === '';
    }

    /**
     * ¿El código de barras del campo es el interno del producto?
     *
     * Se compara sin distinguir mayúsculas: el escáner lo lee igual, y
     * alguien que lo tecleó en minúsculas no tiene otro código.
     */
    private static function tieneElDelHospital(?Item $record, mixed $state): bool
    {
        if (! $record instanceof Item || ! $record->exists || ! is_string($state)) {
            return false;
        }

        $leido = trim($state);

        return $leido !== '' && mb_strtoupper($leido) === mb_strtoupper($record->codigo);
    }

    /**
     * Que el código no lo tenga otro producto ni ningún envase.
     *
     * Con el mensaje de quién lo tiene, para que se pueda ir a corregir
     * sin adivinar. El índice único de la base es el que manda; esto
     * existe para que el choque no llegue como un error de SQL.
     */
    private static function verificarQueElCodigoEsteLibre(?Item $record, mixed $value, Closure $fail): void
    {
        $codigo = is_string($value) ? trim($value) : '';

        if ($codigo === '') {
            return;
        }

        $otroProducto = Item::withTrashed()
            ->where('codigo_barras', $codigo)
            ->when($record instanceof Item, fn (Builder $consulta): Builder => $consulta->whereKeyNot($record?->getKey()))
            ->first();

        if ($otroProducto instanceof Item) {
            $fail("Ese código de barras ya es de «{$otroProducto->nombre}». Cada producto tiene el suyo.");

            return;
        }

        $envase = ItemPresentacion::withTrashed()
            ->with('item')
            ->where('codigo_barras', $codigo)
            ->first();

        if ($envase instanceof ItemPresentacion) {
            $fail(
                'Ese código ya lo tiene la presentación «'.$envase->nombreCompleto().'». Si es el de '
                .'este producto, borralo de la presentación y dejalo acá: lo heredan todos sus envases.'
            );
        }
    }

    /**
     * «Se dispensa en» — la unidad del kardex, en la ficha de farmacia.
     *
     * Vive en Identificación y no en una pestaña propia: es UN campo, y
     * uno que se contesta con el nombre del producto delante. La regla
     * que hay detrás sigue siendo la misma y está en el ⓘ del rótulo —la
     * existencia se lleva SIEMPRE en la unidad mínima de dispensación,
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
            /*
             * `live()` porque las formas de venta se leen con esto: «1
             * CAJA = 100 TAB» y la unidad suelta de la primera línea.
             */
            ->live()
            ->placeholder('TABLETA, ML, AMPOLLA…')
            ->hintIcon(
                'heroicon-o-information-circle',
                'La unidad más chica en que sale al paciente. El inventario se lleva en esta unidad, '
                .'nunca en cajas.',
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
