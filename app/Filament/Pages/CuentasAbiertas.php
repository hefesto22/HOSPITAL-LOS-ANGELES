<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\MotivoDeAnulacionDeCuenta;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RubroDeDescuento;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoDiagnostico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\CuentaException;
use App\Domain\Exceptions\DiagnosticoException;
use App\Domain\Exceptions\EncuentroException;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\Exceptions\FacturaException;
use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\MedioDePago;
use App\Domain\ValueObjects\Monto;
use App\Filament\Concerns\OperaElTurnoDeCaja;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Abono;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Cie10;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Diagnostico;
use App\Models\Especialidad;
use App\Models\Existencia;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Medico;
use App\Models\Persona;
use App\Models\Prestamo;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Models\PrincipioActivo;
use App\Models\TurnoDeCaja;
use App\Models\Unidad;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\AnuladorDeCargo;
use App\Services\AnuladorDeCuenta;
use App\Services\AplicadorDeDescuentoDeLaCuenta;
use App\Services\ConsultorDeExistencias;
use App\Services\EmisorDeFactura;
use App\Services\FormasDeCobro;
use App\Services\LecturaEnEnvases;
use App\Services\PoliticaDeDescuentoComercial;
use App\Services\ReceptorDeAbono;
use App\Services\RegistradorDeAutorizacion;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeDiagnostico;
use App\Services\RegistradorDePrestamo;
use App\Services\ResolutorDeHonorario;
use App\Services\ResolutorDePrecio;
use App\Services\UbicadorDeProducto;
use App\Support\AlmacenesDelUsuario;
use App\Support\CatalogoDelRol;
use App\Support\FichaDelPaciente;
use App\Support\NumeroDeFormulario;
use App\Support\TextoCanonico;
use App\Support\UsuarioAutenticado;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;
use Throwable;

/**
 * Las cuentas abiertas del hospital, en tarjetas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES UN RESOURCE (§9.A10 🔴)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cargarle cosas a la cuenta de un paciente es un flujo con estado, no
 * un formulario: hay que ver de quién es la cuenta, escanear, confirmar
 * el precio y seguir. Un CRUD genérico permite guardar estados
 * imposibles y cuesta cinco clics donde tiene que costar uno.
 *
 * Filament brilla en catálogos, tarifarios y conciliación. Esta pantalla
 * no es eso: es la que se usa a las tres de la mañana, con la pistola en
 * una mano.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TARJETAS Y NO TABLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una tabla de veinte columnas obliga a leer para encontrar al paciente.
 * La tarjeta pone lo único que importa a un metro de distancia: **de
 * quién es, desde cuándo está y cuánto lleva.** El resto está adentro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL MODAL NO SE CIERRA DESPUÉS DE CADA ÍTEM
 * ─────────────────────────────────────────────────────────────────────
 *
 * `replaceMountedAction` lo vuelve a montar con los mismos argumentos:
 * el ítem entra, la lista de abajo se actualiza, el campo de escaneo
 * queda vacío y con el foco. Cerrar y reabrir por cada ampolla serían
 * tres clics de más por línea, y una ronda de medicamentos son veinte
 * líneas.
 */
class CuentasAbiertas extends Page
{
    /*
     * El turno se abre donde se cobra: mandar a la cajera a otra
     * pantalla antes de poder recibir el primer abono es una vuelta que
     * en el mostrador se traduce en «el sistema no me deja cobrar».
     */
    use OperaElTurnoDeCaja;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $slug = 'cuentas';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.cuentas-abiertas';

    /**
     * Las claves de «¿Cómo se le agrega?». Son las de `FormasDeCobro`,
     * que es quien decide qué significa cada una; acá solo se nombran
     * más corto porque viajan entre el formulario, la conversión y la
     * vista.
     */
    private const POR_UNIDAD = FormasDeCobro::SUELTA;

    private const POR_FRACCION = FormasDeCobro::FRACCION;

    private const POR_PRESENTACION = FormasDeCobro::ENVASE;

    /**
     * Las dos formas en que un seguro dice lo que pone, en el modal de
     * factura: un porcentaje de la cuenta (PALIG) o un monto aprobado (el
     * Hospital Militar).
     */
    private const AUTORIZA_UN_PORCENTAJE = 'porcentaje';

    private const AUTORIZA_UN_MONTO = 'monto';

    /**
     * Lo que se teclea en el buscador. Sin `live()` con debounce corto:
     * cada tecla sería una consulta sobre pacientes (§13.2).
     */
    public string $busqueda = '';

    /**
     * El token que distingue «lo cargué otra vez» de «apreté dos veces».
     * Vive en el estado de Livewire, así que un reenvío de la misma
     * acción trae el mismo valor y el servicio devuelve el cargo que ya
     * existía en vez de duplicarlo.
     */
    public string $claveDeEnvio = '';

    /**
     * Memoria por pintada de «¿este paciente ya tiene cuenta viva?».
     *
     * @var array<int, Cuenta|null>
     */
    private array $cuentasVivas = [];

    /**
     * Memoria por pintada de la ficha del paciente del modal «Abrir
     * cuenta». El modal se vuelve a pintar con cada campo que se toca, y
     * la ficha la piden el rótulo del buscador, la visibilidad y la vista:
     * sin memoria serían tres lecturas del mismo paciente por tecla.
     *
     * @var array<int, array{iniciales: string, nombre: string, nn: bool, datos: list<string>}|null>
     */
    private array $fichasDelPaciente = [];

    /**
     * Lo mismo para sus expedientes: los piden la vista, la validación y
     * el que marca el único.
     *
     * @var array<int, array<int, string>>
     */
    private array $expedientesPorPersona = [];

    /**
     * Los pagadores del modal, una vez por pintada: los piden la lista y
     * la visibilidad de la póliza y de la autorización.
     *
     * @var Collection<int, Convenio>|null
     */
    private ?Collection $pagadoresDelModal = null;

    /**
     * Cuántas líneas van cargadas en esta tanda.
     *
     * No se muestra en ningún lado y no es un dato del negocio: es lo que
     * hace que el contexto de la acción cambie en cada ítem para que
     * Filament no cierre el modal. El porqué está en `cargarEnCuenta`.
     */
    public int $renglonDeLaTanda = 0;

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL DESCUENTO DE LA TANDA
     * ─────────────────────────────────────────────────────────────────
     *
     * Lo que se puso arriba en «Descuento sobre medicamentos» sobrevive a
     * cada «Agregar». Vive en el componente y no en los argumentos de la
     * acción porque el modal se vuelve a montar después de cada
     * «Agregar», y un dato que hay que acordarse de pasar en cada
     * remontaje se pierde el día que alguien lo olvide.
     *
     * 🔴 `cuentaDelDescuento` es lo que impide que se cruce de paciente:
     * la propiedad sobrevive a que el modal se cierre, así que sin ella
     * el 30 % autorizado a uno aparecería puesto al abrir la cuenta del
     * siguiente — y nadie lo mira dos veces cuando el campo ya está
     * lleno.
     */
    public ?int $cuentaDelDescuento = null;

    public ?string $descuentoDeLaTanda = null;

    public ?string $motivoDeLaTanda = null;

    /*
     * Los descuentos en servicios (11-oct-2026) y en honorarios
     * (12-oct-2026): los hermanos del de farmacia, en porcentaje o en
     * lempiras. Viven en la cuenta; esto es solo lo que muestra la
     * sección. Ver `RubroDeDescuento`.
     */
    public ?string $descuentoServicios = null;

    public string $modoServicios = 'porcentaje';

    public ?string $motivoServicios = null;

    public ?string $descuentoHonorarios = null;

    public string $modoHonorarios = 'porcentaje';

    public ?string $motivoHonorarios = null;

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL PRINCIPIO ACTIVO QUE SE ESCANEÓ, MIENTRAS DURE ESTA LÍNEA
     * ─────────────────────────────────────────────────────────────────
     *
     * Escanear la etiqueta de la gaveta no dice qué cobrar: dice de qué
     * MOLÉCULA se trata. El acetaminofén está en tableta, en jarabe y en
     * supositorio, y cuál se le dio al paciente lo sabe quien lo dio.
     *
     * Así que el escaneo no elige: acota. Mientras esto tenga un valor,
     * debajo del buscador aparecen solo los productos que lo llevan.
     *
     * ⚠️ Se limpia en `fillForm`, que corre al abrir el modal Y en cada
     * remontaje después de agregar una línea. Un filtro que sobrevive a
     * la línea siguiente es un filtro invisible, y un filtro invisible es
     * una lista corta que nadie entiende por qué está corta.
     */
    public ?int $principioEscaneado = null;

    /**
     * De qué cuenta es el formulario de cargo que está abierto.
     *
     * Existe para una sola cosa: proponer el precio de un honorario, que
     * depende del pagador de esa cuenta. Se llena en `fillForm`, que es
     * el único lugar donde llegan los argumentos de la acción.
     */
    public ?int $cuentaDelFormulario = null;

    /**
     * De qué envase se leyó el código, mientras dure esta línea.
     *
     * El código de barras vive en la presentación, así que pasar la
     * pistola por una caja de 100 dice QUÉ producto es y DE DÓNDE se
     * saca, pero no cómo se vende: eso lo elige quien cobra en «¿Cómo se
     * le agrega?». Si elige la tableta suelta, la caja queda en el cargo
     * como origen —que es el dato que el 3-sep-2026 pidió no perder—.
     *
     * ⚠️ Se limpia en cada escaneo y en `fillForm`, por lo mismo que el
     * principio activo: un origen que sobrevive a la línea siguiente le
     * pega la caja de un producto al cargo de otro.
     */
    public ?int $presentacionEscaneada = null;

    /**
     * Lo que el buscador tiene que decir debajo del campo, cuando lo
     * escrito no llevó a nada: «no hay nada con…», «esa etiqueta de
     * gaveta no existe».
     *
     * Va debajo del campo y no en una notificación: el campo busca
     * mientras se escribe, y una notificación por cada pausa del teclado
     * es una pantalla que grita mientras alguien todavía está tecleando.
     */
    public ?string $avisoDelBuscador = null;

    /**
     * Memoria por pintada de lo que encontró el buscador.
     *
     * @var array<string, array<int, string>>
     */
    private array $resultadosDelBuscador = [];

    /**
     * Memoria por pintada de los ítems leídos.
     *
     * 🔴 Cada campo del formulario pregunta «¿qué ítem es?» para decidir
     * si se muestra —la cantidad, el estante, el precio del honorario, el
     * médico, la forma, el resumen—, y cada pregunta era un `find()`. Son
     * diez consultas iguales por pintada, en el modal que se repinta con
     * cada pausa del teclado (§13.2).
     *
     * @var array<int, Item|null>
     */
    private array $itemsPorId = [];

    /**
     * Memoria por pintada de los estantes de cada ítem, con cuánto hay.
     *
     * 🔴 El campo «¿de dónde sale?» pregunta lo mismo TRES veces por
     * render —las opciones, el marcador de posición y el texto de
     * ayuda—, y cada pregunta son dos consultas. Sin esta memoria son
     * seis consultas por pintada, y el modal se repinta con cada tecla
     * del buscador: el N+1 invisible del §13.2, en la pantalla más
     * caliente del sistema.
     *
     * `private` y no `public`: Livewire no la serializa, así que dura lo
     * que dura la petición — que es exactamente lo que tiene que durar.
     *
     * La clave es «ítem:presentación», porque cuántos frascos hay
     * depende del envase que se esté cobrando.
     *
     * @var array<string, Collection<int, array{almacen: Almacen, hay: Decimal}>>
     */
    private array $estantesPorItem = [];

    /**
     * Memoria por pintada de los almacenes donde está cada producto con
     * stock infinito, y qué envases hay en cada uno (`UbicadorDeProducto`:
     * nulo es «de todo»). Por la misma razón que la de arriba: se pregunta
     * en cada pintada del modal.
     *
     * @var array<int, array<int, list<int>|null>>
     */
    private array $lugaresPorItem = [];

    /**
     * Memoria por pintada de las presentaciones de cada ítem.
     *
     * @var array<int, ColeccionDeModelos<int, ItemPresentacion>>
     */
    private array $presentacionesPorItem = [];

    /**
     * Memoria por pintada del precio que el tarifario propone para cada
     * honorario, en la cuenta que está abierta.
     *
     * @var array<int, string|null>
     */
    private array $precioPropuestoPorItem = [];

    /**
     * Memoria por pintada de los médicos ya leídos.
     *
     * @var array<int, Medico|null>
     */
    private array $medicosPorId = [];

    /**
     * La cuenta del formulario, leída una sola vez por pintada.
     *
     * El pagador manda en el precio del honorario, así que se pregunta
     * en cada tecla; sin esto son dos consultas por pulsación.
     */
    private ?Cuenta $cuentaLeida = null;

    /**
     * La línea cuya ✕ está esperando que le escriban el porqué.
     *
     * Nula casi siempre: solo se llena cuando la línea es de otro turno o
     * ya no es de recién, que es cuando quitarla dejó de ser corregir un
     * tecleo. El porqué completo está en `Cargo::pideMotivoParaQuitar()`.
     */
    public ?int $cargoAQuitar = null;

    /**
     * El renglón del paquete cuya cantidad se está preguntando, y cuánto.
     *
     * ⚠️ Estado de Livewire, no una acción de Filament: `mountAction()`
     * desde adentro de una acción montada no abre nada y no da error.
     */
    public ?int $lineaAEntregar = null;

    public string $cantidadAEntregar = '';

    /**
     * Si el desglose del paquete está desplegado.
     *
     * ⚠️ Vive en Livewire y NO en el `<details>` del navegador: cada
     * entrega vuelve a renderizar la lista, y un `<details>` sin estado
     * se cierra solo. Quien está despachando cinco renglones seguidos
     * tenía que reabrirlo cinco veces.
     */
    public bool $paqueteAbierto = false;

    public string $motivoDeQuitar = '';

    public static function getNavigationLabel(): string
    {
        return 'Cuentas abiertas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    public function getTitle(): string
    {
        return 'Cuentas abiertas';
    }

    /**
     * Debajo del título, los números de la pantalla y no una frase fija
     * (Mauricio, 22-oct-2026): cuántas hay abiertas, cuánto llevan
     * cargado sin facturar y cuántas no tienen nada.
     *
     * Las vacías van aparte porque casi siempre son un error de apertura
     * o una que alguien olvidó: se ven desde arriba sin recorrer
     * tarjetas. Es UNA consulta con `FILTER`, sobre las mismas cuentas
     * vivas que la grilla y sin el buscador: es el estado del hospital,
     * no el de la búsqueda.
     *
     * Sin cuentas no dice nada: el recuadro vacío de abajo ya lo explica.
     */
    public function getSubheading(): ?string
    {
        $numeros = $this->numerosDeLaPantalla();

        if ($numeros['abiertas'] === 0) {
            return null;
        }

        $partes = [
            $numeros['abiertas'] === 1 ? '1 abierta' : "{$numeros['abiertas']} abiertas",
            Monto::de($numeros['cargado'])->formateado().' sin facturar',
        ];

        if ($numeros['vacias'] > 0) {
            $partes[] = $numeros['vacias'] === 1 ? '1 vacía' : "{$numeros['vacias']} vacías";
        }

        return implode(' · ', $partes);
    }

    /**
     * @return array{abiertas: int, cargado: string, vacias: int}
     */
    public function numerosDeLaPantalla(): array
    {
        $fila = Cuenta::query()
            ->vivas()
            ->selectRaw('count(*) as abiertas')
            ->selectRaw('coalesce(sum(total), 0) as cargado')
            ->selectRaw('count(*) filter (where lineas = 0) as vacias')
            ->toBase()
            ->first();

        $abiertas = data_get($fila, 'abiertas');
        $cargado = data_get($fila, 'cargado');
        $vacias = data_get($fila, 'vacias');

        return [
            'abiertas' => is_numeric($abiertas) ? (int) $abiertas : 0,
            'cargado'  => is_numeric($cargado) ? (string) $cargado : '0',
            'vacias'   => is_numeric($vacias) ? (int) $vacias : 0,
        ];
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Cuenta::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->claveDeEnvio = (string) Str::uuid();
    }

    // ── Las tarjetas ──────────────────────────────────────────────────

    /**
     * @return Collection<int, Cuenta>
     */
    public function cuentas(): Collection
    {
        return $this->lasQueCoinciden()
            /*
             * Columnas nombradas y no `with('encuentro.persona')` a
             * secas: la tarjeta necesita seis campos y traer la persona
             * entera son cuarenta columnas por fila (§12).
             *
             * ⚠️ La contracara: lo que la tarjeta lee y no está en esta
             * lista llega NULO, sin error. Así se cayó /cuentas el
             * 10-oct-2026 —`base_descuento_legal` faltaba y la sigla
             * 3R/4R le preguntaba al convenio si aplica el Art. 30— y
             * así decía «(edad estimada)» de toda fecha exacta, porque
             * faltaba `precision_fecha_nacimiento`. Columna que se lea
             * en `resumenDe()`, columna que se agrega acá.
             */
            ->with([
                'encuentro:id,numero,tipo,estado,abierto_en,persona_id,expediente_id,servicio_id',
                'encuentro.persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,apellido_casada,fecha_nacimiento,precision_fecha_nacimiento,sexo_biologico,es_nn',
                'encuentro.expediente:id,numero',
                'encuentro.servicio:id,nombre',
                'convenio:id,codigo,nombre,tipo,base_descuento_legal',
            ])
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL ORDEN TIENE QUE SER ESTABLE, NO SOLO CORRECTO
             * ─────────────────────────────────────────────────────────
             *
             * Con `abierta_en` solo, las cuentas abiertas en el mismo
             * minuto —las tres que deja un comando de demo, o las cuatro
             * del ingreso de la mañana— quedan empatadas, y ante un
             * empate PostgreSQL devuelve las filas en el orden que le
             * convenga. Puede ser otro después de cargarle algo a una.
             *
             * Eso se ve como tarjetas que saltan de lugar mientras
             * alguien trabaja: se va a tocar la de Pedro y aparece la de
             * Marta donde estaba. Con el paciente enfrente, ese salto es
             * un cargo en la cuenta equivocada.
             *
             * El id desempata y no se repite nunca: 40, 39, 38, siempre
             * en el mismo lugar aunque se les agregue o se les quite.
             */
            ->orderByDesc('abierta_en')
            ->orderByDesc('id')
            ->limit($this->tarjetasPorPantalla())
            ->get();
    }

    /**
     * Cuántas cuentas coinciden de verdad, para avisar cuando la grilla
     * no las muestra todas (Mauricio, 22-oct-2026).
     *
     * La grilla corta en `sihla.facturacion.tarjetas_por_pantalla`, y la
     * 25.ª cuenta desaparecía sin que nadie lo notara: el paciente
     * seguía internado y su cuenta no estaba en la pantalla de cuentas
     * abiertas. Solo cuenta cuando la grilla llegó al tope; si mostró
     * menos, ya son todas y no hace falta preguntarle a la base.
     */
    public function cuantasCoinciden(int $mostradas): int
    {
        if ($mostradas < $this->tarjetasPorPantalla()) {
            return $mostradas;
        }

        return $this->lasQueCoinciden()->count();
    }

    /**
     * Las cuentas vivas que coinciden con el buscador: lo que comparten
     * la grilla y el aviso de «se muestran 24 de 31».
     *
     * @return Builder<Cuenta>
     */
    private function lasQueCoinciden(): Builder
    {
        $termino = trim($this->busqueda);

        return Cuenta::query()
            ->vivas()
            /*
             * ⚠️ Todo el buscador va DENTRO de un solo `where` agrupado.
             *
             * Con un `orWhere` de primer nivel, el SQL queda
             * `(estado IN (...) AND EXISTS(...)) OR numero ILIKE ...` y
             * buscar por número de cuenta traería también cuentas
             * cerradas y anuladas a la pantalla de cuentas ABIERTAS.
             */
            /*
             * ⚠️ La búsqueda por nombre entra como SUBCONSULTA y no como
             * `whereHas('persona', fn ($p) => $p->buscarPorNombre(...))`.
             *
             * Dentro de un `whereHas`, el builder que llega es genérico
             * —`Builder<Model>`— y el análisis estático no puede saber
             * que ahí vive el scope de `Persona`. Construir la subconsulta
             * desde `Persona::query()` lo deja tipado, y de paso deja a la
             * vista que la búsqueda tolerante a errores de tecleo
             * (trigramas) corre contra su propio índice.
             *
             * Todas las columnas van calificadas: hay más de una tabla en
             * juego y `numero` existe en tres de ellas.
             */
            ->when($termino !== '', fn ($consulta) => $consulta->where(
                fn ($grupo) => $grupo
                    ->where('cuentas.numero', 'ilike', '%'.$termino.'%')
                    ->orWhereHas(
                        'encuentro',
                        fn ($e) => $e->where('encuentros.numero', 'ilike', '%'.$termino.'%')
                            ->orWhereIn(
                                'encuentros.persona_id',
                                Persona::query()->buscarPorNombre($termino)->select('personas.id'),
                            )
                            ->orWhereIn(
                                'encuentros.expediente_id',
                                Expediente::query()
                                    ->where('expedientes.numero', 'ilike', '%'.$termino.'%')
                                    ->select('expedientes.id'),
                            )
                    )
            ));
    }

    private function tarjetasPorPantalla(): int
    {
        return max(1, (int) config('sihla.facturacion.tarjetas_por_pantalla', 24));
    }

    public function hayCuentas(): bool
    {
        return $this->cuentas()->isNotEmpty();
    }

    /**
     * ¿Quien mira puede cargarle cosas a las cuentas?
     *
     * Lo usa la vista para decidir si la tarjeta es un botón o una ficha
     * de lectura. Auditoría entra a esta pantalla —la necesita— pero no
     * asienta nada.
     */
    /**
     * ¿Este usuario puede escribir el diagnóstico?
     *
     * Solo el médico —y el super admin, que es quien prueba el sistema—.
     * Ver `DiagnosticoPolicy`: está atado al ROL y no a un permiso de
     * Shield a propósito, porque diagnosticar no es una pantalla que
     * dirección reparte, es un acto médico.
     *
     * Esto solo apaga el botón. Quien de verdad protege es el
     * `abort_unless` dentro de la acción: `mountAction` se puede disparar
     * desde el cliente sin que exista ningún botón.
     */
    public function puedeDiagnosticar(): bool
    {
        return Gate::allows('create', Diagnostico::class);
    }

    /**
     * Cuántos diagnósticos vigentes lleva cada tarjeta, por encuentro.
     *
     * Va en la tarjeta como un contador rojo cuando hay alguno, y NO
     * cuando no hay: un cero en cada tarjeta se vuelve invisible en dos
     * días. Lo que se quiere ver de un vistazo es cuáles ya tienen y
     * cuáles siguen sin diagnóstico — que son las que no se le pueden
     * cobrar a una aseguradora.
     *
     * UNA consulta para toda la grilla (22-oct-2026). Antes era un
     * `count()` por tarjeta, y la vista lo llamaba dos veces —para decidir
     * si había y para escribirlo—: 48 consultas de más en una pantalla de
     * 24 cuentas (§13.2). Y solo para quien ve el botón: a nadie más le
     * sirve el número.
     *
     * @param Collection<int, Cuenta> $cuentas
     *
     * @return array<int, int> encuentro_id => cuántos
     */
    public function diagnosticosPorEncuentro(Collection $cuentas): array
    {
        if ($cuentas->isEmpty() || ! $this->puedeDiagnosticar()) {
            return [];
        }

        $filas = Diagnostico::query()
            ->whereIn('encuentro_id', $cuentas->pluck('encuentro_id')->all())
            ->vigentes()
            ->groupBy('encuentro_id')
            ->selectRaw('encuentro_id, count(*) as cuantos')
            ->toBase()
            ->get();

        $cuantos = [];

        foreach ($filas as $fila) {
            $encuentro = data_get($fila, 'encuentro_id');
            $total = data_get($fila, 'cuantos');

            if (is_numeric($encuentro) && is_numeric($total)) {
                $cuantos[(int) $encuentro] = (int) $total;
            }
        }

        return $cuantos;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * QUÉ BOTONES LLEVA LA TARJETA (Mauricio, 22-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «Ya que aún no se usará lo de diagnósticos ni tratamientos, no hay
     * necesidad de que lo vean los cajeros». La regla que sale de ahí:
     *
     *   · sin el PERMISO, el botón no está. A la cajera no le sirve ver
     *     «Diagnóstico» y «Tratamiento» apagados en cada tarjeta, ni a
     *     laboratorio «Abonar» y «Facturar»: nunca van a poder usarlos.
     *   · con el permiso pero sin poder AHORA —la gaveta cerrada, la
     *     cuenta vacía—, el botón está apagado y dice por qué. Eso sí
     *     tiene arreglo, y la persona tiene que saber cuál.
     *
     * Se pregunta el permiso `Create:Factura` y no la policy: la policy
     * pide además el turno abierto, y ese caso es el del botón apagado.
     *
     * @return array{abonar: bool, facturar: bool, clinicos: bool}
     */
    public function botonesDeLaTarjeta(): array
    {
        $usuario = Auth::user();

        return [
            'abonar'   => Gate::allows('create', Abono::class),
            'facturar' => $usuario instanceof User && $usuario->can('Create:Factura'),
            'clinicos' => $this->puedeDiagnosticar(),
        ];
    }

    /**
     * Por qué no se factura ESTA cuenta, o `null` si se puede.
     *
     * Lo de quien mira —sin turno, gaveta de otro turno— pesa primero y
     * ya viene calculado una vez para toda la grilla. Después, la cuenta:
     * una cuenta sin nada cargado no tiene qué facturar
     * (`FacturaException::noHayNadaQueFacturar`), y el botón encendido
     * solo llevaba a ese error con el modal ya abierto.
     */
    public function porQueNoFacturaLa(Cuenta $cuenta, ?string $deQuienMira): ?string
    {
        if ($deQuienMira !== null) {
            return $deQuienMira;
        }

        return $cuenta->lineas === 0 ? 'No tiene nada cargado: no hay qué facturar.' : null;
    }

    public function puedeCargar(): bool
    {
        return Gate::allows('create', Cargo::class);
    }

    /**
     * Por qué el botón «Abonar» está apagado, o `null` si se puede.
     *
     * Abonar es de caja (y dirección), y en el turno abierto de la gaveta
     * cuando quien mira trabaja en él (Mauricio, 13-oct-2026): la cajera
     * de otro turno, o sin turno asignado, carga en la cuenta pero no
     * recibe plata. Antes el botón se veía encendido y el «no» llegaba
     * recién al guardar, con el formulario ya lleno.
     *
     * Laboratorio y consulta externa ven la tarjeta entera pero el botón
     * apagado. La regla de verdad la pone `ReceptorDeAbono`.
     */
    public function porQueNoAbona(): ?string
    {
        if (! Gate::allows('create', Abono::class)) {
            return 'Los abonos los recibe caja, con el turno abierto.';
        }

        if ($this->turno() instanceof TurnoDeCaja) {
            return null;
        }

        $ajena = $this->gavetaDeOtroTurno();

        return $ajena instanceof TurnoDeCaja
            ? "La gaveta está en el {$ajena->etiquetaCorta()}: abonan quienes son de ese turno o la administración."
            : 'Abrí el turno de caja para recibir abonos.';
    }

    /**
     * Por qué el botón «Facturar» está apagado, o `null` si se puede.
     *
     * Tres respuestas distintas porque son tres soluciones distintas: si
     * la gaveta está cerrada, se abre (el botón está arriba, en el
     * encabezado); si está en el turno de otra persona, factura quien es
     * de ese turno; y el laboratorio no tiene nada que hacer — le toca a
     * caja. La regla vive en `FacturaPolicy::create()`.
     */
    public function porQueNoFactura(): ?string
    {
        if (Gate::allows('create', Factura::class)) {
            return null;
        }

        $usuario = Auth::user();

        if (! $usuario instanceof User || ! $usuario->can('Create:Factura')) {
            return 'Facturar es de caja, con el turno abierto.';
        }

        $ajena = $this->gavetaDeOtroTurno();

        return $ajena instanceof TurnoDeCaja
            ? "La gaveta está en el {$ajena->etiquetaCorta()}: facturan quienes son de ese turno o la administración."
            : 'Abrí el turno de caja para facturar.';
    }

    // ── Abrir una cuenta ──────────────────────────────────────────────

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL TURNO VA EN EL ENCABEZADO
     * ─────────────────────────────────────────────────────────────────
     *
     * Arriba a la derecha, junto al título, y no en la barra de la
     * búsqueda: el turno no es una acción sobre las cuentas —es el
     * estado de quien está cobrando—, y metido entre el buscador y
     * «Abrir cuenta» competía con el botón que sí se usa todo el día.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        /*
         * ⚠️ SE REGISTRAN LAS TRES, y cada una decide si se ve.
         *
         * Filament cachea las acciones del encabezado al montar la
         * página: devolviendo solo una, el botón se quedaba en «Abrir
         * turno» después de abrirlo y solo cambiaba al recargar. Con las
         * tres registradas —abrir, cerrar y «gaveta en otro turno»—,
         * `visible()` se evalúa en cada render y el botón se transforma
         * solo.
         */
        return [
            $this->abrirTurnoAction(),
            $this->cerrarTurnoAction(),
            $this->gavetaOcupadaAction(),
        ];
    }

    public function abrirCuentaAction(): Action
    {
        return Action::make('abrirCuenta')
            ->label('Abrir cuenta')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            /*
             * ─────────────────────────────────────────────────────────
             * REDISEÑO DEL 26-OCT-2026
             * ─────────────────────────────────────────────────────────
             *
             * Mauricio: «que acá el diseño sea mejor, más bonito». Eran
             * dos secciones con encabezado y una ayuda debajo de cada
             * campo: la ventana pedía scroll para llegar al botón. Ahora,
             * de arriba abajo:
             *
             *   · El buscador dice de cada resultado la fecha de
             *     nacimiento y el documento: dos homónimos ya no se ven
             *     iguales (§9.D3).
             *   · Elegido el paciente, su ficha con el expediente. Si
             *     tiene uno solo —casi siempre— ya viene marcado: antes
             *     había que abrir un select para elegir la única opción.
             *   · El tipo de atención en tarjetas: un clic.
             *   · El pagador agrupado por clase. La póliza y la
             *     autorización aparecen solo cuando hay seguro: al
             *     contado no hay nada que escribir ahí.
             */
            ->modalIcon(Heroicon::OutlinedFolderPlus)
            ->modalIconColor('primary')
            ->modalHeading('Abrir la cuenta de un paciente')
            ->modalDescription('El encuentro y su cuenta se abren juntos. El pagador se puede cambiar después sin perder lo cargado.')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Abrir la cuenta')
            ->visible(fn (): bool => Gate::allows('create', Cuenta::class))
            ->schema([
                Select::make('persona_id')
                    ->label('Paciente')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->allowHtml()
                    ->placeholder('Buscá por nombre, apellido o número de expediente')
                    ->searchPrompt('Escribí el nombre, el apellido o el número de expediente')
                    ->noSearchResultsMessage('No aparece nadie así. Revisá cómo se escribe antes de registrarlo: un duplicado sale caro.')
                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 EL QUE YA TIENE CUENTA SE VE, PERO NO SE PUEDE
                     * ELEGIR
                     * ─────────────────────────────────────────────────
                     *
                     * Sacarlo del buscador era lo primero que se pensó y
                     * es peor: quien lo busca y no lo encuentra concluye
                     * «no está registrado» y lo crea de nuevo. Este
                     * sistema tiene una pantalla entera para fusionar
                     * duplicados justamente porque eso pasa y sale caro.
                     *
                     * Apagado y con el número de su cuenta debajo, en
                     * ámbar: no se puede elegir, se entiende por qué, y
                     * dice a dónde ir.
                     *
                     * Los renglones son HTML (`allowHtml`): lo arma
                     * `FichaDelPaciente`, que escapa todo lo que viene de
                     * la base. Por lo mismo, el rótulo del elegido también
                     * pasa por `e()`.
                     */
                    ->getSearchResultsUsing(function (string $search): array {
                        $encontrados = Persona::buscar($search);

                        /*
                         * Los documentos de los veinte de una vez: leídos
                         * renglón por renglón serían veinte consultas por
                         * tecla (§13.2).
                         */
                        $encontrados->load('identificadores');

                        /*
                         * `array_values(array_map(...))` y no
                         * `->pluck('id')->all()`: los dos dan lo mismo en
                         * tiempo de ejecución, pero solo el primero le
                         * prueba a PHPStan que son enteros y que las
                         * llaves quedaron 0,1,2… `pluck` devuelve `mixed`
                         * porque el nombre de la columna es una cadena que
                         * el analizador no puede resolver.
                         */
                        $this->precargarCuentasVivas(array_values(array_map(
                            fn (Persona $p): int => (int) $p->id,
                            $encontrados->all(),
                        )));

                        $hoy = now();

                        return $encontrados
                            ->mapWithKeys(fn (Persona $p): array => [
                                $p->id => FichaDelPaciente::opcionDelBuscador($p, $hoy, $this->cuentaVivaDe($p->id)?->numero),
                            ])
                            ->all();
                    })
                    ->disableOptionWhen(fn (mixed $value): bool => $this->cuentaVivaDe($value) instanceof Cuenta)
                    ->getOptionLabelUsing(function (mixed $value): ?string {
                        $ficha = $this->fichaDelPaciente($value);

                        return $ficha === null ? null : e($ficha['nombre']);
                    })
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set(
                        'expediente_id',
                        $this->expedienteUnicoDe($get('persona_id')),
                    )),

                /*
                 * La ficha ES el campo del expediente: el estado es su id.
                 * Se valida con `in` contra los del paciente, lo mismo que
                 * hacía el select de antes con sus opciones.
                 */
                ViewField::make('expediente_id')
                    ->hiddenLabel()
                    ->required()
                    ->in(fn (Get $get): array => array_keys($this->expedientesDe($get('persona_id'))))
                    ->validationMessages([
                        'required' => 'Elegí el expediente del paciente.',
                        'in'       => 'Ese expediente no es de este paciente.',
                    ])
                    ->visible(fn (Get $get): bool => $this->fichaDelPaciente($get('persona_id')) !== null)
                    ->view('filament.forms.ficha-del-paciente')
                    ->viewData(fn (Get $get): array => [
                        'ficha'       => $this->fichaDelPaciente($get('persona_id')),
                        'expedientes' => $this->expedientesDe($get('persona_id')),
                    ]),

                ViewField::make('tipo')
                    ->label('Tipo de atención')
                    ->required()
                    ->default(TipoEncuentro::Hospitalizacion->value)
                    ->in(array_keys(TipoEncuentro::opciones()))
                    ->validationMessages(['required' => 'Elegí el tipo de atención.'])
                    ->view('filament.forms.tipo-de-atencion')
                    ->viewData([
                        'tipos' => array_map(fn (TipoEncuentro $tipo): array => [
                            'valor'    => $tipo->value,
                            'etiqueta' => $tipo->etiqueta(),
                            'icono'    => $tipo->icono(),
                        ], TipoEncuentro::cases()),
                    ]),

                Grid::make(2)
                    ->schema([
                        Select::make('convenio_id')
                            ->label('Pagador')
                            ->required()
                            ->native(false)
                            ->options(fn (): array => $this->pagadoresPorClase())
                            ->helperText('¿La póliza no aparece? Abrila como contado y cambiala después.'),

                        CampoMayusculas::make('motivo')
                            ->label('Motivo de la atención')
                            ->hint('Opcional')
                            ->placeholder('EJ.: DOLOR ABDOMINAL')
                            ->maxLength(200),

                        /*
                         * ─────────────────────────────────────────────
                         * SOLO CON SEGURO, Y SIN IR AL SERVIDOR
                         * ─────────────────────────────────────────────
                         *
                         * Se muestran y se esconden en el navegador
                         * (`visibleJs`): el pagador no es `live()`, así
                         * que elegirlo no vuelve a pintar la página
                         * entera con todas sus tarjetas.
                         *
                         * Escondidos así SÍ llegan al servidor, por eso
                         * la acción vuelve a preguntar con la misma regla
                         * (`pideLaPoliza`, `pideLaAutorizacion`): si se
                         * eligió una aseguradora, se escribió la póliza y
                         * después se pasó a contado, esa póliza no es de
                         * esta cuenta y no se guarda.
                         */
                        CampoMayusculas::make('numero_poliza')
                            ->label('Número de póliza')
                            ->maxLength(60)
                            ->visibleJs(fn (): string => $this->siElPagadorPide(
                                fn (int $convenioId): bool => $this->pideLaPoliza($convenioId),
                            )),

                        CampoMayusculas::make('numero_autorizacion')
                            ->label('Número de autorización')
                            ->hint('Si el seguro ya la dio')
                            ->maxLength(60)
                            ->visibleJs(fn (): string => $this->siElPagadorPide(
                                fn (int $convenioId): bool => $this->pideLaAutorizacion($convenioId),
                            )),
                    ]),
            ])
            ->action(function (array $data): void {
                $persona = Persona::query()->find((int) ($data['persona_id'] ?? 0));
                $expediente = Expediente::query()->find((int) ($data['expediente_id'] ?? 0));
                $convenio = Convenio::query()->find((int) ($data['convenio_id'] ?? 0));

                if (! $persona instanceof Persona
                    || ! $expediente instanceof Expediente
                    || ! $convenio instanceof Convenio) {
                    Notification::make()
                        ->danger()
                        ->title('Faltan datos')
                        ->body('Elegí el paciente, su expediente y quién paga.')
                        ->send();

                    return;
                }

                try {
                    $cuenta = app(AbridorDeEncuentro::class)->abrir(
                        persona: $persona,
                        expediente: $expediente,
                        tipo: TipoEncuentro::from((string) $data['tipo']),
                        convenio: $convenio,
                        motivo: is_string($data['motivo'] ?? null) && $data['motivo'] !== '' ? $data['motivo'] : null,
                        numeroPoliza: $this->pideLaPoliza($convenio->id)
                            && is_string($data['numero_poliza'] ?? null) && $data['numero_poliza'] !== ''
                            ? $data['numero_poliza'] : null,
                        numeroAutorizacion: $this->pideLaAutorizacion($convenio->id)
                            && is_string($data['numero_autorizacion'] ?? null) && $data['numero_autorizacion'] !== ''
                            ? $data['numero_autorizacion'] : null,
                    );
                } catch (EncuentroException|CuentaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo abrir la cuenta')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Cuenta '.$cuenta->numero.' abierta')
                    ->body($persona->nombreCompleto().' ya puede recibir cargos.')
                    ->send();
            });
    }

    // ── Cargar cosas a una cuenta ─────────────────────────────────────

    public function cargarEnCuentaAction(): Action
    {
        return Action::make('cargarEnCuenta')
            ->label('Agregar a la cuenta')
            ->modalHeading(fn (array $arguments): string => $this->tituloDelModal($arguments))
            ->modalDescription('Cada ítem entra con el precio que le corresponde a este pagador hoy.')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Agregar')
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 «AGREGAR» SIEMPRE A LA VISTA (11-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * El botón vivía al pie del modal, DEBAJO de la cuenta en
             * curso: cada línea agregada lo empujaba un renglón más abajo,
             * y a la quinta había que bajar a buscarlo con el paciente
             * esperando. Pegado al borde de la pantalla queda en el mismo
             * lugar con la primera línea y con la vigésima.
             *
             * El otro botón dice «Listo» y no «Cancelar»: cada línea se
             * asienta al apretar Agregar, así que cerrar no deshace nada,
             * y «Cancelar» hacía dudar si lo cargado se perdía.
             */
            ->stickyModalFooter()
            ->modalSubmitAction(fn (Action $action): Action => $action->icon(Heroicon::OutlinedPlus))
            ->modalCancelActionLabel('Listo')
            ->closeModalByClickingAway(false)
            /*
             * 🔴 El permiso se verifica en los DOS lados.
             *
             * `visible()` esconde el botón; `abort_unless` dentro de la
             * acción es el que de verdad protege, porque
             * `mountAction('cargarEnCuenta', {...})` se puede disparar
             * desde el cliente sin que exista ningún botón.
             *
             * Sin esto, `canAccess()` —que solo exige `ViewAny:Cuenta`—
             * le alcanzaría a auditoría para asentar cargos reales, que
             * es exactamente lo que la matriz de permisos le niega.
             */
            ->visible(fn (): bool => Gate::allows('create', Cargo::class))
            ->schema([
                Section::make()
                    /*
                     * Doce columnas: la cantidad y el estante se reparten
                     * 4-8 en una sola fila —o la cantidad, el precio y el
                     * médico 4-4-4 en un honorario—, y lo demás va a lo
                     * ancho.
                     */
                    ->columns(12)
                    ->schema([
                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 UN SOLO CAMPO: EL CÓDIGO O EL NOMBRE
                         * ─────────────────────────────────────────────
                         *
                         * Regla de Mauricio (10-oct-2026): «solo debería
                         * estar lo de escanea el código o escribe el
                         * nombre, el resto no debe». Había un segundo
                         * desplegable abajo para el nombre, y eran dos
                         * lugares para decir lo mismo: se escribía
                         * «acetamin» arriba y había que volver a
                         * escribirlo abajo.
                         *
                         * Ahora la pistola escribe un código y elige; una
                         * persona escribe un nombre y los resultados
                         * aparecen debajo, en la misma línea. Lo demás
                         * —cuánto, de dónde, cómo— aparece recién cuando
                         * ya se sabe QUÉ.
                         *
                         * ⚠️ `dehydrated()` en TRUE. La pistola teclea el
                         * código y manda Enter de una, y ese Enter puede
                         * llegar antes de que el ítem quede elegido: si el
                         * escaneo no viaja con los datos, el envío llega
                         * sin nada. Viajando, `agregar()` lo resuelve como
                         * respaldo y escanear + Enter carga la línea sin
                         * tocar el mouse.
                         *
                         * ⚠️ Con pausa de 400 ms: busca cuando se deja de
                         * teclear, no en cada letra. Cada búsqueda es una
                         * consulta al catálogo (§13.2).
                         */
                        BarcodeInput::make('escaneo')
                            ->label('Escaneá el código o escribí el nombre')
                            ->live(debounce: 400)
                            ->autofocus()
                            ->columnSpanFull()
                            ->placeholder('Pistola, cámara, o escribí tres letras del nombre')
                            ->afterStateUpdated(fn (mixed $state, Set $set) => $this->resolverEscaneo($state, $set)),

                        /*
                         * Lo que el buscador no pudo resolver, dicho
                         * debajo del campo y no en una notificación que
                         * se va sola.
                         */
                        Placeholder::make('aviso_del_buscador')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (): bool => $this->avisoDelBuscador !== null)
                            ->content(fn (): HtmlString => new HtmlString(
                                '<span style="font-size:.8125rem;color:rgb(248 113 113)">'
                                .e((string) $this->avisoDelBuscador).'</span>'
                            )),

                        /*
                         * ─────────────────────────────────────────────
                         * LOS RESULTADOS, DEBAJO DEL MISMO CAMPO
                         * ─────────────────────────────────────────────
                         *
                         * Un botón por producto: tocarlo lo elige. Se
                         * quedan a la vista después de elegir, con el
                         * elegido marcado, para poder cambiar de idea sin
                         * volver a escribir.
                         *
                         * Productos y no formas: «ACETAMINOFEN 500 MG
                         * TABLETA» una vez, aunque se venda por tableta y
                         * por caja. Cómo se le agrega se pregunta después,
                         * con el producto ya elegido.
                         */
                        Actions::make($this->botonesDelBuscador())
                            ->key('resultados_del_buscador')
                            ->columnSpanFull(),

                        Hidden::make('item_id'),

                        /*
                         * ─────────────────────────────────────────────
                         * LO QUE QUEDÓ ELEGIDO, EN UNA LÍNEA
                         * ─────────────────────────────────────────────
                         *
                         * Fijo y no en una notificación que se va sola a
                         * los pocos segundos: el nombre, de qué envase se
                         * leyó el código y el código del catálogo.
                         *
                         * ⚠️ Solo cuando NO hay botones de resultados —se
                         * escaneó, o la búsqueda dio uno solo—. Con la
                         * lista a la vista, el botón marcado ya dice qué
                         * se eligió, y este renglón solo empujaba la
                         * cantidad hacia abajo para repetir el código.
                         */
                        Placeholder::make('lo_encontrado')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->itemDe($get('item_id')) instanceof Item
                                && $this->resultadosDelBuscador() === [])
                            ->content(fn (Get $get): HtmlString => $this->resumenDeLoElegido($get)),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 ¿CÓMO SE LE AGREGA? — LO ELIGE QUIEN COBRA
                         * ─────────────────────────────────────────────
                         *
                         * Regla de Mauricio (10-oct-2026): con la caja de
                         * tabletas, quien cobra «escoge cómo se le va a
                         * agregar, si la caja o tabletas».
                         *
                         * Solo aparece cuando hay algo que elegir: la
                         * unidad de «Se dispensa en» y, al lado, cada
                         * envase cargado en la ficha, vendido sin abrir.
                         * Con una sola forma no hay pregunta.
                         *
                         * ⚠️ SIN NADA MARCADO. Venir con «TABLETA» puesta
                         * es apretar Agregar sin mirar y cobrar tabletas
                         * cuando se entregó la caja; venir con la caja
                         * puesta es cobrar cien cuando se entregaron dos.
                         * Las dos equivocaciones son plata, así que la
                         * respuesta la da la persona y el formulario no
                         * deja seguir sin ella.
                         */
                        ToggleButtons::make('unidad_cobro')
                            ->label('¿Cómo se le agrega?')
                            /*
                             * Agrupados, como un solo control de varias
                             * caras: se lee como «una de estas», que es lo
                             * que es. Sueltos parecían botones de acción.
                             */
                            ->grouped()
                            /*
                             * ─────────────────────────────────────────
                             * CÓMO, CUÁNTO Y DE DÓNDE, EN UN RENGLÓN
                             * ─────────────────────────────────────────
                             *
                             * Las tres preguntas de la línea, en el orden
                             * en que se contestan y una al lado de la
                             * otra (`laLineaVaEnUnaFila()`). Con cuatro
                             * envases o más el control no cabe en media
                             * fila y va solo arriba.
                             *
                             * Sin texto de ayuda debajo: que el envase va
                             * cerrado lo dice el recuadro de abajo —«1
                             * CAJA X 100 sin abrir = 100 TAB»—, que ya se
                             * lee antes de apretar Agregar.
                             */
                            ->columnSpan(fn (Get $get): array => [
                                'default' => 1,
                                'lg'      => $this->laLineaVaEnUnaFila($get) ? 6 : 12,
                            ])
                            ->live()
                            ->options(fn (Get $get): array => $this->unidadesDeCobro($this->itemDe($get('item_id'))))
                            ->icons(fn (Get $get): array => $this->iconosDeLasFormas($this->itemDe($get('item_id'))))
                            ->visible(fn (Get $get): bool => count($this->unidadesDeCobro($this->itemDe($get('item_id')))) > 1)
                            ->required(fn (Get $get): bool => count($this->unidadesDeCobro($this->itemDe($get('item_id')))) > 1)
                            ->validationMessages([
                                'required' => 'Elegí cómo se le agrega: suelto o el envase sin abrir.',
                            ])
                            /*
                             * Cuántas cajas hay en cada estante depende
                             * de si se vende la caja o la tableta, así que
                             * el estante se vuelve a sugerir con la forma
                             * ya elegida.
                             */
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $set('almacen_id', $this->estanteSugerido(
                                    $this->itemDe($get('item_id')),
                                    is_string($state) ? $state : null,
                                )?->id);
                            }),

                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(0.0001)
                            ->step('0.0001')
                            ->columnSpan(fn (Get $get): array => [
                                'default' => 1,
                                'lg'      => $this->laLineaVaEnUnaFila($get) ? 2 : 4,
                            ])
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $this->itemDe($get('item_id')) instanceof Item)
                            /*
                             * Escondida también viaja: el Enter de la
                             * pistola puede llegar antes de que el campo
                             * se pinte, y sin esto la línea llegaba sin
                             * cantidad.
                             */
                            ->dehydratedWhenHidden()
                            /*
                             * 🔴 EN QUÉ SE CUENTA, ADENTRO DEL CAMPO.
                             *
                             * «1» escrito en una caja de cien tabletas y
                             * «1» escrito en una tableta son el mismo
                             * carácter y son L 900 de diferencia. El
                             * sufijo dice TAB o CAJA pegado al número, y
                             * la cuenta completa —«1 CAJA X 100 = 100
                             * TAB»— va en el recuadro de abajo, antes de
                             * apretar Agregar.
                             */
                            ->suffix(fn (Get $get): ?string => $this->enQueSeCuenta($get)),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 DE QUÉ ESTANTE SE AGARRÓ
                         * ─────────────────────────────────────────────
                         *
                         * Desde que farmacia y bodega son dos lugares
                         * distintos, «almacén» dejó de ser un campo de
                         * trámite: es el dato que después contesta por
                         * qué el carrito rojo tiene una ampolla menos.
                         *
                         * Cada opción dice CUÁNTO HAY ahí. Un
                         * desplegable de nombres pelados obliga a
                         * adivinar, y quien adivina elige el primero de
                         * la lista — que es como se intenta despachar de
                         * un estante vacío con el paciente enfrente.
                         *
                         * Viene preseleccionado el estante con más
                         * existencia entre los que dispensan, así que en
                         * el caso normal nadie toca este campo.
                         */
                        /*
                         * ⚠️ OCHO COLUMNAS Y TEXTO DE UNA LÍNEA.
                         *
                         * Con dos columnas y un párrafo de ayuda, el
                         * campo quedaba con el nombre partido en tres
                         * renglones y las opciones cortadas: en el lugar
                         * de la pantalla donde hay menos tiempo para
                         * leer. Lo que hay que saber ya lo dicen las
                         * propias opciones —«ALMACEN-1 · 2300 ML»—, así
                         * que el texto de abajo se queda con lo único que
                         * ellas no dicen.
                         */
                        Select::make('almacen_id')
                            ->label('¿De dónde sale?')
                            ->native(false)
                            ->columnSpan(fn (Get $get): array => [
                                'default' => 1,
                                'lg'      => $this->laLineaVaEnUnaFila($get) ? 4 : 8,
                            ])
                            ->dehydratedWhenHidden()
                            ->options(fn (Get $get): array => $this->estantesConLoQueHay($this->itemDe($get('item_id')), $this->formaEnUso($get)))
                            ->required(fn (Get $get): bool => $this->exigeEstante($this->itemDe($get('item_id'))))
                            ->visible(fn (Get $get): bool => $this->muestraEstante($this->itemDe($get('item_id')), $this->formaEnUso($get)))
                            ->placeholder(fn (Get $get): string => $this->estantesConLoQueHay($this->itemDe($get('item_id')), $this->formaEnUso($get)) === []
                                ? 'No hay en ningún almacén'
                                : 'Elegí el estante'),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 EL HONORARIO SÍ LLEVA PRECIO A LA VISTA
                         * ─────────────────────────────────────────────
                         *
                         * Es la única familia del catálogo donde el
                         * precio de lista es una referencia y no una
                         * regla: el honorario cambia por médico, por
                         * complejidad y por lo que se acordó con la
                         * familia. Obligarlo a pasar por el tarifario
                         * significaba una fila nueva por cada médico, o
                         * —lo que de verdad pasaba— cobrar el de lista y
                         * arreglarlo después con un descuento que no
                         * explica nada.
                         *
                         * Viene PROPUESTO con el precio del pagador de
                         * esta cuenta. Si no se toca, el cargo sigue el
                         * camino de siempre y sale del tarifario; solo se
                         * marca como precio acordado cuando el número
                         * cambia de verdad.
                         *
                         * ⚠️ Y NO se abre para el resto del catálogo. Un
                         * campo de precio libre en la pantalla de cobro
                         * es por donde se va la plata sin que nadie lo
                         * note: un medicamento cobrado a mano no lo
                         * detecta ningún arqueo, porque el arqueo compara
                         * contra lo que dice el sistema.
                         */
                        TextInput::make('precio_acordado')
                            ->label('Precio del honorario')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->prefix('L')
                            ->columnSpan(4)
                            ->visible(fn (Get $get): bool => $this->esHonorario($this->itemDe($get('item_id'))))
                            /*
                             * 🔴 El texto distingue los dos casos, porque
                             * un campo vacío se lee igual en los dos y no
                             * lo son: o el tarifario propuso un número, o
                             * este honorario no tiene precio de lista
                             * —que es normal en los que cambian con cada
                             * médico— y hay que escribirlo sí o sí.
                             *
                             * Sin decirlo, quien cobra ve el campo en
                             * blanco y concluye que el sistema falló.
                             */
                            ->helperText(function (Get $get): string {
                                $item = $this->itemDe($get('item_id'));
                                $medico = $this->medicoDe($get('medico_id'));

                                if ($medico instanceof Medico && $this->loQueCobraElMedico($item, $medico) !== null) {
                                    return 'Es lo que cobra '.$medico->nombre.'. Cambialo solo para este cobro.';
                                }

                                return $this->precioPropuesto($item, $this->idDelMedico($get('medico_id'))) === null
                                    ? 'Este honorario no tiene precio en el tarifario: escribí cuánto se le cobra.'
                                    : 'Propuesto del tarifario. Cambialo si este médico cobra otra cosa.';
                            }),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 EL MÉDICO SE ELIGE, NO SE ESCRIBE
                         * ─────────────────────────────────────────────
                         *
                         * Esto era un campo de texto libre, y con texto
                         * libre «Dr. Carlos», «Dr Carlos Pineda» y
                         * «CARLOS PINEDA» son tres doctores para un GROUP
                         * BY y uno solo para quien firma el cheque a fin
                         * de mes.
                         *
                         * Elegirlo además trae su precio: el doctor
                         * Carlos cobra L 500 por la consulta y el doctor
                         * Juan L 100, y eso ya no hay que acordarse de
                         * teclearlo.
                         */
                        Select::make('medico_id')
                            ->label('¿De quién es el honorario?')
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->columnSpan(4)
                            ->visible(fn (Get $get): bool => $this->esHonorario($this->itemDe($get('item_id'))))
                            ->placeholder('Escribí el apellido')
                            ->options(fn (): array => $this->medicosVigentes())
                            ->getSearchResultsUsing(fn (string $search): array => $this->medicosVigentes($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->medicoDe($value)?->etiqueta())
                            /*
                             * Elegir al médico repone el precio: el suyo
                             * si tiene lista propia, y si no el del
                             * tarifario. Se PISA lo que hubiera —incluso
                             * algo tecleado a mano— porque cambiar de
                             * médico es cambiar de honorario, y dejar el
                             * número del doctor anterior es cobrarle al
                             * paciente la tarifa de alguien que no lo
                             * atendió.
                             */
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $set('precio_acordado', $this->precioPropuesto(
                                    $this->itemDe($get('item_id')),
                                    is_numeric($state) ? (int) $state : null,
                                ));
                            })
                            /*
                             * El cirujano nuevo que opera un sábado se da
                             * de alta sin salir de acá. Solo para quien
                             * puede crear médicos: al resto ni le aparece
                             * el botón.
                             */
                            ->createOptionForm(fn (): ?array => Gate::allows('create', Medico::class)
                                ? self::altaRapidaDeMedico()
                                : null)
                            /*
                             * ⚠️ El `abort_unless` se queda aunque el
                             * botón no se pinte: esconder un control en
                             * Livewire no cierra la puerta, la acción se
                             * sigue pudiendo llamar desde el navegador.
                             * Y como corta el flujo, el retorno es `int`
                             * y no `?int` — nunca hay un camino que
                             * devuelva nulo.
                             */
                            ->createOptionUsing(function (array $data): int {
                                abort_unless(Gate::allows('create', Medico::class), 403);

                                return (int) Medico::create([
                                    'nombre'          => $data['nombre'] ?? '',
                                    'especialidad_id' => $data['especialidad_id'] ?? null,
                                    'colegiacion'     => $data['colegiacion'] ?? null,
                                    'vigencia_desde'  => now()->toDateString(),
                                ])->getKey();
                            }),

                        /*
                         * ─────────────────────────────────────────────
                         * UNA SOLA LÍNEA DE CONTEXTO, A LO ANCHO
                         * ─────────────────────────────────────────────
                         *
                         * Antes cada campo llevaba su propio texto de
                         * ayuda y ninguno medía lo mismo: los cuatro
                         * cuadros empezaban parejos y terminaban a cuatro
                         * alturas distintas, con las frases colgando en
                         * escalera. Eso es lo que se ve torcido — no el
                         * largo de cada frase, sino que sean cuatro.
                         *
                         * Acá abajo, a ancho completo, hay UNA. Los cuatro
                         * campos quedan del mismo alto y lo que hay que
                         * leer antes de apretar Agregar está junto.
                         *
                         * 🔴 Y dice lo mismo que antes: «1» escrito en una
                         * caja de cien tabletas y «1» escrito en una
                         * tableta son el mismo carácter y son L 900 de
                         * diferencia.
                         */
                        Placeholder::make('como_queda')
                            ->hiddenLabel()
                            /*
                             * Cuando no hay existencia, el «Se pidió
                             * prestado» va al lado del aviso y no en un
                             * renglón propio debajo: el aviso dice el
                             * problema y el botón, al alcance, la salida.
                             */
                            ->columnSpan(fn (Get $get): array => [
                                'default' => 1,
                                'lg'      => $this->sePuedePedirPrestado($get) ? 9 : 12,
                            ])
                            ->visible(fn (Get $get): bool => $this->hayLecturaDeLaLinea($get))
                            ->content(fn (Get $get): HtmlString => $this->comoQuedaLaLinea($get)),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 «NO HAY» NO PUEDE SER UN CALLEJÓN SIN SALIDA
                         * ─────────────────────────────────────────────
                         *
                         * Cuando el estante está vacío y el paciente
                         * está enfrente, lo que pasa de verdad en el
                         * hospital es que se le pide prestado a la
                         * farmacia de la esquina. Hasta ahora el sistema
                         * decía «recibilo o trasladalo» y ahí terminaba:
                         * o alguien inventaba una recepción sin factura,
                         * o cobraba por fuera.
                         *
                         * Este botón registra el préstamo —sube la
                         * existencia y deja la deuda con quien prestó— y
                         * DEJA EL ESTANTE ELEGIDO, así que quien cobra
                         * sigue en la misma línea sin volver a empezar.
                         *
                         * Solo aparece cuando de verdad no hay nada: con
                         * existencia, ofrecerlo sería enseñar a pedir
                         * prestado lo que ya está en el estante.
                         */
                        Actions::make([
                            $this->accionDePedirPrestado(),
                        ])
                            ->columnSpan(fn (Get $get): array => [
                                'default' => 1,
                                'lg'      => $this->hayLecturaDeLaLinea($get) ? 3 : 12,
                            ])
                            ->alignEnd(fn (Get $get): bool => $this->hayLecturaDeLaLinea($get))
                            ->verticallyAlignCenter()
                            ->visible(fn (Get $get): bool => $this->sePuedePedirPrestado($get)),
                    ]),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                abort_unless(Gate::allows('create', Cargo::class), 403);

                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                /*
                 * Y sobre ESTA cuenta en particular: el id llega del
                 * cliente. `CuentaPolicy::cargar()` niega las cerradas y
                 * las anuladas. Es `cargar` y no `update`: laboratorio y
                 * consulta externa le cargan a la cuenta, pero cerrarla y
                 * ponerle descuento es de caja (21-oct-2026).
                 */
                abort_unless(Gate::allows('cargar', $cuenta), 403);

                /*
                 * 🔴 Si no se pudo agregar, la línea se queda como está.
                 *
                 * Antes el modal se volvía a montar igual, y con eso se
                 * borraba lo tecleado: «no hay suficiente en el estante»
                 * dejaba el campo en blanco y había que volver a escanear
                 * para corregir solo la cantidad. `halt()` corta acá y
                 * deja el formulario con todo puesto.
                 */
                if (! $this->agregar($cuenta, $data)) {
                    $action->halt();
                }

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL MODAL TIENE QUE QUEDARSE ABIERTO
                 * ─────────────────────────────────────────────────────
                 *
                 * Una ronda de medicamentos son veinte líneas. Si el
                 * modal se cierra en cada una, son veinte veces buscar al
                 * paciente de nuevo — y la pantalla deja de servir para
                 * lo que existe.
                 *
                 * 🔴 `replaceMountedAction` sola NO alcanza. Filament,
                 * después de correr la acción, decide si desmontarla
                 * comparando **solo el nombre y el contexto** de lo que
                 * estaba montado contra lo que quedó montado
                 * (`InteractsWithActions::callMountedAction`). Volver a
                 * montar la misma acción con el mismo contexto le da dos
                 * arreglos idénticos: concluye que nadie la reemplazó y
                 * la cierra igual.
                 *
                 * El contador hace que el contexto cambie en cada ítem, y
                 * con eso la comparación da distinto y el modal se queda.
                 * Filament solo lee `schemaComponent`, `table`, `bulk` y
                 * `recordKey` del contexto: una clave propia le es
                 * indiferente.
                 */
                $this->renglonDeLaTanda++;

                $this->replaceMountedAction(
                    'cargarEnCuenta',
                    $arguments,
                    ['renglon' => $this->renglonDeLaTanda],
                );
            })
            /*
             * `fillForm` con el ítem que venga en los argumentos, si
             * viene: deja el ítem puesto y solo pide lo que falta.
             */
            ->fillForm(function (array $arguments): array {
                $this->cargarElDescuentoDe($arguments);

                /*
                 * El precio de un honorario depende del PAGADOR, así que
                 * para poder proponerlo hay que saber de qué cuenta es
                 * este formulario. `$arguments` solo llega acá; los
                 * closures de los campos reciben `Get`, no la cuenta.
                 */
                $this->cuentaDelFormulario = $this->cuentaDe($arguments)?->id;

                /*
                 * Otra cuenta puede tener otro pagador, y con otro pagador
                 * el mismo honorario vale otra cosa. La memoria del
                 * formulario anterior no sirve acá.
                 */
                $this->precioPropuestoPorItem = [];
                $this->medicosPorId = [];
                $this->cuentaLeida = null;

                /*
                 * Cada línea arranca sin filtro. Corre al abrir el modal y
                 * también en cada remontaje —o sea después de cada ítem
                 * agregado—, que es justo lo que hace falta: la gaveta que
                 * se escaneó para el renglón anterior no tiene por qué
                 * seguir acotando el siguiente.
                 */
                $this->principioEscaneado = null;
                $this->presentacionEscaneada = null;
                $this->busquedaEscrita = null;
                $this->avisoDelBuscador = null;

                $itemPuesto = is_numeric($arguments['item'] ?? null) ? (int) $arguments['item'] : null;

                /*
                 * ⚠️ El precio también se propone ACÁ y no solo en
                 * `prepararLaLinea()`. Cuando el ítem llega puesto en los
                 * argumentos nadie tocó el buscador,
                 * así que su `afterStateUpdated` nunca corre y el campo
                 * se quedaba vacío justo en el camino más rápido.
                 */
                $puesto = $this->itemDe($itemPuesto);

                /*
                 * La forma solo viene puesta cuando hay una sola. Con la
                 * tableta y la caja, «¿Cómo se le agrega?» abre sin nada
                 * marcado: lo elige quien cobra.
                 */
                $forma = $puesto instanceof Item ? $this->formaUnicaDe($puesto) : null;

                return [
                    'item_id'         => $puesto?->id,
                    'cantidad'        => 1,
                    'unidad_cobro'    => $forma,
                    'almacen_id'      => $this->estanteSugerido($puesto, $forma)?->id,
                    'precio_acordado' => $this->esHonorario($puesto)
                        ? $this->precioPropuesto($puesto)
                        : null,
                    'medico_id'           => null,
                    'referencia_acordada' => null,
                ];
            })
            /*
             * ─────────────────────────────────────────────────────────
             * EL DESCUENTO VIVE EN LA BANDA DE ARRIBA, NO EN EL FORMULARIO
             * ─────────────────────────────────────────────────────────
             *
             * Son dos decisiones distintas y de dos personas distintas:
             * qué lleva el paciente lo dice la receta, cuánto se le rebaja
             * lo autoriza el hospital. Adentro de la fila del ítem obligaba
             * a decidirlo mientras se tecleaba QUÉ se cobra, y encima había
             * que volver a escribirlo en cada línea.
             *
             * Acá se pone UNA vez, para toda la tanda, y ocupa el rincón de
             * arriba a la derecha: es una excepción que se usa cinco veces
             * al día contra un escaneo que se usa cien.
             */
            ->modalContent(function (array $arguments) {
                $cuenta = $this->cuentaDe($arguments);

                return view('filament.pages.partials.descuentos-de-la-cuenta', [
                    'cuenta' => $cuenta,

                    /*
                     * Solo a quien puede guardarlos: el descuento se
                     * escribe en la cuenta (`update`). Laboratorio y
                     * consulta externa cargan, pero no rebajan — antes la
                     * banda les aparecía y lo que tecleaban no tomaba.
                     */
                    'puede'      => $cuenta instanceof Cuenta && Gate::allows('update', $cuenta),
                    'tope'       => $this->topeDeDescuento($cuenta)->por('100')->redondeado(2),
                    'ayuda'      => $this->ayudaDelDescuento($cuenta),
                    'descuento'  => $this->descuentoDeLaTanda,
                    'motivo'     => $this->motivoDeLaTanda,
                    'servicios'  => $this->datosDelDescuento($cuenta, RubroDeDescuento::Servicios),
                    'honorarios' => $this->datosDelDescuento($cuenta, RubroDeDescuento::Honorarios),
                ]);
            })
            ->modalFooter(fn (array $arguments) => view(
                'filament.pages.partials.cargos-de-la-cuenta',
                [
                    'cuenta' => $this->cuentaDe($arguments),

                    /*
                     * El descuento que está puesto pero todavía no tocó
                     * ninguna línea: el pie lo muestra para que la pantalla
                     * no se vea inerte entre que se teclea y que se agrega.
                     */
                    'armado' => $this->descuentoDeLaTanda,

                    /*
                     * Van como datos de la vista y no leídos con `$this`
                     * adentro del Blade: así el partial se puede renderizar
                     * desde cualquier lado sin depender de qué componente
                     * lo esté pintando.
                     */
                    'usuario'      => auth()->id(),
                    'cargoAQuitar' => $this->cargoAQuitar,
                ],
            ));
    }

    /**
     * Asienta la línea. Devuelve si quedó asentada, para que quien llama
     * sepa si puede limpiar el formulario o tiene que dejarlo como está.
     *
     * @param array<string, mixed> $data
     */
    private function agregar(Cuenta $cuenta, array $data): bool
    {
        /*
         * ⚠️ La memoria de estantes se tira ANTES de cargar: lo que se
         * está por descontar cambia el saldo, y en una ronda de veinte
         * líneas la segunda tiene que ver lo que dejó la primera. Una
         * lista de existencias vieja es cómo alguien intenta despachar
         * del estante que acaba de vaciar.
         */
        $this->estantesPorItem = [];

        $item = $this->itemDe($data['item_id'] ?? null);
        $origen = $this->presentacionEscaneada;

        /*
         * Respaldo del Enter de la pistola: si todavía no quedó elegido
         * pero vino un código, se resuelve acá. Sin esto, escanear y
         * presionar Enter no hace nada y el operador vuelve a escanear —
         * que es como nacen los cargos duplicados.
         */
        if (! $item instanceof Item) {
            $item = $this->itemPorCodigo($data['escaneo'] ?? null);
            $origen = $this->presentacionPorCodigo($data['escaneo'] ?? null)?->id;
        }

        if (! $item instanceof Item) {
            Notification::make()
                ->warning()
                ->title('Falta decir qué se agrega')
                ->body('Escaneá el código, o escribí el nombre y tocá el producto en la lista.')
                ->send();

            return false;
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL GUARDIÁN ESTÁ ACÁ, NO EN EL BUSCADOR
         * ─────────────────────────────────────────────────────────────
         *
         * El buscador ya filtra por área, pero esto es un método público
         * de Livewire: el id del ítem llega del cliente y se puede armar
         * a mano. Una regla que solo vive en la pantalla no es una regla,
         * y lo que limita qué se le puede cobrar a un paciente tiene que
         * estar donde se escribe.
         *
         * ⚠️ DESPUÉS del respaldo del Enter y no antes. Antes el guardián
         * corría solo sobre el ítem elegido, así que un código escaneado
         * y mandado con Enter de una pasaba por el costado sin que nadie
         * preguntara de qué área era.
         */
        try {
            CatalogoDelRol::exigirQuePuedaCargar($item);
        } catch (CargoException $e) {
            Notification::make()
                ->danger()
                ->title('Eso no se carga desde tu área')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        }

        /*
         * ⚠️ Si no se entiende el número, NO se asume nada. Un `12,5`
         * que llegue como float y se convierta a la brava termina
         * cobrándole al paciente una cantidad que nadie tecleó
         * (`NumeroDeFormulario`, lección del bloque 5d-1).
         */
        $tecleada = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

        if (! $tecleada instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('No se entiende esa cantidad')
                ->body('Escribí solo números, con punto o coma para los decimales. Ejemplo: 2.5')
                ->persistent()
                ->send();

            return false;
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 CÓMO SE LE AGREGA: LO QUE ELIGIÓ LA PERSONA, O NADA
         * ─────────────────────────────────────────────────────────────
         *
         * Con una sola forma no hay nada que elegir. Con la tableta y la
         * caja, la respuesta la tiene que dar quien cobra: el formulario
         * ya lo exige, pero esto es un método público y el Enter de la
         * pistola puede llegar antes de que «¿Cómo se le agrega?» se
         * pinte. Adivinar acá sería cobrar tabletas por una caja o una
         * caja por dos tabletas.
         */
        $forma = $this->formaElegida($item, $data['unidad_cobro'] ?? null);

        if ($forma === null) {
            Notification::make()
                ->warning()
                ->title('Elegí cómo se le agrega')
                ->body(implode(' o ', $this->unidadesDeCobro($item)).'. Tocá una y presioná Agregar.')
                ->send();

            return false;
        }

        /*
         * 🔴 Acá se convierte a unidad de dispensación, y en un solo
         * lugar. Todo lo que sale de esta línea hacia el motor ya está en
         * la unidad del kardex: si la conversión viviera repartida, una
         * caja terminaría descontando una tableta.
         */
        $cantidad = $this->aUnidadesDeDispensacion($item, $forma, $tecleada);

        if (! $cantidad instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('Esa forma no aplica a este ítem')
                ->body(
                    'Elegí otra forma de agregarlo. Si el producto se fracciona y no aparece la '
                    .'fracción, hay que declararla en el catálogo antes de poder venderla así.'
                )
                ->persistent()
                ->send();

            return false;
        }

        $envase = $this->presentacionDeLaUnidad($item, $forma);
        $enteros = app(FormasDeCobro::class)->envasesEnteros($forma, $tecleada, $this->presentacionesDe($item));

        $almacen = isset($data['almacen_id']) && is_numeric($data['almacen_id'])
            ? Almacen::query()->find((int) $data['almacen_id'])
            : null;

        /*
         * Stock infinito sin estante elegido (13-oct-2026): si en algún
         * estante hay, sale de ahí primero; si no hay en ninguno, va sin
         * almacén y el motor lo carga sin tocar el inventario.
         */
        if (! $almacen instanceof Almacen && $item->tieneStockInfinito()) {
            $almacen = $this->estanteSugerido($item, $forma);
        }

        /*
         * Stock infinito por envase sin abrir: tiene que haber de ese
         * envase en algún estante donde está. Sin esto, la línea saldría
         * sin almacén y el historial diría que se entregó una caja de un
         * lugar donde no hay cajas.
         */
        $sinEseEnvase = $this->envaseQueNoEstaEnNingunLado($item, $forma);

        if ($sinEseEnvase instanceof ItemPresentacion) {
            Notification::make()
                ->danger()
                ->title('No hay '.UbicadorDeProducto::comoSeLlama($sinEseEnvase).' en ningún almacén')
                ->body($this->porQueNoHayEseEnvase($sinEseEnvase))
                ->persistent()
                ->send();

            return false;
        }

        /*
         * 🔴 EL DESCUENTO DEL HOSPITAL ES SOLO PARA LO QUE SALE DE
         * FARMACIA.
         *
         * Está puesto arriba y queda fijo mientras dura la tanda, así que
         * en la misma tanda puede entrar una consulta. Esa no lleva
         * rebaja, y no la lleva ACÁ y no en la memoria de quien atiende:
         * esa es la diferencia entre una política y una costumbre.
         *
         * De porcentaje tecleado a fracción: 30 → 0.30. La división por
         * cien va acá y no en la calculadora, para que el dominio hable
         * siempre en fracciones y la pantalla siempre en porcentajes.
         */
        $fraccion = $cuenta->descuento_hospital;

        $descuento = $item->se_almacena && $fraccion !== null
            ? Decimal::de($fraccion)
            : null;

        $motivo = $descuento instanceof Decimal
            ? $cuenta->motivo_descuento_hospital
            : null;

        $autorizado = $descuento instanceof Decimal ? $cuenta->descuento_hospital_por : null;

        /*
         * Y lo que NO sale de farmacia lleva el descuento de su rubro
         * —servicios u honorarios—, si la cuenta lo tiene en porcentaje:
         * topado con la categoría del ítem. En lempiras se reparte
         * después, entre todos los del rubro.
         */
        $rubro = AplicadorDeDescuentoDeLaCuenta::rubroDe($item);
        $enSuRubro = $rubro instanceof RubroDeDescuento
            ? app(AplicadorDeDescuentoDeLaCuenta::class)->fraccionParaLaLineaNueva($cuenta, $item, now())
            : null;

        $deLaCuenta = null;

        if ($rubro instanceof RubroDeDescuento && $enSuRubro instanceof Decimal) {
            $descuento = $enSuRubro;
            $motivo = AplicadorDeDescuentoDeLaCuenta::motivoDe($cuenta, $rubro);
            $autorizado = AplicadorDeDescuentoDeLaCuenta::quienLoDio($cuenta, $rubro);
            $deLaCuenta = $rubro;
        }

        try {
            $cargos = app(RegistradorDeCargo::class)->registrar(
                cuenta: $cuenta,
                linea: new LineaDeCargo(
                    item: $item,
                    cantidad: $cantidad,
                    claveIdempotencia: $this->claveDeEnvio,
                    almacen: $almacen,
                    descuentoComercialPorcentaje: $descuento,
                    motivoDescuento: $motivo,

                    /*
                     * 🔴 Quién lo dio, no quién lo autorizó.
                     *
                     * El descuento no necesita el permiso de nadie: lo da
                     * quien está cobrando. Pero queda con nombre, porque un
                     * descuento anónimo es un descuento que nadie va a
                     * explicar. `created_by` ya guarda quién asentó el
                     * cargo; esto guarda quién decidió la rebaja, que en un
                     * cargo cargado por una interfaz no son la misma
                     * persona.
                     */
                    autorizadoPor: $autorizado,

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 SOLO SI EL NÚMERO CAMBIÓ DE VERDAD
                     * ─────────────────────────────────────────────────
                     *
                     * El campo viene propuesto con el precio del
                     * tarifario, así que la mayoría de las veces llega
                     * igual. Mandarlo igual marcaría TODOS los honorarios
                     * como «precio acordado», y entonces la marca dejaría
                     * de significar algo: el reporte no podría distinguir
                     * los que de verdad se negociaron.
                     *
                     * Nulo = el cargo sigue el camino de siempre y el
                     * precio sale del tarifario con su vigencia (ADR-0003).
                     */
                    precioAcordado: $this->honorarioAcordado($item, $cuenta, $data),
                    referenciaAcordada: $this->deQuienEsElHonorario($item, $data),

                    /*
                     * La FK, además del texto: «cuánto hay que liquidarle
                     * al doctor Carlos» no se contesta sobre un renglón
                     * que dice «Dr. Carlos» escrito de tres formas.
                     */
                    medicoId: $this->esHonorario($item)
                        ? $this->idDelMedico($data['medico_id'] ?? null)
                        : null,

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 EN QUÉ SE COBRÓ, PARA QUE EL RENGLÓN SE LEA
                     * ─────────────────────────────────────────────────
                     *
                     * `cantidad` va en unidad de dispensación —100 TAB—
                     * y eso no cambia: es lo que sale del estante. Esto
                     * es lo otro:
                     *
                     *   · la CAJA vendida sin abrir viaja con cuántas
                     *     —«1 CAJA»—, y el renglón se lee así y no «100»;
                     *   · la tableta suelta lleva como origen la caja de
                     *     la que se leyó el código, si se escaneó una.
                     */
                    presentacion: $envase ?? $this->presentacionDe($item, $origen),
                    envases: $enteros,

                    /*
                     * La rebaja de servicios u honorarios viene de la
                     * cuenta: se topa con la regla de la cuenta, no con
                     * la de un descuento suelto (12-oct-2026).
                     */
                    descuentoDeLaCuenta: $deLaCuenta,
                ),
            );
        } catch (CargoException|CuentaException|EncuentroException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo agregar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        } catch (ExistenciaInsuficienteException $e) {
            Notification::make()
                ->danger()
                ->title('No hay suficiente en el estante')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        }

        /*
         * Clave nueva: lo que venga después es un hecho distinto y tiene
         * que poder registrarse. Renovarla acá —y no al empezar la
         * petición— es lo que hace que el reintento del MISMO envío siga
         * trayendo la clave vieja y no duplique.
         */
        $this->claveDeEnvio = (string) Str::uuid();

        $this->repartirDeNuevoElRubroDe($cuenta, $item);

        /*
         * Se suma con SIGNO y no con `montoTotal()`, que devuelve el
         * valor absoluto: si el reintento idempotente trajera una fila
         * negativa, el aviso diría el doble de lo que se cobró.
         *
         * Con `foreach` y no con `reduce()` porque el acumulador de
         * `reduce` queda tipado como `Decimal|null` en la primera vuelta
         * y eso hace fallar el nivel 7 sin ganar nada en claridad.
         */
        $total = Decimal::cero();

        foreach ($cargos as $cargo) {
            $total = $total->sumar(Decimal::de($cargo->total));
        }

        Notification::make()
            ->success()
            ->title($item->nombre)
            ->body(
                $cargos->count() > 1
                    ? 'Agregado desde '.$cargos->count().' lotes, por L '.number_format((float) $total->redondeado(2), 2).'.'
                    : 'Agregado por L '.number_format((float) $total->redondeado(2), 2).'.'
            )
            ->send();

        return true;
    }

    // ── Anular un cargo ───────────────────────────────────────────────

    /**
     * ─────────────────────────────────────────────────────────────────
     * LA ✕ QUITA LA LÍNEA EN EL ACTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes abría un segundo modal encima del de agregar, con un campo
     * «¿qué pasó?» de diez caracteres mínimo. Dos problemas.
     *
     * 🔴 El de fondo: el botón solo aparece en cargos **pendientes** —
     * `EstadoCargo::admiteAnulacionDirecta()` es cierto únicamente ahí—,
     * o sea en algo que todavía no se facturó. Quitar una línea que se
     * tecleó hace diez segundos, en una cuenta abierta, es corregir un
     * error de tipeo; no es enmendar un documento fiscal. Pedir una
     * justificación escrita para eso no produce auditoría: produce
     * «aaaaaaaaaa».
     *
     * ⚠️ Lo que NO se relajó: se sigue llamando al mismo
     * `AnuladorDeCargo`, así que igual queda la anulación con su reversa,
     * el medicamento vuelve al mismo lote del que salió, y el registro
     * dice quién lo quitó y cuándo. Nada se borra — lo único que cambió
     * es que el motivo lo escribe el sistema en vez de la persona.
     *
     * El día que haya una pantalla de cierre donde se quiten cargos de
     * hace tres días, ESA sí tiene que preguntar.
     *
     * ⚠️ Es un método público de Livewire: se puede invocar desde el
     * cliente aunque el botón no exista. Por eso la autorización se
     * verifica acá y no en el Blade.
     */
    public function quitarCargo(int $cargo): void
    {
        abort_unless(Gate::allows('create', Cargo::class), 403);

        $laLinea = $this->cargoDe(['cargo' => $cargo]);

        if (! $laLinea instanceof Cargo) {
            return;
        }

        abort_unless(Gate::allows('cargar', $laLinea->cuenta), 403);

        if (! $this->esDeMiArea($laLinea)) {
            return;
        }

        /*
         * 🔴 EL GUARDIÁN ESTÁ ACÁ, NO EN EL BLADE.
         *
         * El Blade dibuja la ✕ de una o la que abre el campo, pero esto
         * es un método público de Livewire: se puede invocar desde el
         * cliente con el id de un cargo de otro turno y sin motivo
         * ninguno. Una regla que solo vive en la pantalla no es una regla.
         */
        $quienQuita = auth()->id();

        if ($laLinea->pideMotivoParaQuitar(is_int($quienQuita) ? $quienQuita : null)) {
            $this->pedirElMotivo($cargo);

            return;
        }

        $this->anular($laLinea, 'Quitado en el mostrador mientras se armaba la cuenta.');
    }

    /**
     * Abre el campo del motivo debajo del renglón.
     *
     * ⚠️ Es estado de Livewire y no una acción de Filament montada encima
     * del modal. Ese camino ya se probó y no funciona: `mountAction()`
     * desde adentro de una acción montada no abre nada y no da error —
     * el clic simplemente no hace nada.
     */
    public function pedirElMotivo(int $cargo): void
    {
        $laLinea = $this->cargoDe(['cargo' => $cargo]);

        if ($laLinea instanceof Cargo && ! $this->esDeMiArea($laLinea)) {
            return;
        }

        $this->cargoAQuitar = $cargo;
        $this->motivoDeQuitar = '';
    }

    /**
     * Entrega de un tirón lo que falta de un renglón del paquete
     * presupuestado (ADR-0009).
     *
     * ─────────────────────────────────────────────────────────────────
     * UN CLIC, NO UN FORMULARIO
     * ─────────────────────────────────────────────────────────────────
     *
     * El renglón ya dice qué producto, de qué envase y cuánto falta. Que
     * la cajera lo vuelva a teclear es pedirle que copie a mano un dato
     * que el sistema ya tiene — y ahí es donde se teclea 100 en vez de
     * 10, con el paciente enfrente.
     *
     * ⚠️ Es un método de Livewire y NO una acción de Filament: `mountAction()`
     * desde adentro de una acción montada no abre nada y no da error
     * (mismo motivo que `pedirElMotivo()`).
     *
     * 🔴 Entra con `IncluidoEnTarifa`: sale de bodega, descuenta
     * existencia y congela su costo, pero NO se le vuelve a cobrar al
     * paciente — ya está dentro del paquete.
     */
    public function pedirLaCantidad(int $linea): void
    {
        $renglon = PresupuestoLinea::query()->with('presupuesto')->find($linea);

        if (! $renglon instanceof PresupuestoLinea) {
            return;
        }

        $this->lineaAEntregar = $linea;
        $this->paqueteAbierto = true;

        /*
         * Se propone lo que falta, pero se puede bajar: casi nunca se
         * entregan las diez tabletas de una sola vez. Si se dan ocho y el
         * paciente pide el alta, las otras dos nunca salieron de farmacia
         * y no tienen por qué figurar como entregadas.
         */
        $this->cantidadAEntregar = rtrim(rtrim(
            $this->loQueFaltaDe($renglon->presupuesto, $renglon)->redondeado(4),
            '0'
        ), '.');
    }

    /**
     * Abre o cierra el desglose del paquete.
     *
     * ⚠️ Es estado de LIVEWIRE y no un `<details>` del navegador.
     *
     * Se intentó con `<details>` —primero solo, después con `@entangle` y
     * con `wire:key`— y en las tres el nodo se recrea al re-renderizar y
     * la lista se cierra sola. Quien despacha cinco renglones seguidos
     * tenía que reabrirla cinco veces, con el paciente esperando.
     *
     * Es el mismo camino que ya usa `pedirElMotivo()`: el estado del DOM
     * no sobrevive a Livewire; el estado del componente sí.
     */
    public function alternarPaquete(): void
    {
        $this->paqueteAbierto = ! $this->paqueteAbierto;
    }

    public function cancelarEntrega(): void
    {
        $this->lineaAEntregar = null;
        $this->cantidadAEntregar = '';
    }

    public function entregarDelPaquete(int $linea): void
    {
        $renglon = PresupuestoLinea::query()->with(['item', 'presupuesto'])->find($linea);

        if (! $renglon instanceof PresupuestoLinea || ! $renglon->item instanceof Item) {
            return;
        }

        $presupuesto = $renglon->presupuesto;
        $cuenta = $presupuesto->cuentaViva();

        if (! $cuenta instanceof Cuenta) {
            Notification::make()->danger()->title('La cuenta ya no está abierta')->send();

            return;
        }

        abort_unless(Gate::allows('update', $cuenta), 403);

        $pendiente = $this->loQueFaltaDe($presupuesto, $renglon);

        if ($pendiente->esCero() || $pendiente->esNegativo()) {
            Notification::make()->warning()->title('Ese renglón ya se entregó completo')->send();

            return;
        }

        /*
         * Lo que se entrega AHORA: nunca más de lo que falta. Si hiciera
         * falta más, eso ya no está presupuestado y va por el camino
         * normal — como excedente cobrable, que es lo correcto.
         */
        $aEntregar = NumeroDeFormulario::aDecimal($this->cantidadAEntregar) ?? $pendiente;

        if ($aEntregar->esCero() || $aEntregar->esNegativo()) {
            Notification::make()->warning()->title('Poné cuánto se le entrega')->send();

            return;
        }

        if ($pendiente->menorQue($aEntregar)) {
            $aEntregar = $pendiente;
        }

        $almacen = $this->almacenConExistencia($renglon->item, $aEntregar);

        /*
         * Stock infinito: del estante que tenga algo, y lo que no alcance
         * lo carga el motor sin tocar el inventario. Sin estante con nada,
         * va sin almacén (13-oct-2026).
         */
        if (! $almacen instanceof Almacen && $renglon->item->tieneStockInfinito()) {
            $almacen = $this->estanteSugerido($renglon->item);
        }

        if (! $almacen instanceof Almacen && ! $renglon->item->tieneStockInfinito()) {
            $hay = $this->cuantoHayDe($renglon->item);

            Notification::make()
                ->danger()
                ->title('No hay existencia suficiente')
                ->body(
                    "Se piden {$aEntregar->redondeado(2)} de {$renglon->item->nombre} y en los almacenes a los que tenés acceso hay {$hay->redondeado(2)}."
                )
                ->persistent()
                ->send();

            return;
        }

        try {
            app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
                item: $renglon->item,
                cantidad: $aEntregar,
                claveIdempotencia: (string) Str::uuid(),
                almacen: $almacen,
                ocurridoEn: now(),
                presupuestoId: $presupuesto->id,
                presupuestoLineaId: $renglon->id,
                politica: PoliticaCargo::IncluidoEnTarifa,
            ));
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo entregar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->cancelarEntrega();
        $this->paqueteAbierto = true;

        /*
         * El renglón del paquete puede tener que moverse: si la cuenta
         * lleva descuento del hospital, cada medicamento que sale de
         * farmacia le rebaja su parte.
         */
        $this->ponerElPaqueteAlDia($presupuesto);

        $queda = $pendiente->restar($aEntregar);

        Notification::make()
            ->success()
            ->title('Entregado')
            ->body($queda->esCero()
                ? "{$renglon->texto}. Va incluido en el paquete: no se le cobra aparte."
                : "{$renglon->texto}: {$aEntregar->redondeado(2)} entregadas, quedan {$queda->redondeado(2)} sin salir de farmacia.")
            ->send();
    }

    /**
     * Cuánto hay del ítem sumando los almacenes que este usuario puede
     * operar. Para que el aviso diga el número y no solo «no alcanza».
     */
    private function cuantoHayDe(Item $item): Decimal
    {
        $consultor = app(ConsultorDeExistencias::class);
        $total = Decimal::cero();

        /** @var Collection<int, Almacen> $almacenes */
        $almacenes = AlmacenesDelUsuario::elegibles()->get();

        foreach ($almacenes as $almacen) {
            $total = $total->sumar($consultor->totalEn($item, $almacen));
        }

        return $total;
    }

    /**
     * Deshace la última entrega de un renglón del paquete.
     *
     * ─────────────────────────────────────────────────────────────────
     * SE EQUIVOCÓ: PUSO 8 Y SOLO DIO 6
     * ─────────────────────────────────────────────────────────────────
     *
     * Pasa, y hay que poder arreglarlo sin llamar a nadie. Pero **un
     * cargo no se edita** (§9.0.3): se anula, y `AnuladorDeCargo` asienta
     * su reversa y devuelve el medicamento al kardex. Después se vuelve a
     * entregar la cantidad correcta.
     *
     * Los dos movimientos quedan en la bitácora, que es lo que permite
     * responder «¿por qué este lote tiene dos salidas y una devolución?»
     * dentro de tres meses.
     */
    public function deshacerEntrega(int $linea): void
    {
        $renglon = PresupuestoLinea::query()->with('presupuesto')->find($linea);

        if (! $renglon instanceof PresupuestoLinea) {
            return;
        }

        $cuenta = $renglon->presupuesto->cuentaViva();

        if (! $cuenta instanceof Cuenta) {
            Notification::make()->danger()->title('La cuenta ya no está abierta')->send();

            return;
        }

        abort_unless(Gate::allows('update', $cuenta), 403);

        $ultima = Cargo::query()
            ->where('presupuesto_linea_id', $renglon->id)
            ->where('estado', EstadoCargo::Pendiente->value)
            ->orderByDesc('id')
            ->first();

        if (! $ultima instanceof Cargo) {
            Notification::make()
                ->warning()
                ->title('No hay ninguna entrega que deshacer')
                ->body('Las entregas ya facturadas no se anulan acá: eso es nota de crédito.')
                ->send();

            return;
        }

        $this->paqueteAbierto = true;

        try {
            app(AnuladorDeCargo::class)->anular(
                $ultima,
                'Se corrigió la cantidad entregada del paquete presupuestado.'
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo deshacer')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->ponerElPaqueteAlDia($renglon->presupuesto);

        Notification::make()
            ->success()
            ->title('Entrega deshecha')
            ->body("Volvieron {$ultima->cantidad} al inventario. Entregá de nuevo con la cantidad correcta.")
            ->send();
    }

    /**
     * Cuánto falta entregar de un renglón: lo presupuestado menos lo que
     * ya salió. Derivado, nunca una columna (§9.G1).
     */
    private function loQueFaltaDe(Presupuesto $presupuesto, PresupuestoLinea $renglon): Decimal
    {
        foreach ($presupuesto->desglose() as $fila) {
            if ($fila['linea']->id === $renglon->id) {
                return Decimal::de($renglon->cantidad)->restar($fila['consumida']);
            }
        }

        return Decimal::de($renglon->cantidad);
    }

    /**
     * El almacén del que conviene sacar esto, si alcanza en alguno.
     *
     * ⚠️ Recorre los estantes EN ORDEN DE PREFERENCIA, no en el orden en
     * que estén en la tabla. Con farmacia y bodega separadas, «el primero
     * que alcance» despacharía de bodega estando la farmacia surtida — y
     * bodega no dispensa a paciente, traslada. El orden lo pone
     * `estantesDe()`.
     *
     * El LOTE lo sigue eligiendo el motor por FEFO: acá solo se decide de
     * qué estante se saca.
     */
    private function almacenConExistencia(Item $item, Decimal $cantidad): ?Almacen
    {
        foreach ($this->estantesDe($item) as $estante) {
            if (! $estante['hay']->menorQue($cantidad)) {
                return $estante['almacen'];
            }
        }

        return null;
    }

    /**
     * Los estantes del usuario con cuánto hay de este ítem en cada uno,
     * ordenados por cuál conviene tocar primero.
     *
     * ─────────────────────────────────────────────────────────────────
     * UNA SOLA CONSULTA PARA TODOS LOS ALMACENES
     * ─────────────────────────────────────────────────────────────────
     *
     * Preguntar «cuánto hay» almacén por almacén es una consulta por
     * estante, y esto se evalúa en cada pintada del modal —o sea, con
     * cada tecla del buscador—. Se suma agrupado por `almacen_id` de una
     * vez, que es el mismo número con una consulta.
     *
     * ─────────────────────────────────────────────────────────────────
     * EL ORDEN, Y POR QUÉ ES ESE
     * ─────────────────────────────────────────────────────────────────
     *
     *   1. Primero los que TIENEN algo. Un estante en cero no es una
     *      opción, es un error esperando.
     *   2. Después los que DISPENSAN a paciente. Bodega guarda y
     *      traslada; despachar de bodega salta el paso que deja
     *      constancia de que la mercadería bajó.
     *   3. Y entre iguales, el que más tiene: vaciar el estante chico
     *      primero deja a la farmacia sin nada para el siguiente.
     *
     * @return Collection<int, array{almacen: Almacen, hay: Decimal}>
     */
    private function estantesDe(Item $item, ?int $presentacionId = null): Collection
    {
        $memoria = $item->id.':'.($presentacionId ?? 'todas');

        if (isset($this->estantesPorItem[$memoria])) {
            return $this->estantesPorItem[$memoria];
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 CON UN ENVASE ELEGIDO, SOLO LOS ENVASES SIN ABRIR
         * ─────────────────────────────────────────────────────────────
         *
         * Sumar todo y dividir entre lo que trae el envase daba «38
         * FRASCO» con los mililitros de los frascos de 60, de 80 y de
         * 120: un número que no corresponde a ningún frasco del estante y
         * que promete una entrega que el hospital no puede cumplir. La
         * cuenta se hace lote por lote en `sinAbrirPorAlmacen()`.
         *
         * Nulo = sin envase elegido, y ahí sí se suma todo: es el caso
         * del pase de presupuesto y del ítem que se cobra por unidad
         * suelta.
         */
        $saldos = $presentacionId === null
            ? Existencia::query()
                ->where('item_id', $item->id)
                ->selectRaw('almacen_id, sum(cantidad) as total')
                ->groupBy('almacen_id')
                ->pluck('total', 'almacen_id')
                ->all()
            : $this->sinAbrirPorAlmacen($item, $presentacionId);

        /** @var Collection<int, Almacen> $almacenes */
        $almacenes = AlmacenesDelUsuario::elegibles()->vigentes()->orderBy('nombre')->get();

        return $this->estantesPorItem[$memoria] = $almacenes
            ->map(fn (Almacen $almacen): array => [
                'almacen' => $almacen,
                'hay'     => self::comoDecimal($saldos[$almacen->id] ?? null),
            ])
            ->sort(function (array $uno, array $otro): int {
                $tiene = ($otro['hay']->esCero() ? 0 : 1) <=> ($uno['hay']->esCero() ? 0 : 1);

                if ($tiene !== 0) {
                    return $tiene;
                }

                $dispensa = ($otro['almacen']->tipo->dispensaAPaciente() ? 1 : 0)
                    <=> ($uno['almacen']->tipo->dispensaAPaciente() ? 1 : 0);

                return $dispensa !== 0 ? $dispensa : $otro['hay']->comparar($uno['hay']);
            })
            ->values();
    }

    /**
     * Los estantes de quien carga donde está un producto con stock
     * infinito, en el mismo orden que `estantesDe()`.
     *
     * Con un envase sin abrir elegido, solo donde HAY de ese envase: la
     * caja, de donde hay cajas; el blíster, de donde hay blísteres o cajas
     * —la caja los trae—. La unidad suelta sale de cualquiera donde esté.
     *
     * Un producto sin ningún lugar anotado —los que se marcaron antes del
     * 13-oct-2026— sale de cualquiera: antes que no poder cargarlo, se
     * elige y queda anotado de dónde salió.
     *
     * @return Collection<int, array{almacen: Almacen, hay: Decimal}>
     */
    private function estantesDelInfinito(Item $item, ?string $unidadDeCobro = null): Collection
    {
        $ubicador = app(UbicadorDeProducto::class);
        $lugares = $this->lugaresPorItem[$item->id] ??= $ubicador->lugaresDe($item);

        $estantes = $this->estantesDe($item);

        if ($lugares === []) {
            return $estantes;
        }

        $envase = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

        return $estantes
            ->filter(fn (array $estante): bool => array_key_exists((int) $estante['almacen']->id, $lugares)
                && (! $envase instanceof ItemPresentacion
                    || $ubicador->hayEnvase($lugares[(int) $estante['almacen']->id], $envase, $this->presentacionesDe($item))))
            ->values();
    }

    /**
     * Cuánto hay del envase sin abrir en cada estante, en unidades del
     * kardex.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LOTE POR LOTE, Y SOLO LOS ENVASES ENTEROS
     * ─────────────────────────────────────────────────────────────────
     *
     * Dos cajas abiertas con 60 tabletas cada una son 120 tabletas y
     * CERO cajas sin abrir. Sumar primero y dividir después prometía una
     * caja que no está en el estante. Se cuenta cada lote por separado,
     * con la misma regla con la que después se descuenta
     * (`FormasDeCobro::sinAbrirEnElLote()`): de un lote de cajas con 95
     * tabletas salen 9 blísteres y ninguna caja.
     *
     * Entran los lotes del envase y los de uno que lo trae adentro: el
     * BLISTER X 10 se ofrece aunque todo lo que haya sean cajas.
     *
     * @return array<int, string> por almacén
     */
    private function sinAbrirPorAlmacen(Item $item, int $presentacionId): array
    {
        $envase = $this->presentacionDe($item, $presentacionId);

        if (! $envase instanceof ItemPresentacion) {
            return [];
        }

        $formas = app(FormasDeCobro::class);
        $presentaciones = $this->presentacionesDe($item);

        $filas = Existencia::query()
            ->join('lotes', 'existencias.lote_id', '=', 'lotes.id')
            ->where('existencias.item_id', $item->id)
            ->whereIn('lotes.item_presentacion_id', $formas->envasesQueLoTraen($envase, $presentaciones))
            ->conSaldo()
            ->get(['existencias.almacen_id', 'existencias.cantidad', 'lotes.item_presentacion_id']);

        $porAlmacen = [];

        foreach ($filas as $fila) {
            $almacenId = (int) $fila->getAttribute('almacen_id');

            $porAlmacen[$almacenId] = ($porAlmacen[$almacenId] ?? Decimal::cero())->sumar(
                $formas->sinAbrirEnElLote(
                    envase: $envase,
                    envaseDelLote: (int) $fila->getAttribute('item_presentacion_id'),
                    saldo: $fila->cantidadDecimal(),
                    presentaciones: $presentaciones,
                ),
            );
        }

        return array_map(static fn (Decimal $hay): string => $hay->exacto(), $porAlmacen);
    }

    /**
     * ¿Tiene sentido ofrecer «se pidió prestado» en esta línea?
     *
     * Las tres condiciones importan y ninguna sobra:
     *
     *   · el ítem mueve inventario —un honorario no se presta—;
     *   · NO hay existencia en ningún estante, porque ofrecerlo con
     *     producto en el estante enseña a pedir prestado lo que ya está;
     *   · y quien cobra tiene permiso de registrar préstamos. Sin esto,
     *     cualquiera que llegue a la pantalla de cobro puede meter una
     *     entrada de inventario sin factura.
     */
    private function sePuedePedirPrestado(Get $get): bool
    {
        $item = $this->itemDe($get('item_id'));

        return $item instanceof Item
            && $item->mueveInventario()
            && ! $item->tieneStockInfinito()
            && $this->estantesConLoQueHay($item, $this->formaEnUso($get)) === []
            && Gate::allows('create', Prestamo::class);
    }

    /**
     * ¿«Cómo se le agrega», la cantidad y el estante caben en UN renglón?
     *
     * Solo cuando hay que preguntar la forma (más de una) y son pocas:
     * con cuatro envases o más, los botones agrupados no caben en media
     * fila y se cortarían.
     */
    private function laLineaVaEnUnaFila(Get $get): bool
    {
        $item = $this->itemDe($get('item_id'));

        if (! $item instanceof Item || ! $item->mueveInventario()) {
            return false;
        }

        $formas = count($this->unidadesDeCobro($item));

        return $formas > 1 && $formas <= 3;
    }

    /**
     * ¿Ya hay algo que decir de la línea? Hace falta el producto y saber
     * cómo se cobra; antes de eso el recuadro estaría vacío.
     */
    private function hayLecturaDeLaLinea(Get $get): bool
    {
        return $this->itemDe($get('item_id')) instanceof Item
            && $this->formaEnUso($get) !== null;
    }

    /**
     * Los estantes donde puede quedar algo prestado, listos para el
     * desplegable.
     *
     * @see Almacen::scopeQueRecibenPrestamo() para el «nunca vacío»
     *
     * @return array<int, string>
     */
    private static function estantesQueRecibenPrestamo(): array
    {
        /** @var array<int, string> $estantes */
        $estantes = Almacen::query()
            ->vigentes()
            ->queRecibenPrestamo()
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();

        if ($estantes !== []) {
            return $estantes;
        }

        /** @var array<int, string> $todos */
        $todos = Almacen::query()->vigentes()->orderBy('nombre')->pluck('nombre', 'id')->all();

        return $todos;
    }

    /**
     * Cuál viene puesto: el mismo desde el que se está despachando.
     *
     * Es el acierto casi siempre —lo prestado entra y sale en el mismo
     * minuto, del mismo estante— y cuando no lo es, la lista está ahí.
     *
     * ⚠️ Solo si ESE estante puede recibir préstamos. Proponer uno que la
     * lista no ofrece deja el desplegable en blanco con un valor puesto
     * por detrás, que es la forma más silenciosa de guardar el dato
     * equivocado.
     */
    private static function estantePropuestoParaElPrestamo(mixed $delDespacho): ?int
    {
        $estantes = self::estantesQueRecibenPrestamo();

        if (is_numeric($delDespacho) && isset($estantes[(int) $delDespacho])) {
            return (int) $delDespacho;
        }

        return count($estantes) === 1 ? (int) array_key_first($estantes) : null;
    }

    /**
     * Registrar el préstamo sin salir de la línea que se está cobrando.
     *
     * ⚠️ `fillForm` resuelve el ÍTEM REAL y no copia el valor del
     * selector: acá arriba «705» y «705|presentacion:12» son el mismo
     * producto, y el formulario del préstamo necesita el id del ítem, no
     * la cadena compuesta.
     *
     * Al terminar deja `almacen_id` elegido en la línea de afuera. Sin
     * eso, quien cobra registra el préstamo y vuelve a encontrarse el
     * campo en blanco con el paciente esperando.
     */
    private function accionDePedirPrestado(): Action
    {
        return Action::make('pedirPrestado')
            ->label('Se pidió prestado')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('warning')
            /*
             * Contorneado y no lleno: es la salida de una excepción, y el
             * botón lleno de la pantalla tiene que seguir siendo Agregar.
             */
            ->outlined()
            ->modalHeading('¿Quién nos lo prestó?')
            ->modalDescription(
                'Sube la existencia en el acto y queda anotado a quién hay que devolvérselo. Lo que '
                .'trae el médico o la familia del paciente se registra igual, pero no cuenta como deuda.'
            )
            ->modalSubmitActionLabel('Registrar y seguir cobrando')
            ->modalWidth('xl')
            ->fillForm(fn (Get $get): array => [
                'item_id'        => $this->itemDe($get('item_id'))?->id,
                'almacen_id'     => self::estantePropuestoParaElPrestamo($get('almacen_id')),
                'presta_tipo'    => QuienPresta::Farmacia->value,
                'forma_de_saldo' => FormaDeSaldo::DevolverProducto->value,
            ])
            ->schema([
                Hidden::make('item_id'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 SOLO BODEGA O FARMACIA
                 * ─────────────────────────────────────────────────────
                 *
                 * Regla de Mauricio (3-sep-2026): «debe ser solo facturar
                 * o bodega; en caso de que pidan 2, que una se guarde en
                 * bodega como prestado y otra se facture — así tenemos una
                 * en stock disponible y se sabe a quién se le pidió
                 * prestada».
                 *
                 * El desplegable ofrecía TODOS los estantes, incluidos los
                 * de consumo interno —el carro de paro, el dispensario—.
                 * Un préstamo parado ahí es una deuda que no se puede
                 * devolver sin trasladarla primero: el día que llegue la
                 * compra, el aviso va a decir que hay que devolver veinte
                 * tabletas que ya se usaron.
                 *
                 * Y viene puesto el mismo estante desde el que se está
                 * despachando, que es de donde va a salir el cargo en el
                 * mismo minuto. Sobre lo que no se cobre, el kardex se
                 * queda: si prestan 2 y se cobra 1, queda 1 disponible y
                 * el préstamo dice a quién se le debe.
                 */
                Select::make('almacen_id')
                    ->label('A qué estante entra')
                    ->required()
                    ->native(false)
                    ->options(fn (): array => self::estantesQueRecibenPrestamo())
                    ->helperText(
                        'Bodega o farmacia: donde queda físicamente, y de donde va a salir cuando se '
                        .'devuelva. Lo que no se cobre en esta cuenta queda ahí en stock, anotado a '
                        .'nombre de quien lo prestó.'
                    ),

                TextInput::make('cantidad')
                    ->label('Cuánto prestaron')
                    ->required()
                    ->rule('regex:/^\d{1,10}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta cuatro decimales.',
                    ])
                    /*
                     * NO se prellena con la cantidad de la línea, y es a
                     * propósito: la línea puede estar en unidad de cobro
                     * —una caja— y el kardex va en la mínima. Un número
                     * heredado de otra unidad se acepta sin mirarlo.
                     */
                    ->helperText('En la unidad del kardex: si prestaron una caja de 100, van 100.'),

                Select::make('lote_id')
                    ->label('Lote')
                    ->visible(fn (Get $get): bool => $this->exigeLoteElItem($get('item_id')))
                    ->required(fn (Get $get): bool => $this->exigeLoteElItem($get('item_id')))
                    ->searchable()
                    ->options(fn (Get $get): array => Lote::query()
                        ->where('item_id', $get('item_id'))
                        ->orderByDesc('fecha_vencimiento')
                        ->pluck('numero', 'id')
                        ->all())
                    ->helperText('El de la caja que prestaron. Si no está en la lista, cargalo acá mismo.')
                    ->createOptionForm([
                        CampoMayusculas::make('numero')->label('Número de lote')->required()->maxLength(60),
                        DatePicker::make('fecha_vencimiento')->label('Vence')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->createOptionUsing(function (array $data, Get $get): ?int {
                        $itemId = $get('item_id');

                        if (! is_numeric($itemId)) {
                            return null;
                        }

                        return Lote::query()->create([
                            'item_id'           => (int) $itemId,
                            'numero'            => $data['numero'],
                            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
                        ])->id;
                    }),

                Select::make('presta_tipo')
                    ->label('Quién prestó')
                    ->required()
                    ->native(false)
                    ->options(QuienPresta::opciones())
                    ->live()
                    ->helperText(fn (Get $get): string => is_string($get('presta_tipo'))
                        && QuienPresta::tryFrom($get('presta_tipo')) instanceof QuienPresta
                            ? QuienPresta::from($get('presta_tipo'))->ayuda()
                            : 'De quién salió lo que no había.'),

                CampoMayusculas::make('presta_nombre')
                    ->label('Nombre')
                    ->required()
                    ->minLength(3)
                    ->maxLength(160)
                    ->placeholder('FARMACIA SAN JOSE')
                    ->helperText('Sin esto no se le puede devolver a nadie.'),

                TextInput::make('presta_telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(40),

                Select::make('forma_de_saldo')
                    ->label('Cómo se le paga')
                    ->required()
                    ->native(false)
                    ->options(FormaDeSaldo::opciones())
                    ->live(),

                TextInput::make('monto_acordado')
                    ->label('Cuánto se le va a pagar')
                    ->prefix('L')
                    ->visible(fn (Get $get): bool => $get('forma_de_saldo') === FormaDeSaldo::Pagar->value)
                    ->required(fn (Get $get): bool => $get('forma_de_saldo') === FormaDeSaldo::Pagar->value)
                    ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta dos decimales.',
                    ]),

                CampoMayusculas::area('motivo')
                    ->label('Por qué se pidió')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('No había existencia y el paciente lo necesitaba en el momento.'),
            ])
            ->action(function (array $data, Set $set): void {
                $idDelItem = $data['item_id'] ?? null;
                $idDelAlmacen = $data['almacen_id'] ?? null;
                $idDelLote = $data['lote_id'] ?? null;

                $item = is_numeric($idDelItem) ? Item::query()->find((int) $idDelItem) : null;
                $almacen = is_numeric($idDelAlmacen) ? Almacen::query()->find((int) $idDelAlmacen) : null;
                $lote = is_numeric($idDelLote) ? Lote::query()->find((int) $idDelLote) : null;

                $cantidad = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

                if (! $item instanceof Item || ! $almacen instanceof Almacen || ! $cantidad instanceof Decimal) {
                    Notification::make()->danger()->title('Faltan datos del préstamo')->send();

                    return;
                }

                $forma = FormaDeSaldo::from((string) $data['forma_de_saldo']);

                try {
                    $prestamo = app(RegistradorDePrestamo::class)->registrar(
                        item: $item,
                        almacen: $almacen,
                        cantidad: $cantidad,
                        quienPresta: QuienPresta::from((string) $data['presta_tipo']),
                        nombreDeQuienPresta: (string) $data['presta_nombre'],
                        forma: $forma,
                        lote: $lote,
                        montoAcordado: $forma === FormaDeSaldo::Pagar
                            ? NumeroDeFormulario::aDecimal($data['monto_acordado'] ?? null)
                            : null,
                        telefono: self::textoONulo($data['presta_telefono'] ?? null),
                        cuenta: $this->cuentaEnCurso(),
                        motivo: self::textoONulo($data['motivo'] ?? null),
                    );
                } catch (PrestamoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo registrar el préstamo')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                /*
                 * El estante queda elegido: es el que acaba de recibir lo
                 * prestado, y sin esto quien cobra vuelve a encontrarse el
                 * campo en blanco con el paciente esperando.
                 */
                $set('almacen_id', (string) $almacen->id);

                Notification::make()
                    ->success()
                    ->title('Préstamo registrado')
                    ->body($prestamo->seDebe()
                        ? "Ya podés cobrarlo. Se le deben {$prestamo->saldoPendiente()->redondeado(2)} a {$prestamo->presta_nombre}."
                        : 'Ya podés cobrarlo. No genera deuda: lo trajo el paciente.')
                    ->send();
            });
    }

    /** ¿Este ítem exige lote? ARSA lo obliga en medicamentos. */
    private function exigeLoteElItem(mixed $itemId): bool
    {
        if (! is_numeric($itemId)) {
            return false;
        }

        $item = Item::query()->find((int) $itemId);

        return $item instanceof Item && $item->requiere_lote;
    }

    private static function textoONulo(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Las opciones del desplegable, cada una diciendo cuánto hay.
     *
     * @return array<int, string>
     */
    private function estantesConLoQueHay(?Item $item, ?string $unidadDeCobro = null): array
    {
        if (! $item instanceof Item) {
            return [];
        }

        /*
         * Stock infinito: los almacenes donde ESTÁ, porque no se descuenta
         * de ninguno pero sí se sabe dónde hay (13-oct-2026). Se elige
         * igual para que quede «de dónde se sacó» en el reporte de
         * consumo.
         */
        if ($item->tieneStockInfinito()) {
            return $this->estantesDelInfinito($item, $unidadDeCobro)
                ->mapWithKeys(fn (array $estante): array => [
                    $estante['almacen']->id => $estante['almacen']->nombre.' · stock infinito',
                ])
                ->all();
        }

        $presentacion = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

        /*
         * Todo lo que hay en cada estante, para decir también lo suelto:
         * «9 BLISTER + 5 TAB». Es la misma consulta memorizada que usa
         * el resto de la pantalla, así que no cuesta otra.
         */
        $todo = [];

        if ($presentacion instanceof ItemPresentacion) {
            foreach ($this->estantesDe($item) as $estante) {
                $todo[$estante['almacen']->id] = $estante['hay'];
            }
        }

        return $this->estantesDe($item, $presentacion?->id)
            /*
             * 🔴 LOS ESTANTES VACÍOS NO SE LISTAN.
             *
             * Un almacén sin existencia de este producto no es una
             * opción: elegirlo termina en «no alcanza» después de haber
             * tecleado todo, con el paciente enfrente. Y con carritos, la
             * lista se llenaría de renglones que nunca sirven —un carro
             * de paro tiene ocho productos, no el catálogo entero—.
             *
             * Si no queda ninguno, el desplegable sale vacío y el texto
             * de abajo lo dice con todas las letras. Eso es lo correcto:
             * el problema no es la pantalla, es que no hay.
             */
            ->reject(fn (array $estante): bool => $estante['hay']->esCero())
            ->mapWithKeys(fn (array $estante): array => [
                $estante['almacen']->id => $estante['almacen']->nombre
                    .' · '.$this->existenciaComoSeCobra(
                        $item,
                        $estante['hay'],
                        $unidadDeCobro,
                        $todo[$estante['almacen']->id] ?? null,
                    ),
            ])
            ->all();
    }

    /**
     * Cuánto hay, dicho en la unidad en la que se está cobrando.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 «2300 ML» NO ES UNA RESPUESTA ÚTIL SI SE COBRA POR FRASCO
     * ─────────────────────────────────────────────────────────────────
     *
     * Quien está por cobrar dos frascos necesita saber si hay dos
     * frascos. Mostrarle 2300 mililitros lo obliga a dividir de cabeza
     * —entre 60, entre 80 o entre 120 según el envase— con el paciente
     * enfrente, y esa división mal hecha es una promesa de entrega que el
     * estante no puede cumplir.
     *
     * El kardex sigue en mililitros. Esto es solamente cómo se LEE.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LOS ENVASES ENTEROS Y LO SUELTO, LAS DOS COSAS (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * `$hay` son solo los envases sin abrir. Una caja con 95 tabletas,
     * cobrando por blíster, decía «9 BLISTER» y las 5 del blíster roto
     * desaparecían de la lectura. Mauricio: «exacto sería 9 blíster y 5
     * tabletas». Con `$todo` —lo que hay en el estante— se dice lo
     * suelto también: «9 BLISTER + 5 TAB».
     */
    private function existenciaComoSeCobra(
        Item $item,
        Decimal $hay,
        ?string $unidadDeCobro,
        ?Decimal $todo = null,
    ): string {
        $presentacion = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

        if ($presentacion instanceof ItemPresentacion) {
            $contenido = $presentacion->unidades_por_presentacion;

            if (is_numeric($contenido) && ! Decimal::de($contenido)->esCero()) {
                /*
                 * `->` y no `?->`: `item_presentaciones.unidad_id` es NOT
                 * NULL —una presentación sin envase no es una
                 * presentación—, así que la relación siempre trae fila.
                 * El `?->` no protegía de nada y obligaba a inventar un
                 * «envases» que no puede ocurrir.
                 */
                $envases = self::cifraCorta($hay->entre($contenido)).' '.$presentacion->unidad->codigo;
                $suelto = $todo instanceof Decimal ? $todo->restar($hay) : null;

                return $suelto instanceof Decimal && $suelto->mayorQue('0')
                    ? rtrim($envases.' + '.self::cifraCorta($suelto).' '.($item->unidadDispensacion->codigo ?? ''))
                    : $envases;
            }
        }

        $unidad = $item->unidadDispensacion?->codigo;

        return self::cifraCorta($hay).($unidad === null ? '' : ' '.$unidad);
    }

    /**
     * «38» y no «38.33»; «0.08» y no «0».
     *
     * Los decimales de una existencia grande son ruido —a nadie le
     * cambia la decisión que haya 38 o 38.33 frascos— y alargan la
     * opción hasta partirla en dos renglones. Por debajo de diez sí se
     * conservan: ahí la diferencia entre 0.08 y 0 es entre tener algo y
     * no tener nada.
     */
    private static function cifraCorta(Decimal $cantidad): string
    {
        return $cantidad->menorQue('10')
            ? ItemPresentacion::sinCerosDeMas($cantidad->redondeado(2))
            : $cantidad->redondeado(0);
    }

    /**
     * La presentación detrás de una clave del selector, o nulo cuando lo
     * elegido no es un envase —la unidad suelta, la fracción—.
     */
    private function presentacionDeLaUnidad(Item $item, ?string $unidad): ?ItemPresentacion
    {
        if ($unidad === null || ! str_starts_with($unidad, self::POR_PRESENTACION)) {
            return null;
        }

        $id = (int) mb_substr($unidad, mb_strlen(self::POR_PRESENTACION));

        $presentacion = $this->presentacionesDe($item)->firstWhere('id', $id);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    /**
     * Deja listo lo que se puede deducir del producto elegido: cómo se
     * agrega —si hay una sola forma— y de qué estante sale.
     *
     * Se llama desde todos los caminos por los que queda elegido un
     * producto —la pistola, la gaveta con un solo producto, la búsqueda
     * con un solo resultado y el botón de la lista—, porque un `$set()`
     * desde el código NO dispara los `afterStateUpdated` de los campos.
     * Sin esto, escanear cargaba el producto y dejaba «¿de dónde sale?»
     * en blanco, que es justo el campo que se quería ahorrar.
     */
    private function prepararLaLinea(mixed $valor, Set $set): void
    {
        $item = $this->itemDe($valor);

        /*
         * 🔴 LA FORMA PRIMERO, Y DESPUÉS EL ESTANTE.
         *
         * Cuántas cajas hay en cada estante depende de si se vende la
         * caja o la tableta, así que el estante se sugiere con la forma
         * ya resuelta y no con la que quedó del producto anterior.
         *
         * La forma queda puesta solo cuando hay una sola. La caja sin
         * abrir NUNCA viene marcada sola: pasar la pistola por una caja
         * de cien no es venderla.
         */
        $forma = $item instanceof Item ? $this->formaUnicaDe($item) : null;

        $set('unidad_cobro', $forma);
        $set('almacen_id', $this->estanteSugerido($item, $forma)?->id);

        /*
         * El honorario arranca con el precio del tarifario a la vista.
         * Vacío obligaría a teclear el número entero incluso cuando es el
         * de siempre, y eso convierte un campo de excepción en un campo
         * obligatorio.
         */
        $set('precio_acordado', $this->esHonorario($item) ? $this->precioPropuesto($item) : null);

        /*
         * 🔴 El médico se limpia SIEMPRE al cambiar de producto. Dejarlo
         * pegado le atribuiría al cirujano anterior el honorario del
         * anestesiólogo, y esos renglones son los que después se
         * liquidan.
         */
        $set('medico_id', null);
        $set('referencia_acordada', null);
    }

    /**
     * El precio tecleado para un honorario, solo cuando difiere del que
     * propone el tarifario.
     *
     * @param array<string, mixed> $data
     */
    private function honorarioAcordado(Item $item, Cuenta $cuenta, array $data): ?Monto
    {
        if (! $this->esHonorario($item)) {
            return null;
        }

        $tecleado = NumeroDeFormulario::aDecimal($data['precio_acordado'] ?? null);

        if (! $tecleado instanceof Decimal) {
            return null;
        }

        /*
         * ⚠️ Se compara contra el precio de ESTA cuenta —el de su
         * pagador—, no contra el de lista. Un paciente de seguro tiene
         * otro número, y comparar contra el de lista marcaría como
         * negociado un honorario que salió tal cual del convenio.
         */
        $this->cuentaDelFormulario ??= $cuenta->id;

        $medico = $this->medicoDe($data['medico_id'] ?? null);

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL PRECIO DEL MÉDICO SIEMPRE VIAJA COMO ACORDADO
         * ─────────────────────────────────────────────────────────────
         *
         * La lista del doctor NO está en el tarifario, así que devolver
         * nulo acá —«coincide con lo propuesto, que lo resuelva el
         * motor»— haría que el motor cobrara el precio de lista del
         * hospital y no los L 500 del doctor Carlos. El número tiene que
         * ir, aunque nadie lo haya cambiado.
         */
        if ($medico instanceof Medico && $this->loQueCobraElMedico($item, $medico) instanceof Monto) {
            return Monto::de($tecleado->redondeado(2));
        }

        $propuesto = $this->precioPropuesto($item);

        if ($propuesto !== null && $tecleado->igualA($propuesto)) {
            return null;
        }

        return Monto::de($tecleado->redondeado(2));
    }

    /**
     * De quién es el honorario, si se escribió. Va al renglón de la
     * cuenta y a la factura, que es donde el paciente lo lee.
     *
     * @param array<string, mixed> $data
     */
    private function deQuienEsElHonorario(Item $item, array $data): ?string
    {
        if (! $this->esHonorario($item)) {
            return null;
        }

        /*
         * Sale del médico elegido: «CARLOS PINEDA · CIRUGIA GENERAL». El
         * texto libre sigue aceptándose para el llamador programático y
         * para los cargos viejos, pero en la pantalla ya no se teclea.
         */
        $medico = $this->medicoDe($data['medico_id'] ?? null);

        if ($medico instanceof Medico) {
            return mb_substr($medico->etiqueta(), 0, 120);
        }

        $texto = is_string($data['referencia_acordada'] ?? null)
            ? trim($data['referencia_acordada'])
            : '';

        return $texto === '' ? null : mb_substr($texto, 0, 120);
    }

    /**
     * El médico detrás del valor del selector.
     *
     * Memoriza: se pregunta en el texto de ayuda del precio, o sea con
     * cada tecla del formulario.
     */
    private function medicoDe(mixed $valor): ?Medico
    {
        $id = $this->idDelMedico($valor);

        if ($id === null) {
            return null;
        }

        if (array_key_exists($id, $this->medicosPorId)) {
            return $this->medicosPorId[$id];
        }

        $medico = Medico::query()->with('especialidad')->find($id);

        return $this->medicosPorId[$id] = $medico instanceof Medico ? $medico : null;
    }

    private function idDelMedico(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * Lo que ESE médico cobra por ESE honorario, o nulo si no tiene lista
     * propia y hay que cobrar el precio del tarifario.
     */
    private function loQueCobraElMedico(?Item $item, ?Medico $medico): ?Monto
    {
        if (! $item instanceof Item || ! $medico instanceof Medico) {
            return null;
        }

        /*
         * 🔴 CON EL PAGADOR DE ESTA CUENTA. El doctor no le cobra lo
         * mismo al paciente de la calle que al del Hospital Militar o al
         * de PALIG; preguntar sin convenio devolvería siempre su precio
         * general y le cobraría de más al asegurado.
         */
        return app(ResolutorDeHonorario::class)->para($medico, $item, $this->pagadorDeLaCuenta());
    }

    /**
     * El convenio de la cuenta que se está cobrando.
     *
     * Memoriza: se pregunta en cada pintada del modal, y son dos
     * consultas —la cuenta y su pagador—.
     */
    private function pagadorDeLaCuenta(): ?Convenio
    {
        $cuenta = $this->cuentaEnCurso();

        return $cuenta instanceof Cuenta ? $cuenta->convenio : null;
    }

    /**
     * La cuenta del formulario, ya con su pagador y su sede.
     */
    private function cuentaEnCurso(): ?Cuenta
    {
        if ($this->cuentaDelFormulario === null) {
            return null;
        }

        if ($this->cuentaLeida instanceof Cuenta
            && (int) $this->cuentaLeida->getKey() === $this->cuentaDelFormulario) {
            return $this->cuentaLeida;
        }

        $cuenta = Cuenta::query()->with(['convenio', 'sede'])->find($this->cuentaDelFormulario);

        return $this->cuentaLeida = $cuenta instanceof Cuenta ? $cuenta : null;
    }

    /**
     * Los médicos vigentes para el selector, con su especialidad.
     *
     * @return array<int, string>
     */
    private function medicosVigentes(?string $termino = null): array
    {
        $limpio = trim((string) $termino);

        return Medico::query()
            ->with('especialidad')
            ->vigentes()
            ->when(
                $limpio !== '',
                fn (Builder $consulta): Builder => $consulta->where(
                    fn (Builder $filtro): Builder => $filtro
                        ->where('nombre', 'ilike', '%'.$limpio.'%')
                        ->orWhere('colegiacion', 'ilike', '%'.$limpio.'%')
                        ->orWhereHas(
                            'especialidad',
                            fn (Builder $especialidad): Builder => $especialidad->where('nombre', 'ilike', '%'.$limpio.'%'),
                        ),
                ),
            )
            ->orderBy('nombre')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Medico $medico): array => [(int) $medico->getKey() => $medico->etiqueta()])
            ->all();
    }

    /**
     * El alta rápida del médico, desde el mismo modal del cargo.
     *
     * Pide lo mínimo con lo que un honorario queda bien atribuido; el
     * resto —colegiación completa, teléfono, sus precios— se completa
     * después en la ficha.
     *
     * @return array<int, mixed>
     */
    private static function altaRapidaDeMedico(): array
    {
        return [
            CampoMayusculas::make('nombre')
                ->label('Nombre completo')
                ->required()
                ->maxLength(255),

            Select::make('especialidad_id')
                ->label('Especialidad')
                ->required()
                ->native(false)
                ->options(fn (): array => Especialidad::query()
                    ->vigentes()
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all()),

            CampoMayusculas::make('colegiacion')
                ->label('Colegiación')
                ->maxLength(30)
                ->helperText('Se puede completar después.'),
        ];
    }

    /**
     * «ML», «TAB», «AMP» — el código corto de la unidad en que se
     * dispensa el ítem, o nulo si el ítem no tiene unidad declarada.
     *
     * ⚠️ Existe para NO escribir `$item->unidadDispensacion?->codigo ??
     * …`. El analizador rechaza un nullsafe a la izquierda de `??`, y la
     * alternativa —quitarle el `?`— sería un error fatal el día que
     * alguien cargue un servicio sin unidad de dispensación, que la
     * columna permite. La comprobación explícita satisface a los dos.
     */
    private static function codigoDeLaUnidad(Item $item): ?string
    {
        $unidad = $item->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : null;
    }

    /**
     * ¿Es un honorario médico?
     *
     * La única familia del catálogo cuyo precio se puede escribir en el
     * mostrador. Se pregunta por el TIPO y no por la categoría: la
     * categoría la elige quien carga el catálogo y puede equivocarse; el
     * tipo gobierna el comportamiento del ítem en todo el sistema.
     */
    private function esHonorario(?Item $item): bool
    {
        return $item instanceof Item && $item->tipo === TipoItem::Honorario;
    }

    /**
     * El precio que el tarifario le da a este ítem para el pagador de la
     * cuenta abierta. Nulo si no hay precio: ahí el campo queda vacío y
     * quien cobra escribe el número, que es mejor que proponerle un cero.
     */
    private function precioPropuesto(?Item $item, ?int $medicoId = null): ?string
    {
        if (! $item instanceof Item || $this->cuentaDelFormulario === null) {
            return null;
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 MANDA LA LISTA DEL MÉDICO SOBRE EL TARIFARIO
         * ─────────────────────────────────────────────────────────────
         *
         * «CONSULTA EXTERNA MEDICINA INTERNA» es un solo ítem del
         * catálogo, pero el doctor Carlos cobra L 500 y el doctor Juan L
         * 100: el precio no es del servicio, es de quien lo presta. Si
         * ese médico tiene lista propia, es la suya la que se propone.
         *
         * Sin lista propia se cae al tarifario, y eso es lo normal: solo
         * unos pocos doctores negociaron tarifa.
         */
        if ($medicoId !== null) {
            $pagador = $this->pagadorDeLaCuenta();

            $suyo = app(ResolutorDeHonorario::class)->paraId(
                $medicoId,
                (int) $item->getKey(),
                $pagador instanceof Convenio ? (int) $pagador->getKey() : null,
            );

            if ($suyo instanceof Monto) {
                return $suyo->valor();
            }
        }

        /*
         * Memoria por pintada. El precio se pregunta al proponerlo Y en
         * cada evaluación del texto de ayuda, o sea con cada tecla del
         * buscador — y resolverlo son dos consultas: la cuenta con su
         * pagador, y el tarifario vigente. El §13.2 otra vez.
         *
         * `array_key_exists` y no `isset`: el valor legítimo es NULL
         * cuando el honorario no tiene precio de lista, y con `isset` ese
         * caso volvería a consultar cada vez — justo el que más se repite
         * en los honorarios que cambian con cada médico.
         */
        /*
         * La memoria es del precio DE TARIFARIO —el que no depende del
         * médico—. El del doctor ya se resolvió arriba y lo memoriza
         * `ResolutorDeHonorario`.
         */
        if (array_key_exists($item->id, $this->precioPropuestoPorItem)) {
            return $this->precioPropuestoPorItem[$item->id];
        }

        $cuenta = $this->cuentaEnCurso();

        if (! $cuenta instanceof Cuenta) {
            return null;
        }

        try {
            return $this->precioPropuestoPorItem[$item->id] = app(ResolutorDePrecio::class)->para(
                item: $item,
                convenio: $cuenta->convenio,
                fechaServicio: now(),
                sede: $cuenta->sede,
                /*
                 * `valor()` y no `exacto()`: es lo que se va a mostrar en un
                 * campo y lo que después se compara contra lo tecleado. La
                 * escala 12 de `exacto()` pondría «1080.000000000000» en la
                 * pantalla y nunca coincidiría con lo que alguien escribe.
                 */
            )->precio->valor();
        } catch (PrecioNoDefinidoException) {
            /*
             * Sin precio de lista NO es un error: hay honorarios que no
             * tienen tarifa porque cambian con cada médico. El campo
             * queda vacío y el texto de abajo lo explica.
             */
            return $this->precioPropuestoPorItem[$item->id] = null;
        }
    }

    /**
     * El estante que viene marcado solo. Nulo cuando no hay ninguno con
     * existencia: ahí es mejor que el campo quede vacío y obligue a
     * elegir que preseleccionar un estante donde no hay nada.
     *
     * ⚠️ RECIBE LA UNIDAD DE COBRO A PROPÓSITO. El estante sugerido tiene
     * que ser uno de los que el desplegable va a listar, y el desplegable
     * lista por envase: si el frasco de 120 solo está en bodega, sugerir
     * farmacia —que tiene los de 60— deja el campo con un valor que ya no
     * es opción, y el usuario ve el desplegable en blanco sin saber que
     * hay algo elegido debajo.
     */
    private function estanteSugerido(?Item $item, ?string $unidadDeCobro = null): ?Almacen
    {
        if (! $item instanceof Item || ! $item->mueveInventario()) {
            return null;
        }

        /*
         * Stock infinito: el primero de los almacenes donde está, en el
         * orden de preferencia —farmacia antes que bodega—.
         */
        if ($item->tieneStockInfinito()) {
            return $this->estantesDelInfinito($item, $unidadDeCobro)->first()['almacen'] ?? null;
        }

        $presentacion = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

        $primero = $this->estantesDe($item, $presentacion?->id)->first();

        return $primero !== null && ! $primero['hay']->esCero() ? $primero['almacen'] : null;
    }

    /**
     * El estante es obligatorio para lo que se almacena. También con stock
     * infinito —es «de dónde se sacó» en el reporte de consumo—, salvo que
     * quien carga no tenga ningún estante: ahí se carga sin almacén antes
     * que no poder cargar (13-oct-2026).
     */
    private function exigeEstante(?Item $item): bool
    {
        if (! $item instanceof Item || ! $item->mueveInventario()) {
            return false;
        }

        return ! $item->tieneStockInfinito() || $this->estantesConLoQueHay($item) !== [];
    }

    /**
     * Y se muestra para lo que se almacena, salvo un stock infinito sin
     * ningún estante a la vista: un desplegable vacío diciendo «no hay»
     * haría creer que no se puede cargar.
     */
    private function muestraEstante(?Item $item, ?string $forma): bool
    {
        if (! $item instanceof Item || ! $item->mueveInventario()) {
            return false;
        }

        return ! $item->tieneStockInfinito() || $this->estantesConLoQueHay($item, $forma) !== [];
    }

    /**
     * `sum()` devuelve lo que le da el driver —int, float o string— y
     * ninguna de las tres se le puede pasar directo a `Decimal`, que
     * rechaza el punto flotante a propósito (§8.6.2).
     */
    private static function comoDecimal(mixed $suma): Decimal
    {
        if (is_string($suma) && $suma !== '') {
            return Decimal::de($suma);
        }

        if (is_int($suma)) {
            return Decimal::de((string) $suma);
        }

        if (is_float($suma)) {
            return Decimal::de(number_format($suma, 4, '.', ''));
        }

        return Decimal::cero();
    }

    public function cancelarQuitar(): void
    {
        $this->cargoAQuitar = null;
        $this->motivoDeQuitar = '';
    }

    /**
     * Quita la línea con el motivo que se escribió.
     *
     * El mínimo de diez caracteres lo impone `AnuladorDeCargo` y no se
     * repite acá: si esta pantalla tuviera su propio mínimo, el día que
     * cambie uno quedarían dos verdades. Lo que sí hace acá es traducir
     * la excepción a algo que se pueda leer entre dos pacientes.
     */
    public function quitarConMotivo(): void
    {
        abort_unless(Gate::allows('create', Cargo::class), 403);

        if ($this->cargoAQuitar === null) {
            return;
        }

        $laLinea = $this->cargoDe(['cargo' => $this->cargoAQuitar]);

        if (! $laLinea instanceof Cargo) {
            $this->cancelarQuitar();

            return;
        }

        abort_unless(Gate::allows('cargar', $laLinea->cuenta), 403);

        if (! $this->esDeMiArea($laLinea)) {
            $this->cancelarQuitar();

            return;
        }

        if ($this->anular($laLinea, trim($this->motivoDeQuitar))) {
            $this->cancelarQuitar();
        }
    }

    /**
     * ¿La línea es de algo que esta persona puede cargar? (21-oct-2026)
     *
     * Quien carga puede corregir lo que cargó (`MatrizDePermisosSeeder`),
     * pero dentro de su área: laboratorio quita un examen mal puesto, no
     * la noche de hospitalización que cargó caja. La regla es la misma
     * que al agregar —`CatalogoDelRol`—, y para caja, admisión y
     * enfermería no cambia nada: no tienen restricción.
     */
    private function esDeMiArea(Cargo $cargo): bool
    {
        $item = Item::query()->find($cargo->item_id);

        if (! $item instanceof Item || CatalogoDelRol::puedeCargar($item)) {
            return true;
        }

        Notification::make()
            ->danger()
            ->title('Esa línea es de otra área')
            ->body("{$item->nombre} no se quita desde tu área: lo corrige caja o el área que lo cargó.")
            ->persistent()
            ->send();

        return false;
    }

    /**
     * El único camino por el que esta pantalla anula.
     *
     * Devuelve si salió bien, para que quien llame decida si cierra el
     * campo del motivo o lo deja abierto con lo que la persona ya
     * escribió — perderle el texto porque el motivo era corto es la forma
     * de que la segunda vez escriba «aaaaaaaaaa».
     */
    private function anular(Cargo $cargo, string $motivo): bool
    {
        try {
            app(AnuladorDeCargo::class)->anular($cargo, $motivo);
        } catch (CargoException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo quitar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        }

        $item = Item::query()->find($cargo->item_id);
        $cuenta = Cuenta::query()->find($cargo->cuenta_id);

        if ($item instanceof Item && $cuenta instanceof Cuenta
            && ! $this->soltarElDescuentoSiQuedoVacio($cuenta, $item)) {
            $this->repartirDeNuevoElRubroDe($cuenta, $item);
        }

        Notification::make()
            ->success()
            ->title('Línea quitada')
            ->body('Quedó la reversa, con quién la quitó y por qué. Si movió inventario, la '
                .'existencia volvió a su lote.')
            ->send();

        return true;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * DE QUÉ SE LE ESTÁ ATENDIENDO
     * ─────────────────────────────────────────────────────────────────
     *
     * El sistema ya sabe QUÉ se le cobró y QUIÉN se lo dio. Esto es lo
     * que faltaba: POR QUÉ. Sin diagnóstico la aseguradora no procesa el
     * reclamo, el Art. 180 del Código de Salud queda sin cumplirse, y el
     * hospital no puede contestar de qué atiende.
     *
     * ⚠️ Cuelga del ENCUENTRO, no de la cuenta. La cuenta es el documento
     * de cobro; el diagnóstico se consulta veinte años después, cuando la
     * factura ya se pagó y se archivó.
     */
    /**
     * ─────────────────────────────────────────────────────────────────
     * RECIBIR PLATA A CUENTA
     * ─────────────────────────────────────────────────────────────────
     *
     * El paciente está internado, la cuenta va creciendo y la familia
     * deja L 5,000 hoy y L 3,000 mañana. Esto es eso, y por eso vive
     * ACÁ y no en una pantalla de caja aparte: el saldo está en esta
     * pantalla, y quien recibe la plata está mirando este número.
     *
     * 🔴 EXIGE TURNO DE CAJA ABIERTO. No es burocracia: el efectivo entra
     * a una gaveta que alguien cuenta al final del turno. El servicio lo
     * verifica y el mensaje dice qué hacer.
     *
     * El repetidor de formas de pago ES el pago mixto: «una parte en
     * tarjeta y otra en efectivo» son dos renglones del mismo recibo, no
     * una forma de pago llamada «mixto».
     */
    /**
     * La cuenta a la que se le está por abonar.
     *
     * 🔴 EXISTE PORQUE `$arguments` NO LLEGA A LOS CAMPOS.
     *
     * Filament inyecta los argumentos de la acción en SUS closures
     * —`modalHeading`, `action`— pero no en los de un componente del
     * formulario: ahí adentro `$arguments` no se puede resolver y el
     * contenedor tira `BindingResolutionException` en la cara del
     * usuario. Los tres números del encabezado y el total en vivo leen
     * esta propiedad, que se pone al abrir el modal.
     */
    /**
     * Lo que se escribió en el campo de escaneo cuando no era un código.
     *
     * Mientras tenga valor, los resultados se ofrecen como botones
     * debajo del mismo campo: quien está en el mostrador escribe
     * «acetamin» y toca el que es, sin volver a teclear en otro lado.
     */
    public ?string $busquedaEscrita = null;

    public ?int $cuentaDelAbono = null;

    /**
     * Los recibos de la cuenta del modal, leídos una vez por petición: el
     * título de la sección, su visibilidad y la lista los piden los tres.
     * Privado a propósito —Livewire no lo guarda entre peticiones—, y se
     * vacía al anular o corregir para que la lista no muestre lo de antes.
     *
     * @var array{cuenta: int, filas: list<array{id: int, numero: string, cuando: string, medios: string, total: string, quien: string, entrego: string|null, nota: string|null, anulado: bool, motivo: string|null, tocable: bool}>}|null
     */
    private ?array $recibosLeidos = null;

    /**
     * Abre el modal de abono para una cuenta. Se llama desde la tarjeta
     * en vez de `mountAction` directo para dejar la cuenta anotada antes
     * de que el formulario se arme.
     */
    public function prepararAbono(int $cuenta): void
    {
        $this->cuentaDelAbono = $cuenta;

        $this->mountAction('abonar', ['cuenta' => $cuenta]);
    }

    /**
     * La cuenta del modal abierto, releída para que los totales estén al
     * día aunque se haya cargado algo mientras el modal estaba abierto.
     */
    private function cuentaAbonando(): ?Cuenta
    {
        return $this->cuentaDelAbono === null
            ? null
            : Cuenta::query()->find($this->cuentaDelAbono);
    }

    /**
     * La cuenta que se está por facturar. Misma razón que
     * `$cuentaDelAbono`: `$arguments` no llega a los campos.
     */
    public ?int $cuentaAFacturar = null;

    public function prepararFactura(int $cuenta): void
    {
        $this->cuentaAFacturar = $cuenta;

        $this->mountAction('facturar', ['cuenta' => $cuenta]);
    }

    /**
     * Los documentos que sirven para identificar al cliente en la
     * factura. El carnet del IHSS identifica al paciente, no al
     * contribuyente.
     *
     * @return array<string, string>
     */
    private static function tiposDeDocumento(): array
    {
        $opciones = [];

        foreach (ClienteDeFactura::tiposAceptados() as $tipo) {
            $opciones[$tipo->value] = $tipo === TipoIdentificador::Dni ? 'Identidad' : $tipo->etiqueta();
        }

        return $opciones;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL CLIENTE ES QUIEN PAGA; EL PACIENTE VA APARTE (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «si tiene seguro, el cliente es el seguro y el paciente
     * siempre está; si no tiene seguro, el cliente es consumidor final».
     * El paciente ya no se propone acá: lo congela `EmisorDeFactura` en
     * su propio renglón, así que el cliente es solo el cliente.
     *
     *   · Con un tercero que paga —aseguradora, Militar, IHSS—: el
     *     convenio, con SU RTN. Es quien paga y quien reembolsa.
     *   · Con reembolso: también la aseguradora con su RTN. Paga el
     *     paciente —todo, en caja— y reclama él con esa factura
     *     (Mauricio, 11-oct-2026).
     *   · De contado: «CONSUMIDOR FINAL»… salvo arriba del umbral del
     *     SAR (L 10,000), donde hay que identificar al cliente y se
     *     propone al paciente con su documento.
     *
     * Es una PROPUESTA: la factura puede ir a la empresa que lo mandó o
     * al familiar que paga, y en la caja se cambia.
     *
     * El «Código de Cliente» ya no se propone: es el expediente y lo
     * pone `EmisorDeFactura`. La póliza va aparte, con el paciente.
     *
     * @return array{nombre: string, tipo: string|null, numero: string|null}
     */
    private function clientePropuesto(): array
    {
        $cuenta = $this->cuentaFacturando();
        $convenio = $cuenta?->convenio;

        if ($convenio instanceof Convenio && $convenio->tipo->facturaANombreDelSeguro()) {
            $rtn = trim((string) $convenio->rtn);

            /*
             * 🔴 CON SEGURO VA EL RTN DEL SEGURO, Y NADA MÁS (regla
             * del hospital, 10-oct-2026), pague quien pague: con el
             * reembolso el paciente paga todo y la factura sale igual a
             * nombre de la aseguradora (11-oct-2026). Si falta el RTN,
             * la tarjeta lo dice y `EmisorDeFactura` no emite.
             */
            return [
                'nombre' => $convenio->nombre,
                'tipo'   => TipoIdentificador::Rtn->value,
                'numero' => $rtn === '' ? null : $rtn,
            ];
        }

        if ($this->superaElUmbralDelSar($cuenta)) {
            $documento = $this->documentoDelPaciente();

            return [
                'nombre' => $this->nombreDelPacienteAFacturar() ?? ClienteDeFactura::consumidorFinal()->nombre,
                'tipo'   => $documento['tipo'],
                'numero' => $documento['numero'],
            ];
        }

        return [
            'nombre' => ClienteDeFactura::consumidorFinal()->nombre,
            'tipo'   => null,
            'numero' => null,
        ];
    }

    /**
     * Debajo del campo del cliente: por qué se propuso lo que se propuso.
     */
    private function ayudaDelCliente(): string
    {
        $cuenta = $this->cuentaFacturando();
        $tipo = $cuenta?->convenio->tipo ?? null;

        if ($tipo === TipoConvenio::Reembolso) {
            return 'El paciente paga todo y la factura sale a nombre de su aseguradora, con su RTN, para que la reclame.';
        }

        if ($tipo instanceof TipoConvenio && $tipo->facturaANombreDelSeguro()) {
            return 'Con seguro sale a nombre de la aseguradora, con su RTN.';
        }

        if ($this->superaElUmbralDelSar($cuenta)) {
            return 'Desde L 10,000 el SAR pide identificar al cliente: sale a nombre del paciente.';
        }

        return 'Sin seguro y menos de L 10,000: sale como consumidor final.';
    }

    /**
     * ¿La cuenta llega al umbral desde el cual se identifica al cliente?
     *
     * Mauricio, 11-oct-2026: «si es 10,000 o más, sale a nombre del
     * paciente». `EmisorDeFactura` exige el documento solo cuando la
     * venta SUPERA el umbral, que es lo que dice el SAR; proponer al
     * paciente también en el número exacto no viola nada —identificar
     * al cliente siempre está permitido— y evita discutirlo en caja.
     */
    private function superaElUmbralDelSar(?Cuenta $cuenta): bool
    {
        if (! $cuenta instanceof Cuenta) {
            return false;
        }

        $configurado = config('sihla.facturacion.umbral_rtn_obligatorio');
        $umbral = Decimal::de(is_string($configurado) ? $configurado : '10000.00');
        $total = Decimal::de($cuenta->total);

        return $total->mayorQue($umbral) || $total->igualA($umbral);
    }

    /**
     * El nombre del paciente de la cuenta que se está facturando, o nulo
     * si la cuenta no tiene a quién nombrar.
     */
    private function nombreDelPacienteAFacturar(): ?string
    {
        return self::nombreDelPacienteDe($this->cuentaFacturando());
    }

    /**
     * El nombre del paciente de una cuenta, o nulo si no hay a quién
     * nombrar. Lo comparten la franja de la factura y la del abono.
     */
    private static function nombreDelPacienteDe(?Cuenta $cuenta): ?string
    {
        $persona = $cuenta?->encuentro->persona ?? null;

        if (! $persona instanceof Persona) {
            return null;
        }

        $nombre = trim($persona->nombreCompleto());

        return $nombre === '' ? null : $nombre;
    }

    /**
     * Qué dato falta cuando el documento del cliente sale vacío, dicho
     * en términos de dónde se arregla.
     */
    private function queDocumentoFalta(): ?string
    {
        $propuesto = $this->clientePropuesto();

        /*
         * «CONSUMIDOR FINAL» va sin documento a propósito: no falta
         * nada, y un aviso ahí manda a buscar un dato que nadie pidió.
         */
        if ($propuesto['numero'] !== null || $propuesto['nombre'] === ClienteDeFactura::consumidorFinal()->nombre) {
            return null;
        }

        $convenio = $this->cuentaFacturando()?->convenio;

        if ($convenio instanceof Convenio && $convenio->tipo->facturaANombreDelSeguro()) {
            return 'Falta el RTN de '.$convenio->nombre.' — cargalo en Seguros y convenios';
        }

        return 'Sin documento en el expediente del paciente';
    }

    /**
     * El documento que el paciente ya tiene registrado, si tiene alguno.
     *
     * Prefiere el RTN —es el que el SAR espera— y cae a la identidad.
     *
     * @return array{tipo: string|null, numero: string|null}
     */
    private function documentoDelPaciente(): array
    {
        $persona = $this->cuentaFacturando()?->encuentro->persona ?? null;

        if (! $persona instanceof Persona) {
            return ['tipo' => null, 'numero' => null];
        }

        foreach (ClienteDeFactura::tiposAceptados() as $tipo) {
            $identificador = $persona->identificadores->firstWhere('tipo', $tipo);

            if ($identificador !== null) {
                return ['tipo' => $tipo->value, 'numero' => (string) $identificador->valor];
            }
        }

        return ['tipo' => null, 'numero' => null];
    }

    /**
     * La cuenta del modal de factura, leída una vez por pedido.
     *
     * Se consultaba en cada closure del modal —son más de veinte, cada
     * una con cuatro relaciones— y desde el 13-oct-2026 el reparto se
     * recalcula mientras se escribe: sin esto cada tecla pasaba de cien
     * consultas (§13.2). Se guarda junto con el id para que cambiar de
     * cuenta no devuelva la anterior, y el propio modal la olvida cuando
     * la modifica (`olvidarLaCuentaFacturando()`).
     *
     * @var array{0: int, 1: Cuenta|null}|null
     */
    private ?array $cuentaFacturandoLeida = null;

    /**
     * Lo abonado de la cuenta que se factura, por la misma razón.
     *
     * @var array<int, Decimal>
     */
    private array $abonadoAlFacturar = [];

    private function cuentaFacturando(): ?Cuenta
    {
        if ($this->cuentaAFacturar === null) {
            return null;
        }

        if ($this->cuentaFacturandoLeida === null || $this->cuentaFacturandoLeida[0] !== $this->cuentaAFacturar) {
            $this->cuentaFacturandoLeida = [
                $this->cuentaAFacturar,
                Cuenta::query()->with(['encuentro.persona.identificadores', 'convenio'])->find($this->cuentaAFacturar),
            ];
        }

        return $this->cuentaFacturandoLeida[1];
    }

    /**
     * Después de anotar la autorización o de cobrar, lo leído ya no es
     * la cuenta: si el modal se queda abierto, tiene que volver a leerla.
     */
    private function olvidarLaCuentaFacturando(): void
    {
        $this->cuentaFacturandoLeida = null;
        $this->abonadoAlFacturar = [];
    }

    /**
     * ¿Hay una cirugía presupuestada por facturar en esta cuenta?
     *
     * Es lo que decide si la casilla de desglose aparece. Sin paquete no
     * hay nada que abrir, y una casilla que no cambia nada en la mayoría
     * de las facturas es ruido en la pantalla donde menos conviene
     * distraer.
     *
     * `presupuesto_id` sin `presupuesto_linea_id` es el renglón del
     * paquete (ADR-0009); con los dos es un consumo previsto, que no se
     * factura aparte.
     */
    private function laCuentaFacturandoTienePaquete(): bool
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return false;
        }

        return $cuenta->cargos()
            ->where('estado', EstadoCargo::Pendiente->value)
            ->where('politica_cargo', PoliticaCargo::Cobrable->value)
            ->whereNotNull('presupuesto_id')
            ->whereNull('presupuesto_linea_id')
            ->exists();
    }

    /**
     * ¿La columna de la derecha del modal de factura tiene algo?
     *
     * Es la de la plata: lo que autorizó el seguro —si paga un tercero—,
     * lo que ya abonó y lo que falta cobrar. Sin nada de eso, el papel
     * toma el ancho entero.
     */
    private function hayColumnaDerechaAlFacturar(): bool
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return false;
        }

        return $cuenta->convenio->tipo->pagaUnTercero()
            || $cuenta->saldoPendienteDelPaciente()->mayorQue('0')
            || $cuenta->abonado()->mayorQue('0');
    }

    /**
     * Los recibos vivos de la cuenta que se está facturando, con la misma
     * lista del modal de abono pero sin botones: acá se mira, no se
     * corrige.
     *
     * Antes era una tabla de cuatro columnas que en media pantalla partía
     * el número del recibo y el monto en dos renglones (12-oct-2026).
     * Los anulados no van: esa plata se devolvió y no paga nada.
     */
    private function loQueYaAbono(): HtmlString
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return new HtmlString('');
        }

        return new HtmlString(view('filament.pages.partials.recibos-de-la-cuenta', [
            'recibos'  => $this->filasDeRecibos($cuenta, soloVivos: true),
            'acciones' => false,
        ])->render());
    }

    /**
     * Lo que va a decir el papel: los renglones de la cuenta y los
     * totales que el formato fiscal separa (§8.6.1-3).
     *
     * Los totales son los materializados de la cuenta —los mismos de la
     * pantalla de cargos—, que es lo que el emisor suma de los cargos
     * pendientes. Con cirugía desglosada el papel trae más renglones,
     * pero el total es el mismo.
     */
    private function loQueDiceLaFactura(): HtmlString
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return new HtmlString('');
        }

        $renglones = [];

        foreach ($cuenta->renglonesVivos() as $renglon) {
            $renglones[] = [
                'cantidad' => rtrim(rtrim($renglon->cantidad->redondeado(4), '0'), '.'),
                'unidad'   => $renglon->unidad,
                'texto'    => $renglon->texto,
                'importe'  => number_format((float) $renglon->total->redondeado(2), 2),
            ];
        }

        $totales = [];

        if (Decimal::de($cuenta->total_descuento)->mayorQue('0')) {
            $totales[] = ['etiqueta' => 'Descuentos', 'valor' => '− '.number_format((float) $cuenta->total_descuento, 2)];
        }

        $totales[] = ['etiqueta' => 'Importe exento', 'valor' => number_format((float) $cuenta->total_exento, 2)];

        if (Decimal::de($cuenta->total_gravado)->mayorQue('0')) {
            $totales[] = ['etiqueta' => 'Importe gravado', 'valor' => number_format((float) $cuenta->total_gravado, 2)];
            $totales[] = ['etiqueta' => 'ISV', 'valor' => number_format((float) $cuenta->total_isv, 2)];
        }

        return new HtmlString(view('filament.pages.partials.lo-que-dice-la-factura', [
            'renglones' => $renglones,
            'totales'   => $totales,
            'total'     => $cuenta->saldo()->formateado(),
        ])->render());
    }

    /**
     * La franja de arriba del modal de factura: de quién es la cuenta y
     * los números que deciden qué hacer —cuánto es, cuánto queda a
     * crédito del seguro, cuánto abonó y cuánto se cobra hoy—.
     *
     * 🔴 EN VIVO (13-oct-2026). Se lee del reparto que va quedando con lo
     * que se está escribiendo en «¿Quién paga qué?», no de la cuenta:
     * antes escribir «el seguro cubre el 50 %» no movía nada acá y la
     * franja seguía pidiendo la cuenta entera.
     */
    private function encabezadoDeLaFactura(Get $get): HtmlString
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return new HtmlString('&nbsp;');
        }

        $reparto = $this->repartoEnVivo($cuenta, self::estadoDeLaAutorizacion($get));
        $conSeguro = $cuenta->convenio->tipo->pagaUnTercero();

        $cajas = [self::cajaDeResumen('Total de la cuenta', self::enLempiras($reparto['total']))];

        /*
         * La franja es el único lugar donde se lee el reparto (14-oct-2026):
         * lo del seguro con su porcentaje y su plazo, lo del paciente con
         * el suyo. Antes había además dos tarjetas abajo que repetían lo
         * mismo y empujaban el botón fuera de la pantalla.
         */
        if ($conSeguro) {
            $cajas[] = $reparto['por_decidir']
                ? self::cajaDeResumen('Seguro · a crédito', 'Por decidir', 'rgb(217 119 6)', 'Escribí lo que autorizó')
                : self::cajaDeResumen(
                    'Seguro · a crédito',
                    self::enLempiras($reparto['seguro']),
                    'rgb(59 130 246)',
                    $reparto['seguro']->esCero()
                        ? 'No pone nada en esta cuenta'
                        : self::parteDe($reparto['seguro'], $reparto['total'])->comoPorcentaje().' · '.self::plazoCorto($cuenta->convenio),
                );
        }

        if ($reparto['abonado']->mayorQue('0') && $reparto['falta']->mayorQue('0')) {
            $cajas[] = self::cajaDeResumen('Ya abonó', self::enLempiras($reparto['abonado']), 'rgb(22 163 74)');
        }

        /*
         * «Ya pagó» y «no le toca nada» son dos cosas distintas para
         * quien cobra: una se explica con recibos y la otra con que el
         * seguro cubre todo.
         */
        if ($reparto['por_decidir']) {
            $cajas[] = self::cajaDeResumen('Cobrar hoy', '—', null, 'Depende de lo que autorice el seguro');
        } elseif ($reparto['falta']->mayorQue('0')) {
            $cajas[] = self::cajaDeResumen(
                'Cobrar hoy',
                self::enLempiras($reparto['falta']),
                'rgb(220 38 38)',
                $conSeguro
                    ? 'Copago del paciente · '.self::parteDe($reparto['paciente'], $reparto['total'])->comoPorcentaje()
                    : null,
            );
        } elseif ($reparto['falta']->esNegativo()) {
            /*
             * Abonó más de lo que al final le tocó —el seguro terminó
             * poniendo más de lo previsto—. No traba la factura, pero esa
             * plata es del paciente y se tiene que ver antes de emitir.
             */
            $cajas[] = self::cajaDeResumen(
                'A favor del paciente',
                self::enLempiras(Decimal::cero()->restar($reparto['falta'])),
                'rgb(217 119 6)',
                'Abonó más que su parte',
            );
        } else {
            $cajas[] = self::cajaDeResumen(
                'El paciente',
                'Ya puso lo suyo',
                'rgb(22 163 74)',
                $reparto['abonado']->mayorQue('0') ? 'Con sus abonos, en «Ya abonó»' : 'No le toca pagar nada',
            );
        }

        return new HtmlString(
            self::quienEsLaCuenta($cuenta).'<div style="display:flex;flex-wrap:wrap;gap:.6rem">'.implode('', $cajas).'</div>'
        );
    }

    /**
     * De quién es la cuenta, en una línea: nombre, expediente y convenio.
     * La misma arriba del modal de factura y del de abono, para que quien
     * cobra lea al paciente igual en los dos.
     */
    private static function quienEsLaCuenta(Cuenta $cuenta): string
    {
        $expediente = Expediente::query()->withTrashed()->find($cuenta->encuentro->expediente_id);

        return '<div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:.25rem .75rem;margin-bottom:.6rem">'
            .'<span style="font-size:1.05rem;font-weight:600">'.e(self::nombreDelPacienteDe($cuenta) ?? '—').'</span>'
            .($expediente instanceof Expediente
                ? '<span style="font-size:.8rem;opacity:.6;font-family:ui-monospace,monospace">'.e($expediente->numero).'</span>'
                : '')
            .'<span style="font-size:.7rem;padding:.1rem .5rem;border-radius:999px;border:1px solid rgba(127,127,127,.35);opacity:.85">'
            .e($cuenta->convenio->nombre).'</span>'
            .'</div>';
    }

    /**
     * La franja de arriba del modal de abono: de quién es la cuenta,
     * cuánto es, cuánto dejó ya y cuánto falta.
     *
     * Se mide contra el TOTAL, como el saldo de la cuenta (ADR-0008). Con
     * seguro se muestra aparte lo que pone la aseguradora y, debajo de lo
     * que falta, cuánto de eso es del paciente: en la ventanilla se le
     * pide eso, no lo que la aseguradora paga a treinta días.
     */
    private function encabezadoDelAbono(): HtmlString
    {
        $cuenta = $this->cuentaAbonando();

        if (! $cuenta instanceof Cuenta) {
            return new HtmlString('&nbsp;');
        }

        $saldo = $cuenta->saldoPendiente();
        $abonado = $cuenta->abonado();
        $conSeguro = ! $cuenta->saldoDeLaAseguradora()->esCero();

        $cajas = [self::cajaDeResumen('Total de la cuenta', 'L '.number_format((float) $cuenta->total, 2))];

        if ($conSeguro) {
            $cajas[] = self::cajaDeResumen(
                'Lo pone el seguro',
                'L '.number_format((float) $cuenta->saldoDeLaAseguradora()->valor(), 2),
                nota: 'Se factura y queda por cobrar',
            );
        }

        if ($abonado->mayorQue('0')) {
            $cajas[] = self::cajaDeResumen('Ya abonado', 'L '.number_format((float) $abonado->redondeado(2), 2), 'rgb(22 163 74)');
        }

        if ($saldo->esNegativo()) {
            $cajas[] = self::cajaDeResumen(
                'A favor del paciente',
                'L '.number_format((float) $cuenta->saldoAFavor()->redondeado(2), 2),
                'rgb(22 163 74)',
                'Dejó más de lo que suma la cuenta',
            );
        } elseif ($saldo->esCero()) {
            $cajas[] = self::cajaDeResumen('La cuenta', 'Está saldada', 'rgb(22 163 74)');
        } else {
            $delPaciente = $cuenta->saldoPendienteDelPaciente();
            $nota = null;

            if ($conSeguro) {
                $nota = $delPaciente->mayorQue('0')
                    ? 'Del paciente: L '.number_format((float) $delPaciente->redondeado(2), 2)
                    : 'Lo del paciente ya está; el resto es del seguro';
            }

            $cajas[] = self::cajaDeResumen('Falta por pagar', 'L '.number_format((float) $saldo->redondeado(2), 2), 'rgb(217 119 6)', $nota);
        }

        return new HtmlString(self::quienEsLaCuenta($cuenta).'<div style="display:flex;flex-wrap:wrap;gap:.6rem">'.implode('', $cajas).'</div>');
    }

    /**
     * Lo que se está recibiendo y cómo queda la cuenta si se aprieta
     * Recibir: la resta que la cajera hacía de cabeza con el paciente
     * esperando. Es de pantalla, no de dominio: el servicio vuelve a
     * sumar desde los medios ya validados.
     */
    private function comoQuedaConElAbono(Get $get): HtmlString
    {
        $cuenta = $this->cuentaAbonando();
        $suma = $this->sumaDeMedios($get('medios'));

        if (! $cuenta instanceof Cuenta || $suma->esCero()) {
            return new HtmlString('');
        }

        $queda = $cuenta->saldoPendiente()->restar($suma);

        /*
         * Dejar de más no es un error —la cuenta todavía puede crecer—,
         * pero tampoco es lo normal: va en ámbar para que se vea antes de
         * apretar Recibir.
         */
        if ($queda->esNegativo()) {
            $color = '217 119 6';
            $despues = 'Quedarían L '.number_format((float) Decimal::cero()->restar($queda)->redondeado(2), 2).' a favor del paciente';
        } elseif ($queda->esCero()) {
            $color = '22 163 74';
            $despues = 'La cuenta queda saldada';
        } else {
            $color = '22 163 74';
            $despues = 'Faltarían L '.number_format((float) $queda->redondeado(2), 2);
        }

        return new HtmlString(
            '<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.35rem 1rem;'
            .'padding:.6rem .85rem;border-radius:.75rem;border:1px solid rgb('.$color.' / .35);background:rgb('.$color.' / .08)">'
            .'<div>'
            .'<div style="font-size:.7rem;letter-spacing:.03em;text-transform:uppercase;opacity:.7">Recibe ahora</div>'
            .'<div style="font-size:1.25rem;font-weight:700;line-height:1.3;font-variant-numeric:tabular-nums;color:rgb('.$color.')">L '
            .number_format((float) $suma->redondeado(2), 2).'</div>'
            .'</div>'
            .'<div style="font-size:.9rem;font-weight:600;text-align:right;font-variant-numeric:tabular-nums">'.e($despues).'</div>'
            .'</div>'
        );
    }

    /**
     * Pone `$objetivo` en la ÚLTIMA forma de pago, descontando lo que ya
     * tienen las otras: «300 en efectivo y el resto con tarjeta» es
     * agregar la fila de la tarjeta y un clic.
     *
     * Si las otras ya cubren el objetivo no toca nada: restar ahí dejaría
     * un monto en cero o negativo que el recibo rechaza.
     */
    private function completarHasta(Decimal $objetivo, Get $get, Set $set): void
    {
        $medios = $get('medios');

        if (! is_array($medios) || $medios === []) {
            $set('medios', [
                (string) Str::uuid() => [
                    'forma' => FormaDePago::Efectivo->value,
                    'monto' => $objetivo->redondeado(2),
                    'banco' => null,
                ],
            ]);

            return;
        }

        $ultima = array_key_last($medios);
        $otras = $medios;
        unset($otras[$ultima]);

        $falta = $objetivo->restar($this->sumaDeMedios($otras));

        if ($falta->esCero() || $falta->esNegativo()) {
            Notification::make()
                ->warning()
                ->title('Las otras formas de pago ya cubren ese monto')
                ->send();

            return;
        }

        $set('medios.'.$ultima.'.monto', $falta->redondeado(2));
    }

    /**
     * ¿Hay una porción del paciente distinta de lo que falta? Solo con
     * seguro, y solo si al paciente todavía le falta algo.
     */
    private function hayPorcionDelPacienteAparte(): bool
    {
        $cuenta = $this->cuentaAbonando();

        if (! $cuenta instanceof Cuenta || $cuenta->saldoDeLaAseguradora()->esCero()) {
            return false;
        }

        $delPaciente = $cuenta->saldoPendienteDelPaciente();

        return $delPaciente->mayorQue('0') && ! $delPaciente->igualA($cuenta->saldoPendiente());
    }

    /**
     * La tarjeta del cliente: a nombre de quién sale y por qué, sin que
     * nadie tenga que escribirlo.
     */
    private function clienteQueSale(): HtmlString
    {
        $propuesto = $this->clientePropuesto();
        $tipos = self::tiposDeDocumento();

        $documento = $propuesto['numero'] === null
            ? 'Sin documento'
            : ($tipos[(string) $propuesto['tipo']] ?? 'Documento').' '.$propuesto['numero'];

        $falta = $this->queDocumentoFalta();

        return new HtmlString(
            '<div style="padding:.65rem .8rem;border-radius:.6rem;border:1px solid rgba(127,127,127,.22);'
            .'background:rgba(127,127,127,.06)">'
            .'<div style="font-size:1rem;font-weight:600">'.e($propuesto['nombre']).'</div>'
            .'<div style="font-size:.82rem;opacity:.8;font-variant-numeric:tabular-nums">'.e($documento).'</div>'
            .($falta === null
                ? ''
                : '<div style="font-size:.78rem;color:rgb(220 38 38);margin-top:.2rem">'.e($falta).'</div>')
            .'<div style="font-size:.75rem;opacity:.6;margin-top:.3rem">'.e($this->ayudaDelCliente()).'</div>'
            .'</div>'
        );
    }

    /**
     * El cliente con el que se emite: el de la regla, salvo que quien
     * cobra haya abierto «A nombre de alguien».
     *
     * Se arma ACÁ y no se confía en lo que viajó del formulario: con el
     * interruptor apagado los campos ni se muestran, y la regla se vuelve
     * a calcular con la cuenta de este momento.
     *
     * @param array<string, mixed> $data
     */
    private function clienteParaEmitir(array $data): ClienteDeFactura
    {
        if ((bool) ($data['otro_cliente'] ?? false)) {
            return new ClienteDeFactura(
                nombre: is_string($data['cliente_nombre'] ?? null) ? $data['cliente_nombre'] : '',
                documento: is_string($data['cliente_documento'] ?? null) ? $data['cliente_documento'] : null,
                tipoDocumento: TipoIdentificador::tryFrom(
                    is_string($data['cliente_documento_tipo'] ?? null) ? $data['cliente_documento_tipo'] : ''
                ),
                direccion: is_string($data['cliente_direccion'] ?? null) ? $data['cliente_direccion'] : null,
            );
        }

        $propuesto = $this->clientePropuesto();

        return new ClienteDeFactura(
            nombre: $propuesto['nombre'],
            documento: $propuesto['numero'],
            tipoDocumento: TipoIdentificador::tryFrom((string) $propuesto['tipo']),
        );
    }

    /**
     * ¿Alguna de las formas de pago es efectivo? Solo ahí hay vuelto.
     */
    private function hayEfectivoEn(Get $get): bool
    {
        $medios = $get('medios');

        foreach (is_array($medios) ? $medios : [] as $medio) {
            if (is_array($medio) && ($medio['forma'] ?? null) === FormaDePago::Efectivo->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * El vuelto contra lo que se cobra EN EFECTIVO: con tarjeta no hay
     * billete que devolver. Si el billete no alcanza, dice cuánto falta.
     */
    private function vueltoDe(Get $get): HtmlString
    {
        $efectivo = Decimal::cero();
        $medios = $get('medios');

        foreach (is_array($medios) ? $medios : [] as $medio) {
            if (! is_array($medio) || ($medio['forma'] ?? null) !== FormaDePago::Efectivo->value) {
                continue;
            }

            $efectivo = $efectivo->sumar(NumeroDeFormulario::aDecimalO($medio['monto'] ?? null, Decimal::cero()));
        }

        $paga = NumeroDeFormulario::aDecimalO($get('paga_con'), Decimal::cero());

        if ($paga->esCero()) {
            return new HtmlString('<span style="opacity:.55">Escribí con cuánto paga</span>');
        }

        $diferencia = $paga->restar($efectivo);

        if ($diferencia->esNegativo()) {
            return new HtmlString(
                '<span style="font-weight:600;color:rgb(220 38 38)">Faltan L '
                .number_format((float) Decimal::cero()->restar($diferencia)->redondeado(2), 2).'</span>'
            );
        }

        return new HtmlString(
            '<span style="font-size:1.25rem;font-weight:700;color:rgb(22 163 74);font-variant-numeric:tabular-nums">L '
            .number_format((float) $diferencia->redondeado(2), 2).'</span>'
        );
    }

    /**
     * Una cifra del resumen de arriba del modal de factura.
     *
     * Estilos en línea y grises con transparencia, a propósito: el CSS
     * del panel viene precompilado (§9.A7) y un gris con alfa se lee
     * igual en el tema claro que en el oscuro.
     */
    private static function cajaDeResumen(string $etiqueta, string $valor, ?string $color = null, ?string $nota = null): string
    {
        return '<div style="flex:1 1 12rem;padding:.5rem .85rem;border:1px solid rgba(127,127,127,.25);'
            .'border-radius:.75rem;background:rgba(127,127,127,.06)">'
            .'<div style="font-size:.7rem;letter-spacing:.03em;text-transform:uppercase;opacity:.7">'.e($etiqueta).'</div>'
            .'<div style="font-size:1.15rem;font-weight:600;line-height:1.3;font-variant-numeric:tabular-nums'
            .($color === null ? '' : ';color:'.e($color)).'">'.e($valor).'</div>'
            .($nota === null ? '' : '<div style="font-size:.8rem;opacity:.7;margin-top:.1rem">'.e($nota).'</div>')
            .'</div>';
    }

    /**
     * El porcentaje que viene propuesto en el campo, en enteros.
     *
     * Manda lo que ya se autorizó para ESTA cuenta; si nadie anotó nada,
     * lo que cubre el convenio en general. Vacío cuando la autorización
     * es por monto, o cuando el convenio decide la cobertura al facturar:
     * ahí no hay nada que proponer y hay que escribirlo.
     */
    private function coberturaPropuesta(): ?string
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta || $cuenta->monto_autorizado !== null) {
            return null;
        }

        $fraccion = $cuenta->cobertura_autorizada !== null
            ? Decimal::de($cuenta->cobertura_autorizada)
            : $cuenta->convenio->coberturaGeneral();

        if (! $fraccion instanceof Decimal) {
            return null;
        }

        return rtrim(rtrim($fraccion->por('100')->redondeado(2), '0'), '.');
    }

    /**
     * La ayuda debajo de lo que autorizó el seguro: de dónde sale lo que
     * viene escrito, o que no viene nada y hay que escribirlo. Una línea:
     * el modal no puede pedir scroll (14-oct-2026).
     */
    private function comoSeDecideLaCobertura(): ?string
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return null;
        }

        if ($cuenta->tieneAutorizacionPropia()) {
            return 'Ya anotado para esta cuenta. El resto es copago.';
        }

        $general = $cuenta->convenio->coberturaGeneral();

        return $general instanceof Decimal
            ? 'En general cubre el '.$general->comoPorcentaje().'. El resto es copago.'
            : 'Escribí lo que autorizó. Si lo negó, 0.';
    }

    /**
     * ¿La cuenta que se factura la paga en parte un tercero? Es lo que
     * decide si aparece lo que autorizó el seguro.
     */
    private function pagaUnTerceroAlFacturar(): bool
    {
        return $this->cuentaFacturando()?->convenio->tipo->pagaUnTercero() ?? false;
    }

    /**
     * Lo que el modal de factura tiene escrito sobre la autorización,
     * para pasárselo a `repartoEnVivo()` igual desde un campo que desde
     * el botón o desde la acción.
     *
     * @return array<string, mixed>
     */
    private static function estadoDeLaAutorizacion(Get $get): array
    {
        return [
            'forma_autorizacion'   => $get('forma_autorizacion'),
            'cobertura_autorizada' => $get('cobertura_autorizada'),
            'monto_autorizado'     => $get('monto_autorizado'),
        ];
    }

    /**
     * Lo que dice el formulario que autorizó el seguro, en el idioma del
     * dominio, y si hay que anotarlo en la cuenta.
     *
     * ─────────────────────────────────────────────────────────────────
     * CUÁNDO NO SE ANOTA NADA
     * ─────────────────────────────────────────────────────────────────
     *
     *   · El porcentaje general del convenio, sin tocar, en una cuenta
     *     sin autorización propia. Los cargos ya se partieron con él
     *     renglón por renglón, respetando lo que la póliza excluye y el
     *     tope del evento. Anotarlo como autorización lo aplicaría parejo
     *     sobre el total y se llevaría puestas las dos cosas.
     *   · Lo mismo que la cuenta ya tiene anotado: volver a anotarlo solo
     *     cambiaría quién figura como el que lo autorizó.
     *   · El campo vacío. Si el convenio decide la cobertura al facturar,
     *     el campo es obligatorio y no se llega a emitir así.
     *
     * @param array<string, mixed> $estado
     *
     * @return array{anotar: bool, fraccion: Decimal|null, monto: Monto|null}
     */
    private function autorizacionDelFormulario(Cuenta $cuenta, array $estado): array
    {
        $nada = ['anotar' => false, 'fraccion' => null, 'monto' => null];

        if (! $cuenta->convenio->tipo->pagaUnTercero()) {
            return $nada;
        }

        if (($estado['forma_autorizacion'] ?? null) === self::AUTORIZA_UN_MONTO) {
            $escrito = NumeroDeFormulario::aDecimal($estado['monto_autorizado'] ?? null);

            if (! $escrito instanceof Decimal) {
                return $nada;
            }

            $monto = Monto::de($escrito->redondeado(2));

            if ($cuenta->monto_autorizado !== null && Monto::de($cuenta->monto_autorizado)->igualA($monto)) {
                return $nada;
            }

            return ['anotar' => true, 'fraccion' => null, 'monto' => $monto];
        }

        $porcentaje = NumeroDeFormulario::aDecimal($estado['cobertura_autorizada'] ?? null);

        if (! $porcentaje instanceof Decimal) {
            return $nada;
        }

        /*
         * ⚠️ Se escribe en enteros y se guarda en FRACCIÓN: la columna, el
         * CHECK y el motor de cobertura hablan en fracción. Convertir en
         * un solo lugar es lo que evita que alguien guarde «50» donde va
         * «0.50» y el seguro termine cubriendo cincuenta veces la cuenta.
         */
        $fraccion = Decimal::de($porcentaje->entre('100')->redondeado(4));

        if ($cuenta->monto_autorizado === null && $cuenta->cobertura_autorizada !== null
            && Decimal::de($cuenta->cobertura_autorizada)->igualA($fraccion)) {
            return $nada;
        }

        $general = $cuenta->convenio->coberturaGeneral();

        if (! $cuenta->tieneAutorizacionPropia() && $general instanceof Decimal && $general->igualA($fraccion)) {
            return $nada;
        }

        return ['anotar' => true, 'fraccion' => $fraccion, 'monto' => null];
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN SOLO CÁLCULO PARA TODO EL MODAL (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Cómo queda la cuenta con lo que está escrito: cuánto pone el
     * seguro a crédito, cuánto es del paciente y cuánto falta cobrarle
     * hoy. Lo leen la franja de arriba, la tarjeta de «¿Quién paga qué?»,
     * «Cobrar ahora», el botón y la acción antes de cobrar.
     *
     * Antes cada uno leía por su lado: la línea del reparto calculaba en
     * vivo y todo lo demás leía la cuenta guardada. Con el seguro
     * cubriendo la mitad, la franja seguía diciendo «Falta cobrar
     * L 138.50», «Cobrar ahora» proponía L 138.50 y el botón cobraba
     * L 138.50. Lo que reparte es `Cuenta::repartoConLaAutorizacion()`,
     * la misma cuenta que hace el emisor después.
     *
     * `por_decidir`: el convenio decide la cobertura al facturar y todavía
     * no se escribió nada. Mientras tanto no hay copago que cobrar.
     *
     * @param array<string, mixed> $estado
     *
     * @return array{total: Monto, seguro: Monto, paciente: Monto, abonado: Decimal, falta: Decimal, por_decidir: bool}
     */
    private function repartoEnVivo(Cuenta $cuenta, array $estado): array
    {
        $autorizacion = $this->autorizacionDelFormulario($cuenta, $estado);

        $reparto = $autorizacion['anotar']
            ? $cuenta->repartoConLaAutorizacion($autorizacion['fraccion'], $autorizacion['monto'])
            : ['aseguradora' => $cuenta->saldoDeLaAseguradora(), 'paciente' => $cuenta->saldoDelPaciente()];

        $abonado = $this->abonadoAlFacturar[$cuenta->id] ??= $cuenta->abonado();

        return [
            'total'       => Monto::de($cuenta->total),
            'seguro'      => $reparto['aseguradora'],
            'paciente'    => $reparto['paciente'],
            'abonado'     => $abonado,
            'falta'       => $reparto['paciente']->cantidad()->restar($abonado),
            'por_decidir' => $cuenta->convenio->decideLaCoberturaAlFacturar()
                && ! $autorizacion['anotar']
                && ! $cuenta->tieneAutorizacionPropia(),
        ];
    }

    /**
     * El reparto en vivo de la cuenta que se está facturando, o nulo sin
     * cuenta. Para los `visible()` y las descripciones del modal.
     *
     * @return array{total: Monto, seguro: Monto, paciente: Monto, abonado: Decimal, falta: Decimal, por_decidir: bool}|null
     */
    private function repartoDelFormulario(Get $get): ?array
    {
        $cuenta = $this->cuentaFacturando();

        return $cuenta instanceof Cuenta ? $this->repartoEnVivo($cuenta, self::estadoDeLaAutorizacion($get)) : null;
    }

    /**
     * ¿Hay algo que cobrarle hoy al paciente? No mientras el seguro no
     * haya dicho cuánto pone: el copago todavía no existe.
     */
    private function hayQueCobrarHoy(Get $get): bool
    {
        $reparto = $this->repartoDelFormulario($get);

        return $reparto !== null && ! $reparto['por_decidir'] && $reparto['falta']->mayorQue('0');
    }

    /**
     * Los dos cuadros de quién paga qué: la barra partida, lo del seguro a
     * crédito y el copago del paciente, en vivo con lo que está escrito.
     * Compactos (14-oct-2026): «me gustaban los cuadros detallados, solo
     * que eran muy grandes».
     */
    private function comoQuedaElReparto(Get $get): HtmlString
    {
        $cuenta = $this->cuentaFacturando();

        if (! $cuenta instanceof Cuenta) {
            return new HtmlString('');
        }

        $estado = self::estadoDeLaAutorizacion($get);
        $reparto = $this->repartoEnVivo($cuenta, $estado);
        $total = $reparto['total'];
        $aprobado = $this->autorizacionDelFormulario($cuenta, $estado)['monto'];

        /*
         * El monto se recorta al total —ver `Cuenta::repartir()`—, y eso se
         * dice: si no, quien escribió L 5,000 ve L 3,000 y no entiende.
         */
        $notaDelSeguro = match (true) {
            $reparto['seguro']->esCero()                              => 'No pone nada en esta cuenta.',
            $aprobado instanceof Monto && $aprobado->mayorQue($total) => 'Aprobó '.self::enLempiras($aprobado).': cubre la cuenta entera.',
            default                                                   => 'Se le factura; '.self::plazoCorto($cuenta->convenio).'.',
        };

        $notaDelPaciente = match (true) {
            $reparto['paciente']->esCero()     => 'No le toca pagar nada.',
            ! $reparto['falta']->mayorQue('0') => 'Ya lo pagó con sus abonos.',
            $reparto['abonado']->mayorQue('0') => 'Ya abonó '.self::enLempiras($reparto['abonado']).': hoy '.self::enLempiras($reparto['falta']).'.',
            default                            => 'Se cobra hoy, antes de emitir.',
        };

        return new HtmlString(view('filament.pages.partials.quien-paga-que', [
            'porDecidir'       => $reparto['por_decidir'],
            'seguro'           => $cuenta->convenio->nombre,
            'delSeguro'        => self::enLempiras($reparto['seguro']),
            'delPaciente'      => self::enLempiras($reparto['paciente']),
            'parteDelSeguro'   => self::parteDe($reparto['seguro'], $total)->comoPorcentaje(),
            'parteDelPaciente' => self::parteDe($reparto['paciente'], $total)->comoPorcentaje(),
            'anchoDelSeguro'   => self::parteDe($reparto['seguro'], $total)->por('100')->redondeado(2),
            'notaDelSeguro'    => $notaDelSeguro,
            'notaDelPaciente'  => $notaDelPaciente,
        ])->render());
    }

    /**
     * Qué parte del total es `$parte`, en fracción. Cero con la cuenta en
     * cero: no hay nada que repartir.
     */
    private static function parteDe(Monto $parte, Monto $total): Decimal
    {
        if ($total->esCero()) {
            return Decimal::cero();
        }

        return Decimal::de($parte->cantidad()->entre($total->cantidad())->redondeado(4));
    }

    /**
     * «La verdad nunca se sabe cuánto tiempo» (Mauricio, 13-oct-2026): el
     * plazo pactado se dice como referencia, no como promesa. Corto,
     * porque va de nota en la franja de arriba.
     */
    private static function plazoCorto(Convenio $convenio): string
    {
        return $convenio->dias_credito !== null
            ? 'paga a '.$convenio->dias_credito.' días o más'
            : 'paga a 30, 60 o 90 días';
    }

    /**
     * Si al seguro le cambian lo que pone, lo que se le cobra al paciente
     * cambia con él: la última forma de pago se pone en lo que falta,
     * descontando lo que ya tienen las otras.
     *
     * Sin esto «Cobrar ahora» se quedaba con el número del momento en que
     * se abrió el modal —la cuenta entera—, y había que acordarse de
     * corregirlo a mano.
     */
    private function ajustarElCobro(Get $get, Set $set): void
    {
        $reparto = $this->repartoDelFormulario($get);

        if ($reparto === null || $reparto['por_decidir'] || ! $reparto['falta']->mayorQue('0')) {
            return;
        }

        $medios = $get('medios');

        if (! is_array($medios) || $medios === []) {
            $set('medios', [
                (string) Str::uuid() => [
                    'forma' => FormaDePago::Efectivo->value,
                    'monto' => $reparto['falta']->redondeado(2),
                    'banco' => null,
                ],
            ]);

            return;
        }

        $ultima = array_key_last($medios);
        $otras = $medios;
        unset($otras[$ultima]);

        $resto = $reparto['falta']->restar($this->sumaDeMedios($otras));

        if ($resto->mayorQue('0')) {
            $set('medios.'.$ultima.'.monto', $resto->redondeado(2));
        }
    }

    /**
     * Si lo que se está cobrando es exactamente lo del paciente. Nulo
     * cuando cuadra; si no, por qué no, en palabras de ventanilla.
     *
     * 🔴 Emitir exige que el paciente haya puesto lo suyo, y lo exige al
     * centavo. Cobrar de menos dejaba un recibo hecho y la factura sin
     * salir; cobrar de más dejaba plata del seguro en la gaveta.
     *
     * @return array{titulo: string, detalle: string}|null
     */
    private static function porQueNoCuadraElCobro(Decimal $falta, Decimal $cobrado): ?array
    {
        $falta = Decimal::de($falta->redondeado(2));
        $cobrado = Decimal::de($cobrado->redondeado(2));

        if ($cobrado->igualA($falta)) {
            return null;
        }

        if ($cobrado->menorQue($falta)) {
            return [
                'titulo'  => 'Faltan '.self::enLempiras($falta->restar($cobrado)),
                'detalle' => 'Al paciente le toca '.self::enLempiras($falta).' y se están cobrando '
                    .self::enLempiras($cobrado).'. Si hoy deja solo una parte, usá «Abonar» y facturá cuando complete.',
            ];
        }

        return [
            'titulo'  => 'Sobran '.self::enLempiras($cobrado->restar($falta)),
            'detalle' => 'Al paciente le toca '.self::enLempiras($falta).' y se están cobrando '
                .self::enLempiras($cobrado).'. Lo que pone el seguro no se le cobra a él.',
        ];
    }

    /**
     * Lo que no cuadra entre lo tecleado y lo que le toca al paciente, o
     * nulo cuando cuadra. Cuando cuadra no se dice nada: el botón ya dice
     * cuánto se cobra (14-oct-2026: la caja verde de «Cuadra» era un
     * adorno más en una sección que tenía que ser sencilla).
     */
    private function cobroQueNoCuadra(Get $get): ?string
    {
        $reparto = $this->repartoDelFormulario($get);

        if ($reparto === null || $reparto['por_decidir'] || ! $reparto['falta']->mayorQue('0')) {
            return null;
        }

        $problema = self::porQueNoCuadraElCobro($reparto['falta'], $this->sumaDeMedios($get('medios')));

        return $problema === null ? null : $problema['titulo'].'. '.$problema['detalle'];
    }

    /**
     * El aviso de «Cobrar ahora» cuando el monto no cuadra: una línea en
     * rojo, del tamaño de un mensaje de validación, pegada a la tabla.
     */
    private function avisoDelCobro(Get $get): HtmlString
    {
        $aviso = $this->cobroQueNoCuadra($get);

        return new HtmlString($aviso === null
            ? ''
            : '<p style="font-size:.875rem;line-height:1.4;color:rgb(239 68 68)">'.e($aviso).'</p>');
    }

    /**
     * «L 1,250.00», como el resto del modal.
     */
    private static function enLempiras(Monto|Decimal $valor): string
    {
        $cantidad = $valor instanceof Monto ? $valor->cantidad() : $valor;

        return 'L '.number_format((float) $cantidad->redondeado(2), 2);
    }

    /**
     * Deja anotada la autorización antes de emitir. Devuelve false cuando
     * algo no cuadra y ya avisó en pantalla.
     *
     * @param array<string, mixed> $data
     */
    private function aplicarLaAutorizacion(Cuenta $cuenta, array $data): bool
    {
        $autorizacion = $this->autorizacionDelFormulario($cuenta, $data);

        if (! $autorizacion['anotar']) {
            return true;
        }

        try {
            app(RegistradorDeAutorizacion::class)->registrar(
                cuenta: $cuenta,
                fraccion: $autorizacion['fraccion'],
                monto: $autorizacion['monto'],
                quien: Auth::user() instanceof User ? Auth::user() : null,
            );

            return true;
        } catch (SihlaException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo anotar la autorización')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EMITIR LA FACTURA — Y CON ESO, CERRAR LA CUENTA
     * ─────────────────────────────────────────────────────────────────
     *
     * 🔴 Es irreversible. Consume un número del rango autorizado por el
     * SAR, marca los cargos como facturados y cierra la cuenta. Ese
     * número no se libera ni aunque después se anule el papel.
     *
     * Lo que llegue después es un cargo tardío: el sistema SIEMPRE lo
     * acepta —jamás se rechaza un hecho clínico— y se resuelve con una
     * factura complementaria.
     */
    public function facturarAction(): Action
    {
        return Action::make('facturar')
            ->label('Facturar')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('primary')
            ->modalWidth('7xl')
            ->stickyModalFooter()
            ->modalHeading(function (array $arguments): string {
                $cuenta = $this->cuentaDe($arguments);

                return 'Emitir la factura'.($cuenta instanceof Cuenta ? ' · '.$cuenta->numero : '');
            })
            ->modalDescription('Consume un número del SAR y cierra la cuenta. El número no se libera aunque después se anule.')
            /*
             * 🔴 SI SE COBRA, EL BOTÓN LO DICE (11-oct-2026).
             *
             * «Cobrar ahora» viene con el saldo ya puesto para que sea
             * un clic. Con el botón diciendo solo «Emitir», ese clic
             * dejaba un recibo de efectivo que quien emitía no sabía que
             * estaba haciendo — y después la cuenta decía «ya abonó».
             *
             * Y dice lo que va a quedar, no lo que había: con «el seguro
             * cubre el 50 %» recién escrito, el botón ya cobra la mitad
             * (13-oct-2026). `getRawData()` es lo que está en pantalla
             * en este momento, sin validar todavía.
             */
            ->modalSubmitActionLabel(function (Action $action): string {
                $cuenta = $this->cuentaFacturando();

                if (! $cuenta instanceof Cuenta) {
                    return 'Emitir';
                }

                $reparto = $this->repartoEnVivo($cuenta, $action->getRawData());

                return ! $reparto['por_decidir'] && $reparto['falta']->mayorQue('0')
                    ? 'Cobrar '.self::enLempiras($reparto['falta']).' y emitir'
                    : 'Emitir la factura';
            })
            ->visible(fn (): bool => Gate::allows('create', Factura::class))
            ->schema([
                /*
                 * La franja de arriba: de quién es la cuenta y los números
                 * que deciden qué hacer. El paciente se lee acá una vez y
                 * no se repite en cada sección.
                 */
                Placeholder::make('resumen_a_facturar')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->encabezadoDeLaFactura($get)),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 A LA IZQUIERDA EL PAPEL, A LA DERECHA LA PLATA
                 * ─────────────────────────────────────────────────────
                 *
                 * Mauricio, 11-oct-2026: «que sea aún más práctico pero
                 * con todo lo necesario». Y el 12: «hay que hacer scroll y
                 * eso puede provocar que olviden algo». A la izquierda lo
                 * que va a decir la factura —a nombre de quién y qué
                 * lleva—; a la derecha lo que se hace en el mostrador:
                 * cobrar con el vuelto, ver lo que ya abonó y, con seguro,
                 * lo que autorizó.
                 *
                 * Doce columnas, 5 y 7, en un modal de 7xl: la derecha
                 * queda lo bastante ancha para que las formas de pago se
                 * dibujen como tabla —una fila— y no como cuatro campos
                 * apilados, que era lo que empujaba Emitir fuera de la
                 * pantalla.
                 */
                Grid::make(['default' => 1, 'lg' => 12])
                    ->schema([
                        Group::make([
                            /*
                             * ─────────────────────────────────────────────────
                             * 🔴 EL CLIENTE NO SE ESCRIBE: LO DECIDE LA REGLA
                             * ─────────────────────────────────────────────────
                             *
                             * Mauricio: «¿por qué hay que escribirlo? Desde
                             * 10,000 sale a nombre del paciente con su RTN;
                             * menos de 10,000, consumidor final; con seguro, a
                             * nombre del seguro». Se muestra en una tarjeta y
                             * se emite así.
                             *
                             * La excepción —el paciente que pide factura con
                             * su RTN por menos de 10,000, la empresa que lo
                             * mandó— se abre con un interruptor. Con seguro no
                             * hay excepción: la factura es de quien paga.
                             */
                            Section::make('Cliente')
                                ->icon(Heroicon::OutlinedUser)
                                ->compact()
                                ->columns(2)
                                ->schema([
                                    Placeholder::make('cliente_que_sale')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->visible(fn (Get $get): bool => ! (bool) $get('otro_cliente'))
                                        ->content(fn (): HtmlString => $this->clienteQueSale()),

                                    Toggle::make('otro_cliente')
                                        ->label('A nombre de alguien: el paciente, un familiar o una empresa')
                                        ->columnSpanFull()
                                        ->live()
                                        ->default(false)
                                        ->visible(fn (): bool => ! ($this->cuentaFacturando()?->convenio->tipo->facturaANombreDelSeguro() ?? false)),

                                    /*
                                     * Vienen con el paciente y su documento: es
                                     * la excepción más común —pide la factura a
                                     * su nombre— y la empresa se escribe encima.
                                     */
                                    CampoMayusculas::make('cliente_nombre')
                                        ->label('Nombre')
                                        ->columnSpanFull()
                                        ->required()
                                        ->maxLength(200)
                                        ->default(fn (): string => $this->nombreDelPacienteAFacturar() ?? '')
                                        ->visible(fn (Get $get): bool => (bool) $get('otro_cliente')),

                                    Select::make('cliente_documento_tipo')
                                        ->label('Documento')
                                        ->options(fn (): array => self::tiposDeDocumento())
                                        ->native(false)
                                        ->default(fn (): ?string => $this->documentoDelPaciente()['tipo'])
                                        ->requiredWith('cliente_documento')
                                        ->visible(fn (Get $get): bool => (bool) $get('otro_cliente')),

                                    TextInput::make('cliente_documento')
                                        ->label('Número')
                                        ->maxLength(20)
                                        ->default(fn (): ?string => $this->documentoDelPaciente()['numero'])
                                        ->hintIcon(
                                            'heroicon-o-information-circle',
                                            'RTN para que le sirva de crédito fiscal; sin RTN, sirve la identidad.',
                                        )
                                        ->visible(fn (Get $get): bool => (bool) $get('otro_cliente')),

                                    CampoMayusculas::make('cliente_direccion')
                                        ->label('Dirección')
                                        ->columnSpanFull()
                                        ->maxLength(250)
                                        ->visible(fn (Get $get): bool => (bool) $get('otro_cliente')),

                                    /*
                                     * La póliza sale junto al paciente, que es de
                                     * quien es. El «Código de Cliente» del papel es
                                     * el expediente y lo pone el sistema.
                                     */
                                    CampoMayusculas::make('poliza')
                                        ->label('Póliza')
                                        ->maxLength(40)
                                        ->default(fn (): ?string => $this->cuentaFacturando()?->numero_poliza)
                                        ->hint(fn (): ?string => $this->cuentaFacturando()?->numero_poliza === null
                                            ? 'Sin póliza en la cuenta'
                                            : null)
                                        ->hintIcon(
                                            'heroicon-o-information-circle',
                                            'Sale junto al paciente: por ahí busca la aseguradora al asegurado cuando reembolsa.',
                                        )
                                        ->visible(fn (): bool => $this->cuentaFacturando()?->convenio?->tipo !== TipoConvenio::Contado),

                                    CampoMayusculas::make('nota')
                                        ->label('Nota en la factura')
                                        ->placeholder('Opcional')
                                        ->columnSpan(fn (): array => $this->cuentaFacturando()?->convenio?->tipo !== TipoConvenio::Contado
                                            ? ['default' => 1, 'sm' => 1]
                                            : ['default' => 1, 'sm' => 2])
                                        ->maxLength(300),
                                ]),

                            /*
                             * ─────────────────────────────────────────────────────
                             * 🔴 LO QUE VA A DECIR EL PAPEL (12-oct-2026)
                             * ─────────────────────────────────────────────────────
                             *
                             * Mauricio: «mejora el diseño de la facturación».
                             * Emitir consume un número del SAR que no vuelve: lo
                             * último que se mira antes del botón es qué va a decir
                             * la factura —sus renglones y los totales que el
                             * formato fiscal separa— sin ir a otra pantalla. Con
                             * cirugía, acá mismo se decide si sale desglosada; antes
                             * era una sección suelta al final de la columna.
                             */
                            Section::make('Lo que dice la factura')
                                ->icon(Heroicon::OutlinedDocumentText)
                                ->compact()
                                ->schema([
                                    Placeholder::make('lo_que_dice_la_factura')
                                        ->hiddenLabel()
                                        ->content(fn (): HtmlString => $this->loQueDiceLaFactura()),

                                    Toggle::make('desglosar_paquetes')
                                        ->visible(fn (): bool => $this->laCuentaFacturandoTienePaquete())
                                        ->label('Detallar la cirugía renglón por renglón')
                                        /*
                                         * Viene marcada según el pagador y se puede
                                         * cambiar para ESTA factura. Lo que se
                                         * decida acá queda congelado en el papel:
                                         * volver a imprimirlo da exactamente lo
                                         * mismo, porque el mismo número de CAI no
                                         * puede producir dos documentos distintos.
                                         */
                                        /*
                                         * ⚠️ `->` y no `?->`: adentro de un `??` el
                                         * nullsafe sobra y PHPStan lo rechaza con
                                         * `nullsafe.neverNull`. Sin cuenta o sin
                                         * convenio, el `?? false` responde igual.
                                         */
                                        ->default(fn (): bool => (bool) ($this->cuentaFacturando()->convenio->desglosa_paquetes ?? false))
                                        ->helperText(
                                            'La cirugía sale en sus renglones —sala, habitación, medicamentos— con el '
                                            .'monto del paquete repartido entre ellos. El total no cambia. Se lista lo '
                                            .'que de verdad se entregó, así que lo que farmacia no despachó no aparece.'
                                        ),
                                ]),
                        ])
                            ->columnSpan(fn (): array => [
                                'default' => 1,
                                'lg'      => $this->hayColumnaDerechaAlFacturar() ? 5 : 12,
                            ]),

                        Group::make([
                            /*
                             * ─────────────────────────────────────────────────────
                             * 🔴 UNA SOLA SECCIÓN: QUIÉN PAGA Y CÓMO (14-oct-2026)
                             * ─────────────────────────────────────────────────────
                             *
                             * Mauricio, 13-oct-2026: «cuando escriban el monto que
                             * cubre el seguro, el resto lo paga el cliente, y el
                             * resto es como crédito del seguro a 30, 60 o 90 días».
                             * Y el 14: «Cobrar ahora es poco práctico, y hacer
                             * scroll en esa ventana no debería suceder: completo,
                             * compacto, bonito y funcional».
                             *
                             * Eran dos secciones —«¿Quién paga qué?» con una barra
                             * y dos tarjetas, y «Cobrar ahora» abajo— y el botón
                             * quedaba lejos. Las tarjetas repetían lo que ya dice la
                             * franja de arriba, que es en vivo: cuánto queda a
                             * crédito del seguro y cuánto se cobra hoy. Ahora es UNA
                             * sección: arriba lo que autorizó el seguro (solo si
                             * paga un tercero) y abajo cómo paga el paciente.
                             *
                             * El convenio trae lo que cubre en general; con la
                             * cobertura vacía —el Militar aprueba caso por caso—
                             * no hay nada que proponer y no se emite sin llenarla.
                             * Cero vale: es «lo negó».
                             */
                            Section::make('Cobro')
                                ->icon(Heroicon::OutlinedBanknotes)
                                ->compact()
                                ->visible(fn (Get $get): bool => $this->pagaUnTerceroAlFacturar() || $this->hayQueCobrarHoy($get))
                                ->schema([
                                    /*
                                     * Uno u otro, nunca los dos: con los dos
                                     * puestos, cuál manda lo decidiría el código y
                                     * alguna vez elegiría mal. La base lo rechaza
                                     * igual con un CHECK. El que no se elige se
                                     * esconde, y lo escondido no viaja.
                                     */
                                    Grid::make(['default' => 1, 'sm' => 2])
                                        ->visible(fn (): bool => $this->pagaUnTerceroAlFacturar())
                                        ->schema([
                                            ToggleButtons::make('forma_autorizacion')
                                                ->label('El seguro autorizó')
                                                ->options([
                                                    self::AUTORIZA_UN_PORCENTAJE => 'Un porcentaje',
                                                    self::AUTORIZA_UN_MONTO      => 'Un monto fijo',
                                                ])
                                                ->grouped()
                                                ->default(fn (): string => $this->cuentaFacturando()?->monto_autorizado !== null
                                                    ? self::AUTORIZA_UN_MONTO
                                                    : self::AUTORIZA_UN_PORCENTAJE)
                                                ->live()
                                                ->afterStateUpdated(fn (Get $get, Set $set) => $this->ajustarElCobro($get, $set)),

                                            TextInput::make('cobertura_autorizada')
                                                ->label('Cubre')
                                                ->numeric()
                                                ->minValue(0)
                                                ->maxValue(100)
                                                ->step('0.01')
                                                ->suffix('%')
                                                ->placeholder('Ej. 80')
                                                ->required()
                                                ->validationMessages(['required' => 'Escribí cuánto cubre el seguro. Si lo negó, 0.'])
                                                ->live(debounce: 600)
                                                ->default(fn (): ?string => $this->coberturaPropuesta())
                                                ->visible(fn (Get $get): bool => $get('forma_autorizacion') !== self::AUTORIZA_UN_MONTO)
                                                ->afterStateUpdated(fn (Get $get, Set $set) => $this->ajustarElCobro($get, $set))
                                                ->helperText(fn (): ?string => $this->comoSeDecideLaCobertura()),

                                            TextInput::make('monto_autorizado')
                                                ->label('Aprobó')
                                                ->numeric()
                                                ->minValue(0)
                                                ->step('0.01')
                                                ->prefix('L')
                                                ->placeholder('Lo que dice la autorización')
                                                ->required()
                                                ->validationMessages(['required' => 'Escribí cuánto aprobó el seguro. Si lo negó, 0.'])
                                                ->live(debounce: 600)
                                                ->default(fn (): ?string => $this->cuentaFacturando()?->monto_autorizado)
                                                ->visible(fn (Get $get): bool => $get('forma_autorizacion') === self::AUTORIZA_UN_MONTO)
                                                ->afterStateUpdated(fn (Get $get, Set $set) => $this->ajustarElCobro($get, $set))
                                                ->helperText(fn (): ?string => $this->comoSeDecideLaCobertura()),

                                            Placeholder::make('como_queda_el_reparto')
                                                ->hiddenLabel()
                                                ->columnSpanFull()
                                                ->content(fn (Get $get): HtmlString => $this->comoQuedaElReparto($get)),
                                        ]),

                                    /*
                                     * ─────────────────────────────────────────
                                     * 🔴 COBRAR Y FACTURAR SON UN SOLO ACTO
                                     * ─────────────────────────────────────────
                                     *
                                     * El caso más común del mostrador: consulta,
                                     * paga y se va. Solo aparece si al paciente le
                                     * falta algo; lo del seguro no se cobra acá.
                                     * Sigue a lo que se escriba arriba: el monto se
                                     * acomoda solo y solo se avisa en rojo si lo
                                     * tecleado no cuadra.
                                     */
                                    Group::make([
                                        Repeater::make('medios')
                                            ->label('Lo que paga hoy el paciente')
                                            ->hiddenLabel(fn (): bool => ! $this->pagaUnTerceroAlFacturar())
                                            ->table([
                                                TableColumn::make('Forma de pago')->width('38%'),
                                                TableColumn::make('Monto')->width('27%'),
                                                TableColumn::make('¿A qué banco?')->width('35%'),
                                            ])
                                            /*
                                             * Un enlace chico a la izquierda y no un
                                             * botón centrado: casi siempre se paga
                                             * con una sola forma, y el botón grande
                                             * ocupaba un renglón entero.
                                             */
                                            ->addActionLabel('Otra forma de pago')
                                            ->addAction(fn (Action $action): Action => $action->link()->icon(Heroicon::OutlinedPlus))
                                            ->addActionAlignment(Alignment::Start)
                                            ->reorderable(false)
                                            ->defaultItems(1)
                                            ->schema([
                                                Select::make('forma')
                                                    ->hiddenLabel()
                                                    ->options(FormaDePago::paraSelector())
                                                    ->default(FormaDePago::Efectivo->value)
                                                    ->native(false)
                                                    ->live(),

                                                /*
                                                 * 🔴 LA PORCIÓN DEL PACIENTE, NO EL
                                                 * TOTAL. Con un seguro que cubre el 70 %,
                                                 * proponer el total le cobra al paciente
                                                 * los L 7,000 de la aseguradora — y el
                                                 * que teclea está mirando al paciente, no
                                                 * al número.
                                                 */
                                                TextInput::make('monto')
                                                    ->hiddenLabel()
                                                    ->prefix('L')
                                                    ->inputMode('decimal')
                                                    ->live(onBlur: true)
                                                    ->default(fn (): ?string => $this->cuentaFacturando()?->saldoPendienteDelPaciente()->redondeado(2)),

                                                Select::make('banco')
                                                    ->hiddenLabel()
                                                    ->options(self::bancos())
                                                    ->native(false)
                                                    ->searchable()
                                                    ->placeholder(fn (Get $get): string => $get('forma') === FormaDePago::Transferencia->value
                                                        ? 'Elegí el banco'
                                                        : '—')
                                                    ->required(fn (Get $get): bool => $get('forma') === FormaDePago::Transferencia->value)
                                                    ->disabled(fn (Get $get): bool => $get('forma') !== FormaDePago::Transferencia->value),
                                            ]),

                                        /*
                                         * Solo si no cuadra, y pegado a la tabla,
                                         * donde está el número que hay que corregir.
                                         * Si no se corrige, la factura no sale.
                                         */
                                        Placeholder::make('aviso_del_cobro')
                                            ->hiddenLabel()
                                            ->visible(fn (Get $get): bool => $this->cobroQueNoCuadra($get) !== null)
                                            ->content(fn (Get $get): HtmlString => $this->avisoDelCobro($get)),

                                        /*
                                         * El vuelto: paga con 50 una cuenta de 26.95
                                         * y la pantalla dice 23.05. No se guarda —el
                                         * recibo dice lo que se aplicó, no el
                                         * billete—; es para que nadie haga la resta
                                         * de cabeza con la fila esperando. Con quién
                                         * lo entrega en la MISMA fila (12-oct-2026).
                                         */
                                        Grid::make(['default' => 1, 'sm' => 3])
                                            ->schema([
                                                TextInput::make('paga_con')
                                                    ->label('Paga con')
                                                    ->prefix('L')
                                                    ->inputMode('decimal')
                                                    ->placeholder('Lo que entrega')
                                                    ->dehydrated(false)
                                                    ->live(debounce: 400)
                                                    ->visible(fn (Get $get): bool => $this->hayEfectivoEn($get)),

                                                Placeholder::make('vuelto')
                                                    ->label('Vuelto')
                                                    ->content(fn (Get $get): HtmlString => $this->vueltoDe($get))
                                                    ->visible(fn (Get $get): bool => $this->hayEfectivoEn($get)),

                                                CampoMayusculas::make('entregado_por_factura')
                                                    ->label('Lo entrega')
                                                    ->placeholder('Si no es el paciente')
                                                    ->maxLength(120)
                                                    ->columnSpan(fn (Get $get): array => [
                                                        'default' => 1,
                                                        'sm'      => $this->hayEfectivoEn($get) ? 1 : 3,
                                                    ]),
                                            ]),
                                    ])
                                        ->visible(fn (Get $get): bool => $this->hayQueCobrarHoy($get)),
                                ]),

                            /*
                             * ─────────────────────────────────────────────────
                             * 🔴 SE TIENE QUE VER SI ABONÓ O SI SE LE COBRA
                             * ─────────────────────────────────────────────────
                             *
                             * Mauricio, 11-oct-2026: «para que se sepa si abonó
                             * o se le está cobrando». Cerrada, una línea con el
                             * total: la franja de arriba ya dice «Ya abonó», y los
                             * recibos uno por uno se abren si hace falta mirarlos
                             * (14-oct-2026, para que el modal no pida scroll).
                             */
                            Section::make(fn (): string => 'Ya abonó · '.self::enLempiras(
                                $this->cuentaFacturando()?->abonado() ?? Decimal::cero()
                            ))
                                ->icon(Heroicon::OutlinedCheckCircle)
                                ->compact()
                                ->collapsible()
                                ->collapsed()
                                ->visible(fn (): bool => $this->cuentaFacturando()?->abonado()->mayorQue('0') ?? false)
                                ->schema([
                                    Placeholder::make('abonos_de_la_cuenta')
                                        ->hiddenLabel()
                                        ->content(fn (): HtmlString => $this->loQueYaAbono()),
                                ]),
                        ])
                            ->columnSpan(['default' => 1, 'lg' => 7])
                            ->visible(fn (): bool => $this->hayColumnaDerechaAlFacturar()),
                    ]),
            ])
            ->action(function (Action $action, array $arguments, array $data): void {
                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                abort_unless(Gate::allows('create', Factura::class), 403);

                /*
                 * 🔴 EL RTN DEL SEGURO, ANTES DE TOCAR NADA.
                 *
                 * `EmisorDeFactura` lo exige igual, pero llega tarde:
                 * para entonces ya se anotó la autorización y, si el
                 * paciente debía algo, ya se le recibió el abono. Se
                 * pregunta acá primero para que «no se pudo facturar»
                 * no deje plata cobrada a medio camino.
                 */
                if ($cuenta->convenio->tipo->facturaANombreDelSeguro() && trim((string) $cuenta->convenio->rtn) === '') {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo facturar')
                        ->body(FacturaException::faltaElRtnDelPagador($cuenta->convenio->nombre)->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                /*
                 * 🔴 EL CLIENTE, ANTES DE COBRAR.
                 *
                 * Un documento mal escrito, o una cuenta de más de L
                 * 10,000 a nombre de alguien sin documento, se descubría
                 * en `EmisorDeFactura`: después de haber recibido la
                 * plata. El recibo quedaba hecho y la factura no.
                 */
                try {
                    $cliente = $this->clienteParaEmitir($data);
                } catch (SihlaException $e) {
                    Notification::make()->danger()->title('Revisá el cliente')->body($e->getMessage())->persistent()->send();

                    return;
                }

                $umbral = config('sihla.facturacion.umbral_rtn_obligatorio');

                if (! $cliente->tieneDocumento()
                    && Decimal::de($cuenta->total)->mayorQue(Decimal::de(is_string($umbral) ? $umbral : '10000.00'))) {
                    Notification::make()
                        ->danger()
                        ->title('Falta identificar al cliente')
                        ->body('Pasa de L 10,000 y el SAR pide el documento del cliente. El paciente no tiene uno en su '
                            .'expediente: abrí «A nombre de alguien» y escribí su RTN o su identidad.')
                        ->persistent()
                        ->send();

                    return;
                }

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL COBRO TIENE QUE SER LO DEL PACIENTE, AL CENTAVO
                 * ─────────────────────────────────────────────────────
                 *
                 * Se mira ANTES de tocar nada, con el mismo cálculo que
                 * la pantalla. Antes no se miraba: cobrar de menos dejaba
                 * un recibo hecho y la factura sin salir —«al paciente le
                 * falta»—, y cobrar de más metía en la gaveta plata que
                 * pone el seguro. `halt()` deja el modal abierto con todo
                 * lo escrito, para corregir solo el número.
                 */
                $previsto = $this->repartoEnVivo($cuenta, $data);

                if ($previsto['por_decidir']) {
                    Notification::make()
                        ->danger()
                        ->title('Falta lo que autorizó el seguro')
                        ->body($cuenta->convenio->nombre.' decide la cobertura en cada cuenta. Escribila en «¿Quién paga qué?»; si lo negó, 0.')
                        ->persistent()
                        ->send();

                    $action->halt();
                }

                if ($previsto['falta']->mayorQue('0')) {
                    $problema = self::porQueNoCuadraElCobro($previsto['falta'], $this->sumaDeMedios($data['medios'] ?? []));

                    if ($problema !== null) {
                        Notification::make()
                            ->danger()
                            ->title('Revisá el cobro: '.mb_strtolower($problema['titulo']))
                            ->body($problema['detalle'])
                            ->persistent()
                            ->send();

                        $action->halt();
                    }
                }

                /*
                 * 🔴 LA AUTORIZACIÓN, ANTES DEL COBRO.
                 *
                 * Cambia el reparto entre el paciente y el seguro, y de
                 * ese reparto depende cuánto hay que cobrarle al paciente
                 * dos líneas más abajo. Aplicarla después cobraría la
                 * cifra vieja.
                 */
                if (! $this->aplicarLaAutorizacion($cuenta, $data)) {
                    $action->halt();
                }

                $cuenta->refresh();
                $this->olvidarLaCuentaFacturando();

                /*
                 * Y si la cuenta se movió con el modal abierto —entró un
                 * cargo, alguien registró un abono—, lo que se tecleó ya
                 * no es lo del paciente. Se avisa con el número nuevo en
                 * vez de cobrar el viejo.
                 */
                if (! Decimal::de($cuenta->saldoPendienteDelPaciente()->redondeado(2))->igualA($previsto['falta']->redondeado(2))) {
                    Notification::make()
                        ->warning()
                        ->title('La cuenta cambió mientras estaba abierta')
                        ->body('Ahora al paciente le falta '.self::enLempiras($cuenta->saldoPendienteDelPaciente())
                            .'. Revisá el cobro y volvé a emitir.')
                        ->persistent()
                        ->send();

                    $action->halt();
                }

                /*
                 * ⚠️ El cobro va PRIMERO y en su propia transacción.
                 *
                 * Si después falla la emisión —no hay CAI cargado, el
                 * rango venció— la plata ya quedó registrada como abono
                 * y se factura cuando eso se resuelva. Al revés, un
                 * cobro perdido es plata que entró y el sistema no vio.
                 */
                $usuario = Auth::user();

                if ($usuario instanceof User && $cuenta->saldoPendienteDelPaciente()->mayorQue('0')) {
                    $medios = $this->mediosDelFormulario($data['medios'] ?? []);

                    if ($medios !== []) {
                        try {
                            app(ReceptorDeAbono::class)->recibir(
                                cuenta: $cuenta,
                                medios: $medios,
                                quienRecibe: $usuario,
                                entregadoPor: is_string($data['entregado_por_factura'] ?? null)
                                    ? $data['entregado_por_factura']
                                    : null,
                                nota: 'Cobrado al emitir la factura.',
                            );

                            $cuenta->refresh();
                            $this->olvidarLaCuentaFacturando();
                        } catch (SihlaException $e) {
                            Notification::make()
                                ->danger()
                                ->title('No se pudo cobrar')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();

                            return;
                        }
                    }
                }

                try {
                    $factura = app(EmisorDeFactura::class)->emitir(
                        cuenta: $cuenta,
                        cliente: $cliente,
                        quien: UsuarioAutenticado::id(),
                        nota: is_string($data['nota'] ?? null) ? $data['nota'] : null,

                        /*
                         * Sin la casilla en pantalla —no había paquete—
                         * va `null` y manda la preferencia del convenio.
                         * Un `false` acá le estaría diciendo al emisor
                         * «el usuario NO quiere desglose», que no es lo
                         * que pasó.
                         */
                        desglosar: array_key_exists('desglosar_paquetes', $data)
                            ? (bool) $data['desglosar_paquetes']
                            : null,
                        poliza: is_string($data['poliza'] ?? null) ? $data['poliza'] : null,
                    );
                } catch (SihlaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo facturar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Factura '.$factura->numero)
                    ->body('L '.number_format((float) $factura->total, 2).'. La cuenta quedó cerrada.')
                    ->send();

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EMITIR ABRE EL PAPEL (Mauricio, 11-oct-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Antes había que ir a Facturas, buscarla y apretar
                 * Imprimir, con la familia esperando el papel para irse.
                 * Ahora el modal de emitir se cambia por la factura y la
                 * ventana de imprimir se abre sola.
                 *
                 * Nombre distinto, así que Filament ve que la acción se
                 * reemplazó y no cierra el modal (ver el comentario de
                 * `cargarEnCuenta`).
                 */
                if (Gate::allows('view', $factura)) {
                    $this->replaceMountedAction('verFactura', ['factura' => $factura->id]);
                }
            });
    }

    /**
     * La factura recién emitida, en el mismo modal que usa la lista de
     * Facturas, con la ventana de imprimir abierta al cargar.
     *
     * No tiene botón propio: la monta `facturar` al terminar. Para
     * reimprimir una vieja está la lista de Facturas.
     */
    public function verFacturaAction(): Action
    {
        return Action::make('verFactura')
            ->modalHeading(function (array $arguments): string {
                $factura = $this->facturaParaVer($arguments);

                return $factura instanceof Factura ? 'Factura '.$factura->numero : 'Factura';
            })
            ->modalDescription('Recién emitida. Si cerraste la ventana de imprimir, el botón la vuelve a abrir.')
            ->modalWidth('7xl')
            ->modalContent(function (array $arguments) {
                $factura = $this->facturaParaVer($arguments);

                return $factura instanceof Factura
                    ? view('facturas.modal', ['factura' => $factura, 'imprimirAlAbrir' => true])
                    : new HtmlString('');
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function facturaParaVer(array $arguments): ?Factura
    {
        $id = $arguments['factura'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $factura = Factura::query()->find((int) $id);

        return $factura instanceof Factura && Gate::allows('view', $factura) ? $factura : null;
    }

    public function abonarAction(): Action
    {
        return Action::make('abonar')
            ->label('Abonar')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            /*
             * Ancho con lista de recibos, angosto sin ella: el primer
             * abono de una cuenta no tiene nada que mostrar a la derecha,
             * y media pantalla vacía solo aleja el botón de Recibir.
             */
            ->modalWidth(fn (): string => $this->recibosDeLaCuenta() === [] ? '3xl' : '5xl')
            ->stickyModalFooter()
            ->modalHeading(function (array $arguments): string {
                $cuenta = $this->cuentaDe($arguments);

                return 'Recibir un abono'.($cuenta instanceof Cuenta ? ' · '.$cuenta->numero : '');
            })
            ->modalSubmitActionLabel('Recibir')
            ->visible(fn (): bool => Gate::allows('create', Abono::class))
            ->schema([
                /*
                 * ─────────────────────────────────────────────────────
                 * LA FRANJA DE ARRIBA, LA MISMA DE LA FACTURA (12-oct-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Mauricio: «se puede mejorar esa vista». Los tres
                 * números sueltos en una sección gris pasan a la franja
                 * del modal de factura —quién es, cuánto es, cuánto
                 * dejó, cuánto falta—: quien cobra lee las dos pantallas
                 * igual, y con seguro ve qué parte es del paciente.
                 *
                 * ⚠️ Estilos en línea: el CSS de Filament viene
                 * precompilado (§9.A7).
                 */
                Placeholder::make('encabezado_del_abono')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->encabezadoDelAbono()),

                /*
                 * A la izquierda lo que se hace en el mostrador; a la
                 * derecha lo que ya entró, para corregir el recibo mal
                 * hecho con la familia enfrente.
                 */
                Grid::make(['default' => 1, 'lg' => 5])
                    ->schema([
                        Section::make('Lo que deja ahora')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->compact()
                            ->columnSpan(fn (): array => [
                                'default' => 1,
                                'lg'      => $this->recibosDeLaCuenta() === [] ? 5 : 3,
                            ])
                            ->schema([
                                /*
                                 * El monto que la pantalla ya sabe, a un
                                 * clic. Con seguro, también solo lo del
                                 * paciente: es lo que se le pide en la
                                 * ventanilla.
                                 */
                                Actions::make([
                                    Action::make('ponerLoQueFalta')
                                        ->label(fn (): string => 'Poner lo que falta · L '
                                            .number_format((float) ($this->cuentaAbonando()?->saldoPendiente()->redondeado(2) ?? '0'), 2))
                                        ->tooltip('Va en la última forma de pago. Si hay varias, completa lo que les falta a las otras.')
                                        ->link()
                                        ->size(Size::Small)
                                        ->visible(fn (): bool => $this->cuentaAbonando()?->saldoPendiente()->mayorQue('0') ?? false)
                                        ->action(function (Get $get, Set $set): void {
                                            $cuenta = $this->cuentaAbonando();

                                            if ($cuenta instanceof Cuenta) {
                                                $this->completarHasta($cuenta->saldoPendiente(), $get, $set);
                                            }
                                        }),

                                    Action::make('ponerLoDelPaciente')
                                        ->label(fn (): string => 'Solo lo del paciente · L '
                                            .number_format((float) ($this->cuentaAbonando()?->saldoPendienteDelPaciente()->redondeado(2) ?? '0'), 2))
                                        ->tooltip('Lo que le toca de su bolsillo. Lo del seguro se factura y se cobra después.')
                                        ->link()
                                        ->color('gray')
                                        ->size(Size::Small)
                                        ->visible(fn (): bool => $this->hayPorcionDelPacienteAparte())
                                        ->action(function (Get $get, Set $set): void {
                                            $cuenta = $this->cuentaAbonando();

                                            if ($cuenta instanceof Cuenta) {
                                                $this->completarHasta($cuenta->saldoPendienteDelPaciente(), $get, $set);
                                            }
                                        }),
                                ]),

                                $this->camposDeLosMedios(),

                                /*
                                 * El vuelto, igual que al facturar: no se
                                 * guarda —el recibo dice lo que se aplicó,
                                 * no el billete—; es para que nadie haga la
                                 * resta de cabeza con la fila esperando.
                                 */
                                Grid::make(2)
                                    ->visible(fn (Get $get): bool => $this->hayEfectivoEn($get))
                                    ->schema([
                                        TextInput::make('paga_con')
                                            ->label('Paga con')
                                            ->prefix('L')
                                            ->inputMode('decimal')
                                            ->placeholder('Lo que entrega')
                                            ->dehydrated(false)
                                            ->live(debounce: 400),

                                        Placeholder::make('vuelto')
                                            ->label('Vuelto')
                                            ->content(fn (Get $get): HtmlString => $this->vueltoDe($get)),
                                    ]),

                                Placeholder::make('recibiendo')
                                    ->hiddenLabel()
                                    ->visible(fn (Get $get): bool => ! $this->sumaDeMedios($get('medios'))->esCero())
                                    ->content(fn (Get $get): HtmlString => $this->comoQuedaConElAbono($get)),

                                $this->camposDeQuienEntrega(),
                            ]),

                        /*
                         * ─────────────────────────────────────────────
                         * LOS RECIBOS QUE YA TIENE LA CUENTA (12-oct-2026)
                         * ─────────────────────────────────────────────
                         *
                         * Mauricio: «que haya historial de los abonos que
                         * se han hecho y poder anular algún abono o
                         * editarlo». «Editar» es corregir: se anula y sale
                         * uno nuevo, en un paso
                         * (`ReceptorDeAbono::corregir()`). Solo con el
                         * turno en que se recibió todavía abierto;
                         * cerrado, la plata ya se contó y eso es una
                         * devolución.
                         */
                        Section::make(fn (): string => 'Recibos de esta cuenta · '.count($this->recibosDeLaCuenta()))
                            ->icon(Heroicon::OutlinedClock)
                            ->compact()
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->visible(fn (): bool => $this->recibosDeLaCuenta() !== [])
                            ->schema([
                                Placeholder::make('recibos_de_la_cuenta')
                                    ->hiddenLabel()
                                    ->content(fn (): HtmlString => new HtmlString(
                                        view('filament.pages.partials.recibos-de-la-cuenta', [
                                            'recibos' => $this->recibosDeLaCuenta(),
                                        ])->render()
                                    )),
                            ]),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                $cuenta = $this->cuentaDe($arguments);
                $usuario = Auth::user();

                if (! $cuenta instanceof Cuenta || ! $usuario instanceof User) {
                    return;
                }

                abort_unless(Gate::allows('create', Abono::class), 403);

                try {
                    $medios = $this->mediosDelFormulario($data['medios'] ?? []);

                    $abono = app(ReceptorDeAbono::class)->recibir(
                        cuenta: $cuenta,
                        medios: $medios,
                        quienRecibe: $usuario,
                        /*
                         * `null` = lo dejó el paciente. Guardar «El
                         * paciente» como texto sería repetir en cada
                         * recibo un dato que la cuenta ya tiene.
                         */
                        entregadoPor: ($data['quien_entrega'] ?? 'paciente') === 'otro'
                            && is_string($data['entregado_por'] ?? null)
                                ? $data['entregado_por']
                                : null,
                        nota: is_string($data['nota'] ?? null) ? $data['nota'] : null,
                    );
                } catch (SihlaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo recibir el abono')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $saldo = $cuenta->refresh()->saldoPendiente();

                Notification::make()
                    ->success()
                    ->title('Abono recibido · '.$abono->numero)
                    ->body('L '.number_format((float) $abono->total, 2).'. '
                        .($saldo->esNegativo() || $saldo->esCero()
                            ? 'La cuenta queda saldada.'
                            : 'Falta L '.number_format((float) $saldo->redondeado(2), 2).'.'))
                    ->send();
            });
    }

    /**
     * Anular un recibo desde la lista del modal de abono.
     *
     * No tiene botón propio: lo monta la lista de recibos, encima del
     * modal de abono, y al terminar se vuelve a él.
     */
    public function anularAbonoAction(): Action
    {
        return Action::make('anularAbono')
            ->modalHeading(function (array $arguments): string {
                $recibo = $this->reciboDe($arguments);

                return $recibo instanceof Abono ? 'Anular el recibo '.$recibo->numero : 'Anular el recibo';
            })
            ->modalDescription(function (array $arguments): string {
                $recibo = $this->reciboDe($arguments);

                return ($recibo instanceof Abono ? 'L '.number_format((float) $recibo->total, 2).' salen de la gaveta: devolvéselos a quien los dejó. ' : '')
                    .'El recibo queda en la bitácora, tachado, con el motivo.';
            })
            ->modalSubmitActionLabel('Anular el recibo')
            ->color('danger')
            ->schema([
                CampoMayusculas::area('motivo')
                    ->label('¿Por qué se anula?')
                    ->placeholder('Se recibió dos veces.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(200)
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $recibo = $this->reciboDe($arguments);

                if (! $recibo instanceof Abono) {
                    return;
                }

                abort_unless(Gate::allows('update', $recibo), 403);

                try {
                    app(ReceptorDeAbono::class)->anular(
                        $recibo,
                        is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                        UsuarioAutenticado::id(),
                    );
                } catch (SihlaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo anular el recibo')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $this->recibosLeidos = null;

                Notification::make()
                    ->success()
                    ->title('Recibo '.$recibo->numero.' anulado')
                    ->body('L '.number_format((float) $recibo->total, 2).' salen de la gaveta: devolvéselos a quien los dejó.')
                    ->send();
            });
    }

    /**
     * «Editar» un recibo: se anula y sale uno nuevo con lo correcto, en
     * un paso (`ReceptorDeAbono::corregir()`). El formulario viene lleno
     * con lo que dice el recibo, para cambiar solo lo que estaba mal.
     */
    public function corregirAbonoAction(): Action
    {
        return Action::make('corregirAbono')
            ->modalHeading(function (array $arguments): string {
                $recibo = $this->reciboDe($arguments);

                return $recibo instanceof Abono ? 'Corregir el recibo '.$recibo->numero : 'Corregir el recibo';
            })
            ->modalDescription('Se anula y sale uno nuevo con lo correcto, en un solo paso. El viejo queda en la bitácora, tachado; el nuevo dice a cuál corrige.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Corregir el recibo')
            ->fillForm(function (array $arguments): array {
                $recibo = $this->reciboDe($arguments);

                if (! $recibo instanceof Abono) {
                    return [];
                }

                $medios = [];

                foreach ($recibo->medios()->orderBy('id')->get() as $medio) {
                    $medios[] = [
                        'forma' => $medio->forma->value,
                        'monto' => Decimal::de($medio->monto)->redondeado(2),
                        'banco' => $medio->banco,
                    ];
                }

                return [
                    'medios'        => $medios,
                    'quien_entrega' => $recibo->entregado_por === null ? 'paciente' : 'otro',
                    'entregado_por' => $recibo->entregado_por,
                    'nota'          => $recibo->nota,
                ];
            })
            ->schema([
                $this->camposDeLosMedios(),
                $this->camposDeQuienEntrega(),

                CampoMayusculas::area('motivo')
                    ->label('¿Qué se corrige?')
                    ->placeholder('Era tarjeta, no efectivo.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(160)
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $recibo = $this->reciboDe($arguments);
                $usuario = Auth::user();

                if (! $recibo instanceof Abono || ! $usuario instanceof User) {
                    return;
                }

                abort_unless(Gate::allows('update', $recibo) && Gate::allows('create', Abono::class), 403);

                try {
                    $nuevo = app(ReceptorDeAbono::class)->corregir(
                        original: $recibo,
                        medios: $this->mediosDelFormulario($data['medios'] ?? []),
                        quienCorrige: $usuario,
                        entregadoPor: ($data['quien_entrega'] ?? 'paciente') === 'otro'
                            && is_string($data['entregado_por'] ?? null)
                                ? $data['entregado_por']
                                : null,
                        nota: is_string($data['nota'] ?? null) ? $data['nota'] : null,
                        motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                    );
                } catch (SihlaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo corregir el recibo')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $this->recibosLeidos = null;

                Notification::make()
                    ->success()
                    ->title('Recibo corregido')
                    ->body('Se anuló el '.$recibo->numero.' y quedó el '.$nuevo->numero
                        .' por L '.number_format((float) $nuevo->total, 2).'.')
                    ->send();
            });
    }

    /**
     * El recibo de los argumentos, si existe y quien mira lo puede ver.
     *
     * @param array<string, mixed> $arguments
     */
    private function reciboDe(array $arguments): ?Abono
    {
        $id = $arguments['recibo'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $recibo = Abono::query()->find((int) $id);

        return $recibo instanceof Abono && Gate::allows('view', $recibo) ? $recibo : null;
    }

    /**
     * Los recibos de la cuenta del modal, el último arriba, listos para
     * la lista: qué entró, cuándo, quién lo recibió y si todavía se puede
     * anular o corregir.
     *
     * @return list<array{id: int, numero: string, cuando: string, medios: string, total: string, quien: string, entrego: string|null, nota: string|null, anulado: bool, motivo: string|null, tocable: bool}>
     */
    private function recibosDeLaCuenta(): array
    {
        $cuenta = $this->cuentaAbonando();

        if (! $cuenta instanceof Cuenta) {
            return [];
        }

        if ($this->recibosLeidos !== null && $this->recibosLeidos['cuenta'] === $cuenta->id) {
            return $this->recibosLeidos['filas'];
        }

        $filas = $this->filasDeRecibos($cuenta);

        $this->recibosLeidos = ['cuenta' => $cuenta->id, 'filas' => $filas];

        return $filas;
    }

    /**
     * Los recibos de una cuenta, el último arriba, en la forma que pinta
     * la lista. La comparten el modal de abono —todos, con sus botones—
     * y el de factura —solo los vivos, sin botones—.
     *
     * @return list<array{id: int, numero: string, cuando: string, medios: string, total: string, quien: string, entrego: string|null, nota: string|null, anulado: bool, motivo: string|null, tocable: bool}>
     */
    private function filasDeRecibos(Cuenta $cuenta, bool $soloVivos = false): array
    {
        $consulta = Abono::query()->where('cuenta_id', $cuenta->id);

        if ($soloVivos) {
            $consulta->where('estado', EstadoAbono::Aplicado->value);
        }

        $recibos = $consulta
            ->with(['medios', 'turno', 'recibidoPor'])
            ->orderByDesc('id')
            ->get();

        $filas = [];

        foreach ($recibos as $recibo) {
            $quien = $recibo->recibidoPor;

            $filas[] = [
                'id'      => $recibo->id,
                'numero'  => $recibo->numero,
                'cuando'  => $recibo->recibido_en->format('d/m/Y H:i'),
                'medios'  => $recibo->resumenDeMedios(),
                'total'   => number_format((float) $recibo->total, 2),
                'quien'   => $quien instanceof User ? $quien->name : '—',
                'entrego' => $recibo->entregado_por,
                'nota'    => $recibo->nota,
                'anulado' => $recibo->estado === EstadoAbono::Anulado,
                'motivo'  => $recibo->motivo_anulacion,
                'tocable' => $recibo->sePuedeAnular() && Gate::allows('update', $recibo),
            ];
        }

        return $filas;
    }

    /**
     * Con qué paga: el mismo repetidor al recibir un abono y al corregirlo.
     */
    private function camposDeLosMedios(): Repeater
    {
        /*
         * ─────────────────────────────────────────────────────
         * EL REPETIDOR, EN TABLA
         * ─────────────────────────────────────────────────────
         *
         * Cada forma de pago era una tarjeta gris enorme con su
         * manija de arrastre: dos formas llenaban la pantalla y
         * el botón de Recibir quedaba abajo del pliegue. En
         * tabla, tres filas ocupan lo que antes ocupaba una.
         *
         * Sin reordenar: el orden de las formas de pago dentro
         * de un recibo no significa nada, y la manija sobraba.
         */
        return Repeater::make('medios')
            ->label('¿Con qué paga?')
            ->table([
                TableColumn::make('Forma de pago')->width('38%')->markAsRequired(),
                TableColumn::make('Monto')->width('27%')->markAsRequired(),
                TableColumn::make('¿A qué banco?')->width('35%'),
            ])
            ->addActionLabel('Agregar otra forma de pago')
            ->reorderable(false)
            ->defaultItems(1)
            ->minItems(1)
            ->live()
            ->schema([
                Select::make('forma')
                    ->hiddenLabel()
                    ->options(FormaDePago::paraSelector())
                    ->default(FormaDePago::Efectivo->value)
                    ->native(false)
                    ->required()
                    ->live(),

                TextInput::make('monto')
                    ->hiddenLabel()
                    ->prefix('L')
                    ->inputMode('decimal')
                    ->required()
                    ->live(onBlur: true),

                /*
                 * 🔴 SE DESHABILITA, NO SE ESCONDE.
                 *
                 * En una tabla, un campo que aparece y desaparece
                 * desalinea la fila con su encabezado. Y un campo
                 * deshabilitado no se deshidrata: el banco nunca
                 * llega al recibo cuando la forma no lo pide, que
                 * es justo lo que el CHECK de la base exige.
                 */
                Select::make('banco')
                    ->hiddenLabel()
                    ->options(self::bancos())
                    ->native(false)
                    ->searchable()
                    ->placeholder(fn (Get $get): string => $get('forma') === FormaDePago::Transferencia->value
                        ? 'Elegí el banco'
                        : '—')
                    ->required(fn (Get $get): bool => $get('forma') === FormaDePago::Transferencia->value)
                    ->disabled(fn (Get $get): bool => $get('forma') !== FormaDePago::Transferencia->value),
            ]);
    }

    /**
     * Quién deja la plata y la nota: lo mismo al recibir y al corregir.
     */
    private function camposDeQuienEntrega(): Grid
    {
        /*
         * Casi siempre lo deja el paciente, así que ese es el
         * default y no hay nada que teclear. Cuando lo deja otro
         * —la hija, el patrón, el vecino— ahí sí se pide el
         * nombre: es la primera pregunta cuando alguien reclama
         * un recibo perdido.
         */
        return Grid::make(2)
            ->schema([
                Select::make('quien_entrega')
                    ->label('¿Quién deja la plata?')
                    ->options([
                        'paciente' => 'El paciente',
                        'otro'     => 'Otra persona',
                    ])
                    ->default('paciente')
                    ->native(false)
                    ->live(),

                CampoMayusculas::make('entregado_por')
                    ->label('¿Quién?')
                    ->maxLength(120)
                    ->required()
                    ->placeholder('Nombre de quien deja el dinero')
                    ->visible(fn (Get $get): bool => $get('quien_entrega') === 'otro'),

                /*
                 * Al lado de la pregunta cuando lo deja el paciente —no
                 * hay nombre que pedir y la mitad derecha quedaba
                 * vacía—; abajo, a todo el ancho, cuando lo deja otro.
                 */
                CampoMayusculas::area('nota')
                    ->label('Nota')
                    ->placeholder('Opcional')
                    ->rows(1)
                    ->autosize()
                    ->maxLength(300)
                    ->columnSpan(fn (Get $get): array => [
                        'default' => 1,
                        'lg'      => $get('quien_entrega') === 'otro' ? 2 : 1,
                    ]),
            ]);
    }

    /**
     * Lo que suman las formas de pago tecleadas hasta ahora.
     *
     * Se usa solo para MOSTRAR el avance mientras se llena el formulario.
     * El monto que se guarda lo vuelve a sumar el servicio desde los
     * medios ya validados: este número es de pantalla, no de dominio.
     */
    private function sumaDeMedios(mixed $filas): Decimal
    {
        if (! is_array($filas)) {
            return Decimal::cero();
        }

        $suma = Decimal::cero();

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $monto = NumeroDeFormulario::aDecimal($fila['monto'] ?? null);

            if ($monto instanceof Decimal && ! $monto->esNegativo()) {
                $suma = $suma->sumar($monto);
            }
        }

        return $suma;
    }

    /**
     * Los bancos a los que se puede depositar, de la configuración.
     *
     * Vive en `config/sihla.php` y no en un enum: la lista cambia cuando
     * el hospital abre o cierra una cuenta bancaria, y eso no merece una
     * migración.
     *
     * @return array<string, string>
     */
    private static function bancos(): array
    {
        $configurados = config('sihla.caja.bancos');

        if (! is_array($configurados)) {
            return [];
        }

        $opciones = [];

        foreach ($configurados as $banco) {
            if (is_string($banco) && trim($banco) !== '') {
                $opciones[$banco] = $banco;
            }
        }

        return $opciones;
    }

    /**
     * Convierte lo que tecleó la cajera en medios de pago validados.
     *
     * Cada uno se valida al construirse —el efectivo no lleva papeles,
     * la tarjeta exige voucher, la transferencia exige referencia—, así
     * que si algo falta, revienta acá con un mensaje que se entiende y
     * NO llega a escribirse medio recibo.
     *
     *
     * @return list<MedioDePago>
     */
    private function mediosDelFormulario(mixed $filas): array
    {
        if (! is_array($filas)) {
            return [];
        }

        $medios = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $forma = FormaDePago::tryFrom(is_string($fila['forma'] ?? null) ? $fila['forma'] : '');
            $monto = NumeroDeFormulario::aDecimal($fila['monto'] ?? null);

            if (! $forma instanceof FormaDePago || ! $monto instanceof Decimal) {
                continue;
            }

            $medios[] = new MedioDePago(
                forma: $forma,
                monto: $monto,

                /*
                 * El banco solo viaja si la forma lo pide. Filament deja
                 * en el array lo que se tecleó ANTES de cambiar la forma
                 * —el campo se oculta, el valor no se borra— y sin este
                 * filtro un «Ficohsa» tecleado por error en una fila que
                 * terminó siendo efectivo haría fallar el CHECK.
                 */
                banco: $forma->exigeBanco() && is_string($fila['banco'] ?? null) ? $fila['banco'] : null,
            );
        }

        return $medios;
    }

    public function diagnosticarAction(): Action
    {
        return Action::make('diagnosticar')
            ->label('Diagnóstico')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->modalHeading(fn (array $arguments): string => $this->tituloDelModal($arguments))
            ->modalDescription('Con qué entró y con qué sale. Es lo que la aseguradora lee primero y lo que se reporta a SESAL.')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Agregar diagnóstico')
            ->closeModalByClickingAway(false)
            ->visible(fn (): bool => $this->puedeDiagnosticar())
            ->schema([
                Section::make()
                    ->columns(12)
                    ->schema([
                        Select::make('momento')
                            ->label('¿Cuándo?')
                            ->required()
                            ->native(false)
                            ->live()
                            ->default(MomentoDiagnostico::Ingreso->value)
                            ->columnSpan(3)
                            ->options(fn (): array => collect(MomentoDiagnostico::cases())
                                ->mapWithKeys(fn (MomentoDiagnostico $m): array => [$m->value => $m->etiqueta()])
                                ->all())
                            ->helperText('Con qué llegó no es con qué sale.'),

                        Select::make('tipo')
                            ->label('¿Qué tan central?')
                            ->required()
                            ->native(false)
                            ->default(TipoDiagnostico::Principal->value)
                            ->columnSpan(3)
                            ->options(fn (): array => collect(TipoDiagnostico::cases())
                                ->mapWithKeys(fn (TipoDiagnostico $t): array => [$t->value => $t->etiqueta()])
                                ->all())
                            ->helperText('Uno principal por momento.'),

                        Select::make('cie10_id')
                            ->label('Diagnóstico (CIE-10)')
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(6)
                            ->placeholder('Escribí el nombre o el código')
                            ->getSearchResultsUsing(fn (string $search): array => Cie10::buscar($search)
                                ->mapWithKeys(fn (Cie10 $c): array => [$c->id => $c->etiqueta()])
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Cie10::query()
                                ->find(is_numeric($value) ? (int) $value : 0)?->etiqueta())
                            ->helperText('Se busca sin tildes: «neumonia» encuentra «Neumonía».'),

                        Toggle::make('confirmado')
                            ->label('Confirmado')
                            ->columnSpan(12)
                            ->default(false)
                            ->helperText(
                                'Al ingreso casi siempre es presuntivo, y está bien que lo sea. '
                                .'Guardar un presuntivo como confirmado hace que las estadísticas del '
                                .'hospital cuenten casos que nunca existieron.'
                            ),

                        CampoMayusculas::area('observacion')
                            ->label('Observación')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpan(12)
                            ->helperText('Opcional. Lo que el médico quiera agregar en sus palabras.'),
                    ]),
            ])
            ->modalContent(function (array $arguments) {
                $cuenta = $this->cuentaDe($arguments);

                return view('filament.pages.partials.diagnosticos-del-encuentro', [
                    'diagnosticos' => $cuenta instanceof Cuenta
                        ? $this->diagnosticosDe($cuenta)
                        : new ColeccionDeModelos,
                ]);
            })
            ->action(function (array $data, array $arguments): void {
                abort_unless(Gate::allows('create', Diagnostico::class), 403);

                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                $cie10 = Cie10::query()->find($data['cie10_id'] ?? 0);

                if (! $cie10 instanceof Cie10) {
                    return;
                }

                try {
                    app(RegistradorDeDiagnostico::class)->registrar(
                        encuentro: $cuenta->encuentro,
                        cie10: $cie10,
                        tipo: TipoDiagnostico::from((string) $data['tipo']),
                        momento: MomentoDiagnostico::from((string) $data['momento']),
                        confirmado: (bool) ($data['confirmado'] ?? false),
                        observacion: $data['observacion'] ?? null,
                    );
                } catch (DiagnosticoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo agregar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Diagnóstico agregado')
                    ->send();

                /*
                 * Mismo truco que en «Agregar a la cuenta»: el contexto
                 * cambia para que Filament no cierre el modal. Una lista
                 * de diagnósticos son tres o cuatro renglones, y cerrar
                 * entre uno y otro obliga a buscar al paciente de nuevo.
                 */
                $this->renglonDeLaTanda++;

                $this->replaceMountedAction(
                    'diagnosticar',
                    $arguments,
                    ['renglon' => $this->renglonDeLaTanda],
                );
            });
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * ANULAR UNA CUENTA QUE NO DEBIÓ ABRIRSE (Mauricio, 21-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes no había cómo: la cuenta abierta por error quedaba viva, y
     * como un paciente tiene una sola cuenta viva, le bloqueaba abrir la
     * correcta. Ahora se anula con un motivo de la lista y, si ya tiene
     * algo cargado, con el detalle de por qué no se factura (Mauricio).
     * Lo hace `AnuladorDeCuenta`; acá solo se pregunta y se avisa.
     *
     * 🔴 El modal dice lo que NO es: el paciente que sí se atendió y no
     * va a pagar. Lo cargado vuelve al estante al anular, y lo que se le
     * dio a alguien ya no está ahí.
     */
    public function anularCuentaAction(): Action
    {
        return Action::make('anularCuenta')
            ->label('Anular cuenta')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->modalHeading(fn (array $arguments): string => 'Anular · '.$this->tituloDelModal($arguments))
            ->modalDescription(fn (array $arguments): string => $this->loQueSeAnula($arguments))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Anular la cuenta')
            ->closeModalByClickingAway(false)
            ->schema([
                Select::make('tipo')
                    ->label('¿Por qué se anula?')
                    ->required()
                    ->native(false)
                    ->live()
                    ->options(fn (): array => collect(MotivoDeAnulacionDeCuenta::cases())
                        ->mapWithKeys(fn (MotivoDeAnulacionDeCuenta $m): array => [$m->value => $m->opcion()])
                        ->all()),

                CampoMayusculas::area('detalle')
                    ->label('Detalle')
                    ->rows(3)
                    ->minLength(10)
                    ->maxLength(150)
                    ->required(fn (Get $get): bool => $get('tipo') === MotivoDeAnulacionDeCuenta::Otro->value)
                    ->placeholder('SE LE CARGÓ EL HEMOGRAMA A LA HERMANA, QUE VENÍA CON ELLA')
                    ->helperText(
                        'Obligatorio si la cuenta ya tiene algo cargado: es por qué no se factura. '
                        .'Queda en la cuenta y en cada renglón que se quita.'
                    ),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                abort_unless(Gate::allows('anular', $cuenta), 403);

                /*
                 * Quien no tiene `Update:Cuenta` —laboratorio, consulta
                 * externa— anula la que abrió, y solo si todo lo vivo es
                 * de su área: el medicamento que puso caja no lo quita
                 * laboratorio, ni renglón por renglón ni de una vez.
                 */
                if (! Gate::allows('update', $cuenta) && ! CatalogoDelRol::todoLoVivoEsDeSuArea($cuenta)) {
                    Notification::make()
                        ->danger()
                        ->title('No se anuló')
                        ->body(CuentaException::tieneRenglonesDeOtraArea($cuenta->numero)->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $tipo = MotivoDeAnulacionDeCuenta::tryFrom(is_string($data['tipo'] ?? null) ? $data['tipo'] : '');

                if (! $tipo instanceof MotivoDeAnulacionDeCuenta) {
                    $action->halt();

                    return;
                }

                $detalle = $data['detalle'] ?? null;

                try {
                    app(AnuladorDeCuenta::class)->anular($cuenta, $tipo, is_string($detalle) ? $detalle : null);
                } catch (CuentaException|CargoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se anuló')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * Abierto y con lo escrito: si faltaba el detalle,
                     * se agrega y se vuelve a apretar, sin elegir de
                     * nuevo el motivo.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Cuenta {$cuenta->numero} anulada")
                    ->body('Queda en «Cuentas (consulta)» con el motivo. Al paciente ya se le puede abrir otra.')
                    ->send();
            });
    }

    /**
     * ¿Se dibuja «Anular cuenta» en esta tarjeta?
     *
     * Solo la policy, que mira la fila y los permisos: se pregunta por
     * cada tarjeta y no puede consultar la base. Lo demás lo verifica la
     * acción al apretar.
     */
    public function puedeAnular(Cuenta $cuenta): bool
    {
        return Gate::allows('anular', $cuenta);
    }

    /**
     * Lo que el modal avisa antes de anular, con lo que la tarjeta ya
     * trae: renglones y total materializados, sin sumar nada.
     *
     * @param array<string, mixed> $arguments
     */
    private function loQueSeAnula(array $arguments): string
    {
        $cuenta = $this->cuentaDe($arguments);

        if (! $cuenta instanceof Cuenta) {
            return '';
        }

        $noEs = 'Anular es para lo que no debió abrirse. Si al paciente sí se le dio algo y no va a '
            .'pagar, no la anulés: avisale a dirección.';

        if ($cuenta->lineas === 0) {
            return 'No tiene nada cargado. Queda anulada con el motivo, y al paciente se le puede abrir otra. '.$noEs;
        }

        $renglones = $cuenta->lineas === 1 ? '1 renglón' : "{$cuenta->lineas} renglones";

        return "Tiene {$renglones} por ".Monto::de($cuenta->total)->formateado().'. Al anularla se quitan '
            .'todos, y lo que salió de farmacia vuelve al estante. '.$noEs;
    }

    /**
     * Los diagnósticos vigentes del encuentro, de ingreso primero.
     *
     * @return ColeccionDeModelos<int, Diagnostico>
     */
    public function diagnosticosDe(Cuenta $cuenta): ColeccionDeModelos
    {
        /** @var ColeccionDeModelos<int, Diagnostico> $lista */
        $lista = Diagnostico::query()
            ->where('encuentro_id', $cuenta->encuentro_id)
            ->with(['cie10', 'medico:id,name'])
            ->orderByRaw("momento = 'egreso'")
            ->orderByRaw("tipo = 'secundario'")
            ->orderBy('id')
            ->get();

        return $lista;
    }

    // ── Utilidades ────────────────────────────────────────────────────

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL DESCUENTO ALCANZA A TODA LA CUENTA, SIN PREGUNTAR
     * ─────────────────────────────────────────────────────────────────
     *
     * En el mostrador la rebaja se decide AL FINAL: se marcan las cosas y
     * cuando el paciente pregunta, se resuelve. Y se cambia de idea —30,
     * después 20, después 10—. Un descuento que solo alcanza a lo que
     * falta cargar deja al mismo paciente con dos precios en la misma
     * factura, y el que quede a precio lleno es el que se cargó primero:
     * pura casualidad, no una decisión.
     *
     * ⚠️ NO se editan los cargos por debajo. Cada línea se ANULA con el
     * mismo servicio de siempre —que devuelve el medicamento al estante y
     * deja la reversa escrita— y se vuelve a cargar con el descuento. Es
     * más trabajo que un UPDATE, y es la única forma en que el kardex
     * sigue diciendo la verdad: si la existencia bajó, hay un cargo que lo
     * explica; si vuelve a subir, hay una anulación que lo explica.
     *
     * Todo va en UNA transacción. A mitad de camino —seis líneas rehechas
     * y cuatro sin rehacer— la cuenta cobraría dos precios distintos por
     * el mismo medicamento, en la misma factura, el mismo día.
     */
    private function rehacerLasLineasConElDescuento(Cuenta $cuenta): void
    {
        $fraccion = $cuenta->descuento_hospital === null
            ? null
            : Decimal::de($cuenta->descuento_hospital);

        $motivo = $cuenta->motivo_descuento_hospital;

        /*
         * Solo lo de farmacia, solo lo que todavía se puede anular, y
         * solo lo que NO tiene ya el descuento que corresponde. Sin esa
         * última condición, cada vez que alguien saliera del campo se
         * anularía y recargaría la cuenta entera para dejarla igual.
         *
         * ─────────────────────────────────────────────────────────────
         * 🔴 LO ENTREGADO DENTRO DEL PAQUETE NO SE REHACE ACÁ
         * ─────────────────────────────────────────────────────────────
         *
         * Un medicamento presupuestado también es de farmacia, así que
         * caía en este barrido — y volvía a nacer SIN `presupuesto_id`,
         * sin `presupuesto_linea_id` y sin `IncluidoEnTarifa`. O sea:
         * convertido en un cargo cobrable normal. Poner un descuento
         * terminaba COBRÁNDOLE al paciente los medicamentos que ya
         * estaban adentro del paquete, y de paso borraba los checks del
         * desglose, que se cuentan por esas mismas columnas.
         *
         * Su rebaja no vive en esa línea: vive en el renglón del
         * paquete, y se pone al día abajo con el agregador.
         */
        $lineas = $cuenta->cargos()
            ->with(['item', 'almacen', 'lote', 'presentacion'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Cargo $cargo): bool => $cargo->admiteAnulacionDirecta()
                && $cargo->item?->se_almacena === true
                && $cargo->presupuesto_linea_id === null
                && ! $this->yaLoLleva($cargo, $fraccion));

        $paquetes = $this->paquetesDe($cuenta);

        if ($lineas->isEmpty() && $paquetes->isEmpty()) {
            return;
        }

        try {
            DB::transaction(function () use ($lineas, $cuenta, $fraccion, $motivo): void {
                $anulador = app(AnuladorDeCargo::class);
                $registrador = app(RegistradorDeCargo::class);

                foreach ($lineas as $cargo) {
                    $item = $cargo->item;

                    if (! $item instanceof Item) {
                        continue;
                    }

                    $cantidad = Decimal::de($cargo->cantidad);
                    $almacen = $cargo->almacen;

                    $anulador->anular(
                        $cargo,
                        'Se rehace con el descuento del hospital de la cuenta '.$cuenta->numero,
                    );

                    $registrador->registrar($cuenta, new LineaDeCargo(
                        item: $item,
                        cantidad: $cantidad,
                        claveIdempotencia: (string) Str::uuid(),
                        almacen: $almacen,

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 SE REHACE IGUAL: MISMO LOTE, MISMO ENVASE
                         * ─────────────────────────────────────────────
                         *
                         * Lo único que cambia es la rebaja. Sin el
                         * envase, el blíster volvía a nacer como diez
                         * tabletas sueltas —L 40.50 en vez de L 38.50—
                         * porque el precio sale de la forma en que se
                         * vende (11-oct-2026, se vio en pantalla).
                         *
                         * Y el lote es el que el paciente se llevó: la
                         * anulación lo devolvió a ese mismo lote, y
                         * dejarlo al FEFO podía asentar otro. Ante un
                         * retiro de mercado, la pregunta «a quién se le
                         * dio el lote X» tiene que seguir contestándose
                         * con la verdad (§9.F9).
                         */
                        lote: $cargo->lote_id === null ? null : $cargo->lote,
                        descuentoComercialPorcentaje: $fraccion,
                        motivoDescuento: $fraccion instanceof Decimal ? $motivo : null,
                        autorizadoPor: $fraccion instanceof Decimal ? $cuenta->descuento_hospital_por : null,
                        presentacion: $cargo->presentacion,
                        envases: is_numeric($cargo->cantidad_presentacion)
                            ? Decimal::de($cargo->cantidad_presentacion)
                            : null,

                        /*
                         * Y con el precio que ya tenía: cambiarle el
                         * descuento no es volver a venderlo al precio
                         * de hoy (§8.5-5).
                         */
                        mismoPrecioQue: $cargo,
                    ));
                }
            });
        } catch (CargoException|CuentaException|EncuentroException|ExistenciaInsuficienteException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo aplicar a lo ya cargado')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        /*
         * Los paquetes se ponen al día DESPUÉS y uno por uno, fuera de
         * la transacción de arriba: si el renglón de un paquete no se
         * puede rehacer, eso no puede tumbar el descuento de las líneas
         * de farmacia que ya quedaron bien.
         */
        foreach ($paquetes as $paquete) {
            $this->ponerElPaqueteAlDia($paquete);
        }

        Notification::make()
            ->success()
            ->title($fraccion instanceof Decimal ? 'Descuento aplicado a toda la cuenta' : 'Descuento quitado de toda la cuenta')
            ->body(trim(
                $lineas->count().' línea(s) de farmacia quedaron al día. '
                .($paquetes->isEmpty()
                    ? ''
                    : $paquetes->count().' paquete(s) presupuestado(s) también: la rebaja de los medicamentos entregados adentro sale del renglón de la cirugía.')
            ))
            ->send();
    }

    /**
     * Los paquetes presupuestados que hoy tienen renglón vivo en esta
     * cuenta.
     *
     * ⚠️ Solo los `pendiente`. Un paquete ya facturado no se rehace
     * anulando: eso es nota de crédito, y el bloque 7 todavía no existe.
     *
     * @return ColeccionDeModelos<int, Presupuesto>
     */
    private function paquetesDe(Cuenta $cuenta): ColeccionDeModelos
    {
        /** @var ColeccionDeModelos<int, Presupuesto> $paquetes */
        $paquetes = Presupuesto::query()
            ->whereIn('id', $cuenta->cargos()
                ->whereNotNull('presupuesto_id')
                ->whereNull('presupuesto_linea_id')
                ->where('estado', EstadoCargo::Pendiente->value)
                ->distinct()
                ->pluck('presupuesto_id'))
            ->get();

        return $paquetes;
    }

    /**
     * Vuelve a calcular el renglón del paquete y lo deja al día.
     *
     * 🔴 NUNCA BLOQUEA LO QUE YA PASÓ. El medicamento salió del estante y
     * el kardex ya lo dice; si el renglón del paquete no se pudo rehacer
     * —la cuenta se cerró, el cargo ya se facturó— se avisa y se sigue.
     * Una regla de facturación no detiene un acto clínico (ADR-0008).
     */
    private function ponerElPaqueteAlDia(Presupuesto $presupuesto): void
    {
        try {
            app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($presupuesto);
        } catch (Throwable $e) {
            Notification::make()
                ->warning()
                ->title('El renglón del paquete quedó sin actualizar')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    /**
     * ¿Esta línea ya tiene exactamente el descuento que le toca?
     *
     * Se compara en LEMPIRAS y no en fracciones, por la misma razón que
     * en `CalculadoraDeCargo`: el descuento vive redondeado al centavo, y
     * volver a dividirlo por el bruto devuelve un porcentaje que casi
     * nunca es idéntico al que se pidió.
     */
    private function yaLoLleva(Cargo $cargo, ?Decimal $fraccion): bool
    {
        $objetivo = $fraccion instanceof Decimal
            ? Decimal::de($cargo->bruto)->por($fraccion)->redondeado(2)
            : '0.00';

        return Decimal::de($cargo->descuento_comercial)->igualA($objetivo);
    }

    /**
     * 🔴 El descuento SE LEE DE LA CUENTA cada vez que se abre el modal.
     *
     * Antes vivía solo en el estado de Livewire y aguantaba mientras la
     * pantalla estuviera abierta. Bastaba con ir a Recepciones y volver
     * para perderlo — y el mismo paciente terminaba con dos líneas al
     * 30 % y una a precio lleno, sin que nadie lo decidiera.
     *
     * @param array<string, mixed> $arguments
     */
    private function cargarElDescuentoDe(array $arguments): void
    {
        $cuenta = $this->cuentaDe($arguments);

        if (! $cuenta instanceof Cuenta) {
            $this->cuentaDelDescuento = null;
            $this->descuentoDeLaTanda = null;
            $this->motivoDeLaTanda = null;
            $this->cargarLosDescuentosPorRubroDe(null);

            return;
        }

        $this->cuentaDelDescuento = $cuenta->id;

        if (Gate::allows('update', $cuenta)) {
            $this->soltarLosDescuentosSiNoHayNada($cuenta);
        }
        $this->cargarLosDescuentosPorRubroDe($cuenta);

        /*
         * De fracción a porcentaje: 0.3000 → «30». La pantalla habla en
         * porcentajes y la tabla en fracciones, y la traducción vive
         * solo acá y en `guardarElDescuentoEnLaCuenta()`.
         */
        $this->descuentoDeLaTanda = $cuenta->descuento_hospital === null
            ? null
            : rtrim(rtrim(Decimal::de($cuenta->descuento_hospital)->por('100')->redondeado(2), '0'), '.');

        $this->motivoDeLaTanda = $cuenta->motivo_descuento_hospital;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * SE AUTORIZA UNA VEZ, Y QUEDA GUARDADO
     * ─────────────────────────────────────────────────────────────────
     *
     * Los dos `updated…` disparan lo mismo porque el descuento son dos
     * datos que valen juntos: se teclea el número, después el motivo, y
     * recién ahí la cuenta tiene algo que guardar. Escribir a medias
     * dejaría un porcentaje sin explicación, que es exactamente lo que
     * el CHECK de la tabla se niega a aceptar.
     *
     * Vaciar el campo lo BORRA. Es la forma de quitarlo sin inventar un
     * botón aparte: si no hay porcentaje, no hay descuento.
     */
    public function updatedDescuentoDeLaTanda(): void
    {
        $this->guardarElDescuentoEnLaCuenta();
    }

    public function updatedMotivoDeLaTanda(): void
    {
        $this->guardarElDescuentoEnLaCuenta();
    }

    private function guardarElDescuentoEnLaCuenta(): void
    {
        $cuenta = $this->cuentaDelDescuento === null
            ? null
            : Cuenta::query()->find($this->cuentaDelDescuento);

        if (! $cuenta instanceof Cuenta || ! Gate::allows('update', $cuenta)) {
            return;
        }

        $fraccion = self::hayDescuento($this->descuentoDeLaTanda)
            ? NumeroDeFormulario::aDecimalO($this->descuentoDeLaTanda, Decimal::cero())->entre('100')
            : null;

        if (! $fraccion instanceof Decimal) {
            $cuenta->forceFill([
                'descuento_hospital'        => null,
                'motivo_descuento_hospital' => null,
                'descuento_hospital_por'    => null,
                'descuento_hospital_en'     => null,
            ])->save();

            $this->rehacerLasLineasConElDescuento($cuenta);

            return;
        }

        /*
         * El `max` del campo es comodidad del navegador, no un control:
         * un POST armado a mano lo ignora. Un porcentaje mayor a 100
         * moriría contra el CHECK de la tabla con un error crudo de
         * Postgres, así que se descarta acá y la cuenta queda como estaba.
         */
        if ($fraccion->mayorQue('1')) {
            return;
        }

        $motivo = TextoCanonico::parrafoEnMayusculas(
            is_string($this->motivoDeLaTanda) ? $this->motivoDeLaTanda : null
        ) ?? '';

        if (mb_strlen($motivo) < 10) {
            return;
        }

        $this->motivoDeLaTanda = $motivo;

        $cuenta->forceFill([
            'descuento_hospital'        => $fraccion->redondeado(4),
            'motivo_descuento_hospital' => $motivo,
            'descuento_hospital_por'    => UsuarioAutenticado::id(),
            'descuento_hospital_en'     => now(),
        ])->save();

        /*
         * Sin preguntar: no hay un botón «¿lo aplico también a lo de
         * antes?». Preguntarlo obliga a decidir dos veces lo mismo, y el
         * día que alguien conteste que no, el paciente se lleva dos
         * precios por el mismo medicamento sin que nadie lo haya querido.
         */
        $this->rehacerLasLineasConElDescuento($cuenta);
    }

    // ── Descuentos por rubro: servicios y honorarios ──────────────────

    /**
     * Lo que muestra la sección de descuentos para un rubro.
     *
     * El tope se dice ANTES de teclear y en una línea: «Hasta 20 %» en
     * porcentaje, y en lempiras cuánto suma lo cargado. La regla completa
     * va en la ayuda que aparece al pasar por encima.
     *
     * @return array{valor: string|null, modo: string, motivo: string|null, ayuda: string, detalle: string, maximo: string|null, puede: bool, aplicado: string|null}
     */
    public function datosDelDescuento(?Cuenta $cuenta, RubroDeDescuento $rubro): array
    {
        $enPantalla = [
            'valor'  => $this->valorDelDescuento($rubro),
            'modo'   => $this->modoDelDescuento($rubro),
            'motivo' => $this->motivoDelDescuento($rubro),
        ];

        if (! $cuenta instanceof Cuenta) {
            return [...$enPantalla, 'ayuda' => '', 'detalle' => '', 'maximo' => null, 'puede' => false, 'aplicado' => null];
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * EN PORCENTAJE HASTA 20 %, EN LEMPIRAS LIBRE (12-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Mauricio: «si es monto se le puede colocar cantidad libre; en
         * porcentaje que no pase del 20 %; en honorarios lo mismo». Una
         * sola línea parejo para todos, en vez de un tope por categoría
         * que al adulto mayor le dejaba «hasta L 0.00».
         */
        $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
        $tope = app(PoliticaDeDescuentoComercial::class)->topeDelDescuentoDeLaCuenta();

        $ayuda = 'Hasta '.$tope->comoPorcentaje().', encima del de ley.';
        $detalle = 'En porcentaje, hasta '.$tope->comoPorcentaje().' en cada '.$rubro->enSingular()
            .', además del descuento de ley. En lempiras, el monto que haga falta, repartido entre lo '
            .'cargado sin que ningún renglón quede en negativo.';

        $monto = AplicadorDeDescuentoDeLaCuenta::montoDe($cuenta, $rubro);
        $fraccion = AplicadorDeDescuentoDeLaCuenta::fraccionDe($cuenta, $rubro);

        return [
            ...$enPantalla,
            'ayuda'    => $ayuda,
            'detalle'  => $detalle,
            'maximo'   => $aplicador->maximoEnLempiras($cuenta, $rubro)->redondeado(2),
            'puede'    => true,
            'aplicado' => match (true) {
                $monto instanceof Decimal    => 'L '.number_format((float) $monto->redondeado(2), 2),
                $fraccion instanceof Decimal => self::comoPorcentajeCorto($fraccion),
                default                      => null,
            },
        ];
    }

    /**
     * 0.1000 → «10 %», 0.1250 → «12.5 %». Sin ceros de relleno.
     */
    private static function comoPorcentajeCorto(Decimal $fraccion): string
    {
        return rtrim(rtrim($fraccion->por('100')->redondeado(2), '0'), '.').' %';
    }

    private function valorDelDescuento(RubroDeDescuento $rubro): ?string
    {
        return $rubro === RubroDeDescuento::Servicios ? $this->descuentoServicios : $this->descuentoHonorarios;
    }

    private function modoDelDescuento(RubroDeDescuento $rubro): string
    {
        return $rubro === RubroDeDescuento::Servicios ? $this->modoServicios : $this->modoHonorarios;
    }

    private function motivoDelDescuento(RubroDeDescuento $rubro): ?string
    {
        return $rubro === RubroDeDescuento::Servicios ? $this->motivoServicios : $this->motivoHonorarios;
    }

    private function ponerEnLaSeccion(RubroDeDescuento $rubro, ?string $valor, string $modo, ?string $motivo): void
    {
        if ($rubro === RubroDeDescuento::Servicios) {
            $this->descuentoServicios = $valor;
            $this->modoServicios = $modo;
            $this->motivoServicios = $motivo;

            return;
        }

        $this->descuentoHonorarios = $valor;
        $this->modoHonorarios = $modo;
        $this->motivoHonorarios = $motivo;
    }

    /**
     * De la cuenta a la sección. Igual que el de farmacia: se lee de la
     * cuenta cada vez que se abre el modal, no se confía en lo que quedó
     * en la memoria de Livewire.
     */
    private function cargarLosDescuentosPorRubroDe(?Cuenta $cuenta): void
    {
        foreach (RubroDeDescuento::cases() as $rubro) {
            if (! $cuenta instanceof Cuenta) {
                $this->ponerEnLaSeccion($rubro, null, 'porcentaje', null);

                continue;
            }

            $monto = AplicadorDeDescuentoDeLaCuenta::montoDe($cuenta, $rubro);
            $fraccion = AplicadorDeDescuentoDeLaCuenta::fraccionDe($cuenta, $rubro);

            $this->ponerEnLaSeccion(
                $rubro,
                match (true) {
                    $monto instanceof Decimal    => $monto->redondeado(2),
                    $fraccion instanceof Decimal => rtrim(rtrim($fraccion->por('100')->redondeado(2), '0'), '.'),
                    default                      => null,
                },
                $monto instanceof Decimal ? 'monto' : 'porcentaje',
                AplicadorDeDescuentoDeLaCuenta::motivoDe($cuenta, $rubro),
            );
        }
    }

    public function updatedDescuentoServicios(): void
    {
        $this->guardarElDescuento(RubroDeDescuento::Servicios);
    }

    public function updatedMotivoServicios(): void
    {
        $this->guardarElDescuento(RubroDeDescuento::Servicios);
    }

    public function updatedDescuentoHonorarios(): void
    {
        $this->guardarElDescuento(RubroDeDescuento::Honorarios);
    }

    public function updatedMotivoHonorarios(): void
    {
        $this->guardarElDescuento(RubroDeDescuento::Honorarios);
    }

    /**
     * De % a L, o al revés.
     *
     * No convierte el número: 10 % y L 10 no son lo mismo. Vacía el campo
     * y espera el número nuevo; lo que está guardado en la cuenta no se
     * toca hasta que se escriba.
     */
    public function cambiarModoDelDescuento(string $rubro, string $modo): void
    {
        $elRubro = RubroDeDescuento::tryFrom($rubro);

        if (! $elRubro instanceof RubroDeDescuento
            || ! in_array($modo, ['porcentaje', 'monto'], true)
            || $modo === $this->modoDelDescuento($elRubro)) {
            return;
        }

        $this->ponerEnLaSeccion($elRubro, null, $modo, $this->motivoDelDescuento($elRubro));
    }

    /**
     * Igual que el de farmacia: el número y el motivo valen juntos, y
     * vaciar el número lo quita.
     */
    private function guardarElDescuento(RubroDeDescuento $rubro): void
    {
        $cuenta = $this->cuentaDelDescuento === null
            ? null
            : Cuenta::query()->find($this->cuentaDelDescuento);

        if (! $cuenta instanceof Cuenta || ! Gate::allows('update', $cuenta)) {
            return;
        }

        $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
        $valor = NumeroDeFormulario::aDecimal($this->valorDelDescuento($rubro));

        if (! $valor instanceof Decimal || $valor->esCero() || $valor->esNegativo()) {
            if (! AplicadorDeDescuentoDeLaCuenta::fraccionDe($cuenta, $rubro) instanceof Decimal
                && ! AplicadorDeDescuentoDeLaCuenta::montoDe($cuenta, $rubro) instanceof Decimal) {
                return;
            }

            try {
                $aplicador->quitar($cuenta, $rubro);
            } catch (SihlaException $e) {
                Notification::make()
                    ->danger()
                    ->title('No se pudo quitar el descuento en '.$rubro->enPlural())
                    ->body($e->getMessage())
                    ->persistent()
                    ->send();

                return;
            }

            $this->ponerEnLaSeccion($rubro, null, $this->modoDelDescuento($rubro), null);

            Notification::make()
                ->success()
                ->title('Descuento en '.$rubro->enPlural().' quitado')
                ->body('Volvieron a su precio.')
                ->send();

            return;
        }

        $motivo = $this->motivoDelDescuento($rubro) ?? '';

        if (mb_strlen(trim($motivo)) < 10) {
            return;
        }

        $enLempiras = $this->modoDelDescuento($rubro) === 'monto';

        try {
            $topadas = $aplicador->fijar(
                $cuenta,
                $rubro,
                $enLempiras ? null : $valor->entre('100'),
                $enLempiras ? $valor : null,
                $motivo,
                UsuarioAutenticado::id(),
            );
        } catch (SihlaException $e) {
            Notification::make()
                ->danger()
                ->title('No se aplicó el descuento en '.$rubro->enPlural())
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->cargarLosDescuentosPorRubroDe($cuenta->refresh());

        Notification::make()
            ->success()
            ->title('Descuento en '.$rubro->enPlural().' aplicado')
            ->body($topadas === []
                ? 'Quedó en todo lo cargado y en lo que se cargue.'
                : self::dichoDeLasTopadas($topadas, $rubro))
            ->send();
    }

    /**
     * Con el descuento en lempiras, el reparto depende de qué renglones
     * hay: si entra o sale uno, «L 500» se vuelve a repartir entre los
     * que quedan del mismo rubro.
     *
     * 🔴 NUNCA BLOQUEA LO QUE YA PASÓ. La línea ya entró (o ya salió); si
     * el monto no cabe en lo que quedó, se avisa y se sigue.
     */
    private function repartirDeNuevoElRubroDe(Cuenta $cuenta, Item $item): void
    {
        $rubro = AplicadorDeDescuentoDeLaCuenta::rubroDe($item);

        if (! $rubro instanceof RubroDeDescuento) {
            return;
        }

        $cuenta->refresh();

        if (! AplicadorDeDescuentoDeLaCuenta::montoDe($cuenta, $rubro) instanceof Decimal) {
            return;
        }

        try {
            app(AplicadorDeDescuentoDeLaCuenta::class)->ponerAlDia($cuenta, $rubro);
        } catch (SihlaException $e) {
            Notification::make()
                ->warning()
                ->title('El descuento en '.$rubro->enPlural().' quedó sin repartir')
                ->body($e->getMessage().' Cambialo arriba, en «Descuentos de la cuenta».')
                ->persistent()
                ->send();
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * SIN NADA CARGADO, SIN DESCUENTO (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «si no hay nada, que por defecto el descuento esté
     * vacío». Una cuenta vaciada mostraba «10 % aplicado» sobre nada, y
     * lo próximo que se cargara —quizá otro día y por otro motivo— nacía
     * con esa rebaja sin que nadie la hubiera vuelto a decidir.
     *
     * Al quitar el último renglón de un rubro, su descuento se va con él.
     * Solo al QUITAR desde esta pantalla, no en `AnuladorDeCargo`: rehacer
     * una línea para cambiarle el descuento también la anula, y ahí el
     * rubro queda vacío un instante a propósito.
     *
     * Devuelve si soltó uno, para no repartir después sobre lo que ya no
     * está. Quien llama ya autorizó: `quitarCargo()` y `quitarConMotivo()`
     * exigen poder tocar la cuenta antes de anular nada.
     */
    private function soltarElDescuentoSiQuedoVacio(Cuenta $cuenta, Item $item): bool
    {
        $cuenta->refresh();

        if (! $cuenta->estado->estaViva()) {
            return false;
        }
        $rubro = AplicadorDeDescuentoDeLaCuenta::rubroDe($item);

        if ($rubro instanceof RubroDeDescuento) {
            $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);

            /*
             * «Algo» incluye el paquete: si queda una cirugía con servicios
             * adentro, el descuento sigue alcanzándola (12-oct-2026).
             */
            if (! self::tieneDescuentoEn($cuenta, $rubro) || $aplicador->alcanzaAAlgo($cuenta, $rubro)) {
                return false;
            }

            try {
                $aplicador->quitar($cuenta, $rubro);
            } catch (SihlaException) {
                return false;
            }

            if ($this->cuentaDelDescuento === $cuenta->id) {
                $this->cargarLosDescuentosPorRubroDe($cuenta->refresh());
            }

            return true;
        }

        if ($item->se_almacena !== true || $cuenta->descuento_hospital === null || $this->quedaAlgoDeFarmacia($cuenta)) {
            return false;
        }

        $this->soltarElDescuentoDeFarmacia($cuenta);

        return true;
    }

    /**
     * Al abrir una cuenta que no tiene NADA cargado, sus descuentos se van.
     *
     * Es la cuenta que quedó vacía antes de que existiera la regla de
     * arriba, o la que alguien dejó con un descuento puesto y sin cargar
     * nada. Solo con la cuenta entera vacía: si hay algo de farmacia, el
     * 10 % que se puso en servicios para lo que viene se respeta.
     */
    private function soltarLosDescuentosSiNoHayNada(Cuenta $cuenta): void
    {
        $rubros = array_values(array_filter(
            RubroDeDescuento::cases(),
            fn (RubroDeDescuento $rubro): bool => self::tieneDescuentoEn($cuenta, $rubro),
        ));

        if ($rubros === [] && $cuenta->descuento_hospital === null) {
            return;
        }

        $tieneAlgo = $cuenta->cargos()
            ->whereNotIn('estado', [
                EstadoCargo::Anulado->value,
                EstadoCargo::Anulacion->value,
                EstadoCargo::Trasladado->value,
            ])
            ->exists();

        if ($tieneAlgo || ! $cuenta->estado->estaViva()) {
            return;
        }

        try {
            foreach ($rubros as $rubro) {
                app(AplicadorDeDescuentoDeLaCuenta::class)->quitar($cuenta, $rubro);
            }
        } catch (SihlaException) {
            return;
        }

        if ($cuenta->descuento_hospital !== null) {
            $this->soltarElDescuentoDeFarmacia($cuenta);
        }

        $cuenta->refresh();
    }

    private static function tieneDescuentoEn(Cuenta $cuenta, RubroDeDescuento $rubro): bool
    {
        return AplicadorDeDescuentoDeLaCuenta::fraccionDe($cuenta, $rubro) instanceof Decimal
            || AplicadorDeDescuentoDeLaCuenta::montoDe($cuenta, $rubro) instanceof Decimal;
    }

    /**
     * ¿Queda algo a lo que alcance el descuento de farmacia? Lo de
     * farmacia que se puede rehacer, y el paquete: su renglón lleva la
     * rebaja de los medicamentos de adentro.
     */
    private function quedaAlgoDeFarmacia(Cuenta $cuenta): bool
    {
        if ($this->paquetesDe($cuenta)->isNotEmpty()) {
            return true;
        }

        return $cuenta->cargos()
            ->with('item')
            ->get()
            ->contains(fn (Cargo $cargo): bool => $cargo->admiteAnulacionDirecta()
                && $cargo->item?->se_almacena === true
                && $cargo->presupuesto_linea_id === null);
    }

    private function soltarElDescuentoDeFarmacia(Cuenta $cuenta): void
    {
        $cuenta->forceFill([
            'descuento_hospital'        => null,
            'motivo_descuento_hospital' => null,
            'descuento_hospital_por'    => null,
            'descuento_hospital_en'     => null,
        ])->save();

        if ($this->cuentaDelDescuento === $cuenta->id) {
            $this->descuentoDeLaTanda = null;
            $this->motivoDeLaTanda = null;
        }
    }

    /**
     * @param list<string> $topadas
     */
    private static function dichoDeLasTopadas(array $topadas, RubroDeDescuento $rubro): string
    {
        $nombres = array_slice($topadas, 0, 3);
        $resto = count($topadas) - count($nombres);
        $lista = implode(', ', $nombres).($resto > 0 ? " y {$resto} más" : '');

        return (count($topadas) === 1 ? "{$lista} quedó en su tope" : "{$lista} quedaron en su tope")
            .': ningún '.$rubro->enSingular().' rebaja más de lo que vale después del de ley, y lo que no le cupo pasó a los otros.';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function cuentaDe(array $arguments): ?Cuenta
    {
        $id = $arguments['cuenta'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $cuenta = Cuenta::query()
            ->with(['encuentro.persona', 'encuentro.expediente', 'convenio'])
            ->find((int) $id);

        return $cuenta instanceof Cuenta ? $cuenta : null;
    }

    /**
     * Resuelve de una sola vez qué pacientes de la búsqueda tienen cuenta
     * viva.
     *
     * Sin esto, doce resultados serían doce consultas para armar la lista
     * y otras doce para decidir cuáles van apagados (§13.2). Se llena la
     * misma memoria que usa `cuentaVivaDe`, así que lo que venga después
     * ya no vuelve a preguntar — ni siquiera por los que NO tienen, que
     * quedan anotados como nulo.
     *
     * @param list<int> $personaIds
     */
    private function precargarCuentasVivas(array $personaIds): void
    {
        $faltantes = array_values(array_filter(
            $personaIds,
            fn (int $id): bool => ! array_key_exists($id, $this->cuentasVivas),
        ));

        if ($faltantes === []) {
            return;
        }

        foreach ($faltantes as $id) {
            $this->cuentasVivas[$id] = null;
        }

        $vivas = Cuenta::query()
            ->whereIn('estado', [
                EstadoCuenta::Abierta->value,
                EstadoCuenta::Congelada->value,
            ])
            ->whereHas('encuentro', fn (Builder $encuentro): Builder => $encuentro
                ->whereIn('persona_id', $faltantes))
            ->with('encuentro:id,persona_id')
            ->orderBy('id')
            ->get();

        foreach ($vivas as $cuenta) {
            $persona = $cuenta->encuentro?->persona_id;

            if (is_int($persona)) {
                $this->cuentasVivas[$persona] = $cuenta;
            }
        }
    }

    /**
     * La cuenta viva del paciente, si la tiene.
     *
     * Congelada cuenta como viva: congelar es el paso previo a cerrar, no
     * un cierre, y esa cuenta todavía va a salir en una factura.
     */
    private function cuentaVivaDe(mixed $personaId): ?Cuenta
    {
        if (! is_numeric($personaId)) {
            return null;
        }

        $clave = (int) $personaId;

        /*
         * El modal se vuelve a pintar con cada campo que se toca, y este
         * aviso se pregunta dos veces por pintada —una para saber si se
         * muestra y otra para armar el texto—. Sin la memoria, elegir
         * paciente, expediente, tipo y pagador serían ocho consultas para
         * responder cuatro veces lo mismo (§13.2).
         */
        if (array_key_exists($clave, $this->cuentasVivas)) {
            return $this->cuentasVivas[$clave];
        }

        $cuenta = Cuenta::query()
            ->whereIn('estado', [
                EstadoCuenta::Abierta->value,
                EstadoCuenta::Congelada->value,
            ])
            ->whereHas('encuentro', fn (Builder $encuentro): Builder => $encuentro
                ->where('persona_id', $clave))
            ->orderByDesc('id')
            ->first();

        return $this->cuentasVivas[$clave] = $cuenta instanceof Cuenta ? $cuenta : null;
    }

    private function cargoDe(array $arguments): ?Cargo
    {
        $id = $arguments['cargo'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $cargo = Cargo::query()->find((int) $id);

        return $cargo instanceof Cargo ? $cargo : null;
    }

    /**
     * El ítem que corresponde a un código escaneado o tecleado.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL CÓDIGO DE BARRAS NO ESTÁ EN EL ÍTEM: ESTÁ EN LA PRESENTACIÓN
     * ─────────────────────────────────────────────────────────────────
     *
     * `items` no tiene columna de código de barras, y es correcto que no
     * la tenga: lo que se escanea es el ENVASE, y el mismo medicamento
     * llega en caja de 100, en blíster de 10 y en muestra médica, cada
     * uno con su código. Ese dato vive en `item_presentaciones`.
     *
     * Así que se busca en dos lugares y en este orden:
     *
     *   1. el código de barras de una presentación — lo que lee la
     *      pistola frente a un envase que trae código propio;
     *   2. el del producto —el de la caja del fabricante, que heredan
     *      todos sus envases— o su `codigo` interno, que es lo que
     *      teclea alguien que se lo sabe (`Item::porCodigo()`).
     *
     * ⚠️ El código de barras se compara TAL CUAL, sin pasar a mayúsculas:
     * algunos GS1 llevan minúsculas y tocarlas rompe la lectura (lo dice
     * el propio modelo `ItemPresentacion`). El código del ítem sí se
     * normaliza, porque ese se teclea a mano.
     *
     * Un solo lugar para los dos caminos —el `afterStateUpdated` del
     * escáner y el respaldo del Enter— porque si difirieran, la pistola
     * encontraría un producto que el teclado no.
     */
    private function itemPorCodigo(mixed $codigo): ?Item
    {
        $presentacion = $this->presentacionPorCodigo($codigo);

        if ($presentacion instanceof ItemPresentacion) {
            return $presentacion->item;
        }

        return Item::porCodigo($codigo);
    }

    /**
     * La presentación cuyo código de barras coincide, si la hay.
     */
    private function presentacionPorCodigo(mixed $codigo): ?ItemPresentacion
    {
        if (! is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        $presentacion = ItemPresentacion::query()
            ->with('item')
            ->where('codigo_barras', trim($codigo))
            ->first();

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    /**
     * Cuánto puede rebajar el hospital a ESTE paciente, en fracción.
     *
     * Es la misma clase que usa `CalculadoraDeCargo`, a propósito: si la
     * pantalla tuviera su propia tabla de topes, el día que dirección
     * cambie uno quedarían dos verdades y la pantalla ofrecería algo que
     * el servicio rechaza.
     *
     * Mira la cuenta entera y no solo la edad: con un pagador que no
     * aplica el Art. 30 (PALIG, 9-oct-2026) el adulto mayor no recibe el
     * de ley, y el tope le suma lo que la ley no le dio — 35 % la tercera
     * edad, 40 % la cuarta —. Con el tope de su edad solo, quedaba por
     * debajo del 30 % que se le puede dar a un joven del mismo seguro.
     *
     * La categoría es la de medicamentos porque es lo único que esta
     * banda toca: el descuento de la cuenta solo cae en lo que sale de
     * farmacia (`se_almacena`), y eso nace con esa categoría.
     */
    public function topeDeDescuento(?Cuenta $cuenta): Decimal
    {
        $politica = app(PoliticaDeDescuentoComercial::class);

        if (! $cuenta instanceof Cuenta) {
            return $politica->topePara(null);
        }

        return $politica->topeEnLaCuenta(
            $cuenta,
            CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
            now(),
        );
    }

    /**
     * Corto a propósito: el porqué está en el código y en el mensaje de
     * error, no debajo de un campo que alguien mira de reojo entre dos
     * pacientes. Acá solo va el número que necesita para decidir.
     */
    public function ayudaDelDescuento(?Cuenta $cuenta): string
    {
        $tope = $this->topeDeDescuento($cuenta);

        if ($tope->esCero()) {
            return 'No aplica: ya recibe el descuento de ley más alto.';
        }

        $caso = $cuenta instanceof Cuenta ? $cuenta->encuentro->persona->rangoDeEdadEn(now()) : null;

        /*
         * «Aplica a lo que agregues» y no «aplica a la cuenta»: los cargos
         * que ya están asentados NO se tocan. Un cargo asentado tiene su
         * movimiento de kardex y su fila en la bitácora; cambiarlo por
         * atrás dejaría dos verdades sobre a cuánto se vendió. Si hay que
         * rehacer una línea, se anula y se vuelve a cargar — y ahí queda
         * escrito que se rehizo.
         */
        if (! $caso instanceof RangoEdad || ! $caso->tieneDescuentoLegal() || ! $cuenta instanceof Cuenta) {
            return 'Hasta '.$tope->comoPorcentaje().'.';
        }

        return $cuenta->convenio->aplicaDescuentoLegal()
            ? 'Hasta '.$tope->comoPorcentaje().', además del de ley.'
            : 'Hasta '.$tope->comoPorcentaje().'. Este pagador no da el de ley: es todo su descuento.';
    }

    /**
     * ¿El campo del descuento trae algo distinto de cero?
     *
     * Estático y con `mixed` porque Livewire manda el número tecleado
     * como float, como entero o como cadena según el momento, y las tres
     * formas significan lo mismo (§9: un conversor de formulario nunca
     * decide por su cuenta que «no entiendo esto» vale cero).
     */
    public static function hayDescuento(mixed $porcentaje): bool
    {
        $fraccion = NumeroDeFormulario::aDecimal($porcentaje);

        return $fraccion instanceof Decimal && ! $fraccion->esCero() && ! $fraccion->esNegativo();
    }

    /**
     * Las formas de agregar el producto, rotuladas para «¿Cómo se le
     * agrega?». Qué formas existen lo decide `FormasDeCobro`; acá solo se
     * les pone nombre.
     *
     * @return array<string, string>
     */
    public function unidadesDeCobro(?Item $item): array
    {
        if (! $item instanceof Item) {
            return [];
        }

        $presentaciones = $this->presentacionesDe($item);

        $opciones = [];

        foreach (app(FormasDeCobro::class)->de($item, $presentaciones) as $clave) {
            $opciones[$clave] = $this->rotuloDeLaForma($item, $clave);
        }

        return $opciones;
    }

    /**
     * «TABLETA», «MILILITRO · 120 por FRASCO», «CAJA X 100».
     *
     * Cortos a propósito: van uno al lado del otro en un control
     * agrupado, y con «· sin abrir» repetido en cada envase el control
     * se partía en dos renglones. Que el envase se vende cerrado lo dice
     * una sola vez la ayuda de abajo, y la cuenta —«1 CAJA X 100 = 100
     * TAB»— el recuadro antes de Agregar.
     */
    private function rotuloDeLaForma(Item $item, string $clave): string
    {
        if ($clave === self::POR_UNIDAD) {
            /*
             * La columna y no `?->`: Larastan tipa la relación como no nula
             * aunque la FK sí lo sea (§9.B1).
             */
            return $item->unidad_dispensacion_id === null ? 'UNIDAD' : $item->unidadDispensacion->nombre;
        }

        if ($clave === self::POR_FRACCION) {
            return $item->unidadFraccion->nombre
                .' · '.ItemPresentacion::sinCerosDeMas((string) $item->fracciones_por_unidad)
                .' por '.(self::codigoDeLaUnidad($item) ?? 'unidad');
        }

        $presentacion = $this->presentacionDeLaUnidad($item, $clave);

        if (! $presentacion instanceof ItemPresentacion) {
            return $clave;
        }

        return $presentacion->unidad->codigo.' X '
            .ItemPresentacion::sinCerosDeMas((string) $presentacion->unidades_por_presentacion);
    }

    /**
     * Un dibujo por forma: el cuadrito suelto, el vaso de lo que se mide,
     * la caja de lo que va cerrado. Se distinguen de un vistazo sin leer
     * el rótulo entero.
     *
     * @return array<string, Heroicon>
     */
    private function iconosDeLasFormas(?Item $item): array
    {
        $iconos = [];

        foreach (array_keys($this->unidadesDeCobro($item)) as $clave) {
            $iconos[$clave] = match ($clave) {
                self::POR_UNIDAD   => Heroicon::OutlinedStop,
                self::POR_FRACCION => Heroicon::OutlinedBeaker,
                default            => Heroicon::OutlinedArchiveBox,
            };
        }

        return $iconos;
    }

    /**
     * Lo que va pegado al número de la cantidad: TAB, CAJA, ML.
     */
    private function enQueSeCuenta(Get $get): ?string
    {
        $item = $this->itemDe($get('item_id'));
        $forma = $this->formaEnUso($get);

        if (! $item instanceof Item || $forma === null) {
            return null;
        }

        if ($forma === self::POR_UNIDAD) {
            return self::codigoDeLaUnidad($item);
        }

        if ($forma === self::POR_FRACCION) {
            return $item->unidadFraccion->codigo;
        }

        return $this->presentacionDeLaUnidad($item, $forma)?->unidad->codigo;
    }

    /**
     * Cómo se llama el envase sin repetir el producto: «FRASCO 60 ML»,
     * «CAJA X 100 TABLETAS».
     *
     * El producto ya está escrito arriba, en lo que quedó elegido, así
     * que acá va solo el envase. El recorte del nombre vive una sola vez,
     * en `ItemPresentacion::envase()`; lo único que queda acá es
     * anteponer la unidad cuando el nombre no la trae: una presentación
     * cargada como «X 100 TABLETAS» con envase CAJA se lee «CAJA X 100
     * TABLETAS», que es lo que hay en el estante.
     */
    private static function envaseCorto(ItemPresentacion $presentacion): string
    {
        $envase = $presentacion->envase();
        $unidad = $presentacion->unidad->codigo;

        return str_contains($envase, $unidad) ? $envase : trim($unidad.' '.$envase);
    }

    /**
     * La forma, cuando hay una sola y no hay nada que preguntar.
     */
    private function formaUnicaDe(Item $item): ?string
    {
        return app(FormasDeCobro::class)->unica($item, $this->presentacionesDe($item));
    }

    /**
     * La forma con la que se asienta la línea: la elegida si es de este
     * producto, la única si hay una sola, y si no, ninguna.
     *
     * ⚠️ Una forma que no está en la lista de este producto no se
     * corrige a otra: viene pegada del producto anterior o armada a mano
     * en el cliente, y reemplazarla en silencio es elegir por la persona.
     */
    private function formaElegida(Item $item, mixed $elegida): ?string
    {
        if (is_string($elegida) && isset($this->unidadesDeCobro($item)[$elegida])) {
            return $elegida;
        }

        return $this->formaUnicaDe($item);
    }

    /**
     * En qué forma se está agregando la línea que se está tecleando, o
     * nulo mientras la persona no la elija.
     */
    private function formaEnUso(Get $get): ?string
    {
        $item = $this->itemDe($get('item_id'));

        return $item instanceof Item ? $this->formaElegida($item, $get('unidad_cobro')) : null;
    }

    /**
     * Una presentación de este producto, por id. Nulo si no es suya.
     */
    private function presentacionDe(Item $item, ?int $id): ?ItemPresentacion
    {
        if ($id === null) {
            return null;
        }

        $presentacion = $this->presentacionesDe($item)->firstWhere('id', $id);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    /**
     * Las presentaciones del ítem, en orden de contenido.
     *
     * @return ColeccionDeModelos<int, ItemPresentacion>
     */
    private function presentacionesDe(Item $item): ColeccionDeModelos
    {
        /*
         * 🔴 Memoria por pintada, por la misma razón que los estantes.
         * Las formas, sus rótulos, la conversión y los estantes preguntan
         * esto varias veces por render; sin la memoria son varias
         * consultas por cada pausa del teclado.
         */
        if (isset($this->presentacionesPorItem[$item->id])) {
            return $this->presentacionesPorItem[$item->id];
        }

        return $this->presentacionesPorItem[$item->id] = ItemPresentacion::query()
            /*
             * `contiene_envases` va en la lista: con columnas nombradas,
             * la que falta llega nula sin error (§9.M-6), y sin ella
             * ningún blíster saldría de un lote de cajas.
             */
            ->with('unidad:id,codigo,nombre,contiene_envases')
            ->where('item_id', $item->id)
            ->orderBy('unidades_por_presentacion')
            ->get();
    }

    /**
     * Lo tecleado, llevado a unidades de dispensación. La regla vive en
     * `FormasDeCobro`: el envase sin abrir multiplica por lo que trae.
     */
    private function aUnidadesDeDispensacion(Item $item, string $forma, Decimal $cantidad): ?Decimal
    {
        return app(FormasDeCobro::class)->aUnidadesDeDispensacion(
            $item,
            $forma,
            $cantidad,
            $this->presentacionesDe($item),
        );
    }

    /**
     * Lo tecleado en el campo, ya en la unidad del kardex. Nulo si no se
     * entiende, si no es positivo o si la forma no aplica.
     */
    private function loPedidoEnDispensacion(Item $item, string $forma, mixed $cantidad): ?Decimal
    {
        $tecleada = NumeroDeFormulario::aDecimal($cantidad);

        if (! $tecleada instanceof Decimal || ! $tecleada->mayorQue('0')) {
            return null;
        }

        return $this->aUnidadesDeDispensacion($item, $forma, $tecleada);
    }

    /**
     * «2 CAJA X 100 TABLETAS sin abrir = 200 TAB», para leerlo antes de
     * apretar.
     *
     * El «sin abrir» vivía en un texto de ayuda debajo de «¿Cómo se le
     * agrega?», y ocupaba un renglón entero para decir algo que solo
     * importa al leer la cuenta. Acá va pegado a la cuenta.
     */
    private function equivalencia(Item $item, string $forma, mixed $cantidad): ?string
    {
        $codigo = self::codigoDeLaUnidad($item) ?? $this->unidadDe($item) ?? 'unidades';

        /*
         * La unidad suelta no tiene cuenta que hacer: el sufijo del campo
         * ya dice TAB, y «En TABLETA (tab).» era repetirlo.
         */
        if ($forma === self::POR_UNIDAD) {
            return null;
        }

        $tecleada = NumeroDeFormulario::aDecimal($cantidad);

        if (! $tecleada instanceof Decimal) {
            return null;
        }

        $convertida = $this->aUnidadesDeDispensacion($item, $forma, $tecleada);

        if (! $convertida instanceof Decimal) {
            return null;
        }

        $numero = ItemPresentacion::sinCerosDeMas($tecleada->redondeado(4));
        $presentacion = $this->presentacionDeLaUnidad($item, $forma);

        $enQue = $presentacion instanceof ItemPresentacion
            ? self::envaseCorto($presentacion)
            : $item->unidadFraccion->codigo;

        if (app(FormasDeCobro::class)->esEnvaseEntero($forma, $this->presentacionesDe($item))) {
            $enQue .= ' sin abrir';
        }

        return sprintf(
            '%s %s = %s %s',
            $numero,
            $enQue,
            ItemPresentacion::sinCerosDeMas($convertida->redondeado(4)),
            /*
             * El CÓDIGO y no la etiqueta completa: «60 ml» en vez de «60
             * MILILITRO (ml)». Es la línea que va debajo de un campo
             * angosto, y la palabra larga la partía en tres.
             */
            $codigo,
        );
    }

    /**
     * En qué unidad se cuenta lo que se está cargando.
     *
     * El kardex y la cuenta se llevan SIEMPRE en unidad de dispensación,
     * nunca en envases (§8.7). Decirlo en pantalla es lo que evita que
     * alguien escanee una caja de cien y teclee «1» pensando en la caja.
     */
    private function unidadDe(Item $item): ?string
    {
        if ($item->unidad_dispensacion_id === null) {
            return null;
        }

        return $item->unidadDispensacion->etiqueta();
    }

    private function itemDe(mixed $valor): ?Item
    {
        $id = self::idDelItem($valor);

        if ($id === null) {
            return null;
        }

        if (array_key_exists($id, $this->itemsPorId)) {
            return $this->itemsPorId[$id];
        }

        $item = Item::query()->find($id);

        return $this->itemsPorId[$id] = $item instanceof Item ? $item : null;
    }

    /**
     * El id del ítem elegido. Llega como entero desde el código y como
     * cadena desde el navegador; cualquier otra cosa no es un ítem.
     */
    private static function idDelItem(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tituloDelModal(array $arguments): string
    {
        $cuenta = $this->cuentaDe($arguments);

        if (! $cuenta instanceof Cuenta) {
            return 'Agregar a la cuenta';
        }

        return $cuenta->encuentro->persona->nombreCompleto().' · '.$cuenta->numero;
    }

    /**
     * Los expedientes del paciente en esta sede (el scope de sesión de
     * `Expediente` filtra la sede), el más nuevo primero.
     *
     * @return array<int, string>
     */
    private function expedientesDe(mixed $personaId): array
    {
        if (! is_numeric($personaId)) {
            return [];
        }

        $clave = (int) $personaId;

        if (array_key_exists($clave, $this->expedientesPorPersona)) {
            return $this->expedientesPorPersona[$clave];
        }

        /** @var array<int, string> $expedientes */
        $expedientes = Expediente::query()
            ->where('persona_id', $clave)
            ->orderByDesc('abierto_el')
            ->pluck('numero', 'id')
            ->all();

        return $this->expedientesPorPersona[$clave] = $expedientes;
    }

    /**
     * El expediente que se marca solo al elegir al paciente: el único que
     * tiene. Con varios no se adivina; con ninguno no hay qué marcar.
     */
    private function expedienteUnicoDe(mixed $personaId): ?int
    {
        $expedientes = $this->expedientesDe($personaId);

        return count($expedientes) === 1 ? array_key_first($expedientes) : null;
    }

    /**
     * La ficha del paciente elegido en el modal «Abrir cuenta», o null si
     * no hay ninguno (o el id no existe).
     *
     * @return array{iniciales: string, nombre: string, nn: bool, datos: list<string>}|null
     */
    private function fichaDelPaciente(mixed $personaId): ?array
    {
        if (! is_numeric($personaId)) {
            return null;
        }

        $clave = (int) $personaId;

        if (array_key_exists($clave, $this->fichasDelPaciente)) {
            return $this->fichasDelPaciente[$clave];
        }

        $persona = Persona::query()->with('identificadores')->find($clave);

        return $this->fichasDelPaciente[$clave] = $persona instanceof Persona
            ? FichaDelPaciente::de($persona, now())
            : null;
    }

    /**
     * @return Collection<int, Convenio>
     */
    private function pagadoresDelModal(): Collection
    {
        return $this->pagadoresDelModal ??= app(AbridorDeEncuentro::class)->pagadoresDisponibles();
    }

    /**
     * Los pagadores agrupados por clase —Contado, Aseguradora privada,
     * Seguro externo…—, en el orden en que los da `pagadoresDisponibles`:
     * contado primero.
     *
     * @return array<string, array<int, string>>
     */
    private function pagadoresPorClase(): array
    {
        $grupos = [];

        foreach ($this->pagadoresDelModal() as $convenio) {
            $grupos[$convenio->tipo->etiqueta()][$convenio->id] = $convenio->nombre;
        }

        return $grupos;
    }

    private function pagadorDe(mixed $convenioId): ?Convenio
    {
        if (! is_numeric($convenioId)) {
            return null;
        }

        return $this->pagadoresDelModal()->firstWhere('id', (int) $convenioId);
    }

    /**
     * La condición de JavaScript que muestra un campo según el pagador
     * elegido, sin ir al servidor: los ids de los pagadores para los que
     * `$pide` dice que sí. Son enteros de la base, nunca algo tecleado
     * (`visibleJs` se evalúa en el navegador), y van entre comillas
     * simples porque Filament la escribe dentro de un atributo con
     * comillas dobles.
     *
     * @param callable(int): bool $pide
     */
    private function siElPagadorPide(callable $pide): string
    {
        $ids = $this->pagadoresDelModal()
            ->filter(fn (Convenio $convenio): bool => $pide($convenio->id))
            ->map(fn (Convenio $convenio): string => "'".$convenio->id."'")
            ->implode(',');

        return "[{$ids}].includes(String(\$get('convenio_id') ?? ''))";
    }

    /**
     * La póliza, con todo pagador que no sea el contado: también con el
     * seguro externo, que no paga pero sale en la factura con su póliza
     * para que el paciente reclame (`facturaANombreDelSeguro()`).
     */
    private function pideLaPoliza(mixed $convenioId): bool
    {
        return $this->pagadorDe($convenioId)?->tipo->facturaANombreDelSeguro() ?? false;
    }

    /**
     * La autorización, solo cuando paga un tercero: al seguro externo el
     * hospital no le pide nada, le cobra al paciente.
     */
    private function pideLaAutorizacion(mixed $convenioId): bool
    {
        return $this->pagadorDe($convenioId)?->tipo->pagaUnTercero() ?? false;
    }

    /**
     * Lo escrito en el campo: un código elige el producto, un nombre
     * busca, y la etiqueta de una gaveta acota.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EN ESTE HOSPITAL SE ESCANEAN DOS COSAS DISTINTAS
     * ─────────────────────────────────────────────────────────────────
     *
     *   · EL ENVASE — la caja, el frasco, el blíster. Ese código
     *     identifica UN producto, así que lo elige y listo.
     *   · LA GAVETA — la etiqueta del principio activo, `PA-0001`. Esa
     *     no identifica un producto: identifica una molécula, y el
     *     acetaminofén vive en tableta, en jarabe y en supositorio.
     *
     * Confundirlas sería lo peor que puede hacer esta pantalla: elegir
     * «el primero que aparezca» con el paciente enfrente es dispensar
     * una forma farmacéutica por otra. Por eso la gaveta no elige, acota
     * — y quien dispensó dice cuál fue.
     *
     * ⚠️ Corre en cada pausa del teclado, así que NO manda
     * notificaciones: lo que haya que decir va debajo del campo.
     */
    private function resolverEscaneo(mixed $codigo, Set $set): void
    {
        $this->principioEscaneado = null;
        $this->presentacionEscaneada = null;
        $this->busquedaEscrita = null;
        $this->avisoDelBuscador = null;

        $texto = is_string($codigo) ? trim($codigo) : '';

        /*
         * Borrar el campo es empezar de nuevo: lo elegido se suelta. Si
         * no, el producto anterior queda puesto debajo de un campo vacío
         * y Agregar cobra algo que ya no se ve.
         */
        if ($texto === '') {
            $this->soltarLoElegido($set);

            return;
        }

        if (PrincipioActivo::pareceUnCodigoSuyo($texto)) {
            $this->resolverGaveta($texto, $set);

            return;
        }

        $item = $this->itemPorCodigo($texto);

        /*
         * No era un código: se busca por nombre. El mismo campo sirve
         * para las dos cosas, que es como se usa de verdad — la pistola
         * escribe un código y una persona escribe «acetamin».
         */
        if (! $item instanceof Item) {
            $this->buscarPorNombre($texto, $set);

            return;
        }

        /*
         * ⚠️ El envase leído queda como ORIGEN, no como forma de cobro.
         * Pasar la pistola por una caja de cien no es venderla: si se
         * venden dos tabletas, el cargo dice que salieron de esa caja; si
         * se vende la caja, lo elige quien cobra.
         */
        $presentacion = $this->presentacionPorCodigo($texto);

        $this->presentacionEscaneada = $presentacion instanceof ItemPresentacion
            && $presentacion->item_id === $item->id
                ? $presentacion->id
                : null;

        $this->elegir($item->id, $set);
    }

    /**
     * Deja el producto elegido, con la línea lista para escribir la
     * cantidad.
     */
    private function elegir(int $id, Set $set): void
    {
        $set('item_id', $id);

        /*
         * ⚠️ La cantidad queda en UNO, no en el contenido de la caja.
         *
         * Poner 100 al escanear una caja de 100 tabletas sería cobrarle
         * una caja entera al paciente por pasar la pistola. El escaneo
         * dice QUÉ es; cuánto, y si es la caja o la tableta, lo dice la
         * persona.
         */
        $set('cantidad', 1);

        $this->prepararLaLinea($id, $set);
    }

    private function soltarLoElegido(Set $set): void
    {
        $set('item_id', null);
        $set('unidad_cobro', null);
        $set('almacen_id', null);
    }

    /**
     * La etiqueta de la gaveta: acota la lista a lo que lleva ese
     * principio activo, y solo elige cuando no hay nada que elegir.
     */
    private function resolverGaveta(string $codigo, Set $set): void
    {
        $this->soltarLoElegido($set);

        $principio = PrincipioActivo::query()
            ->whereRaw('upper(codigo) = ?', [mb_strtoupper($codigo)])
            ->first();

        if (! $principio instanceof PrincipioActivo) {
            $this->avisoDelBuscador = 'Ningún principio activo tiene la etiqueta «'.$codigo.'». Si es de una '
                .'gaveta vieja, reimprimila desde Farmacia y servicios → Principios activos.';

            return;
        }

        $productos = $principio->productosVigentes();

        if ($productos->isEmpty()) {
            $this->avisoDelBuscador = 'La gaveta de '.$principio->nombre.' está etiquetada, pero hoy ningún '
                .'producto lo lleva. Se vincula desde la ficha del producto, en «Principios activos».';

            return;
        }

        /*
         * Uno solo no es una elección: es el producto. Obligar a tocar un
         * botón de una sola opción es un clic que no decide nada.
         */
        if ($productos->count() === 1) {
            $this->elegir((int) $productos->firstOrFail()->getKey(), $set);

            return;
        }

        $this->principioEscaneado = (int) $principio->getKey();
    }

    /**
     * Lo escrito no era un código: se busca en el catálogo.
     *
     *   · Un solo resultado → se elige solo. Escribir «hemogr» y apretar
     *     Enter carga el hemograma sin tocar el mouse.
     *   · Varios            → aparecen debajo del campo, para tocar uno.
     *   · Ninguno           → se dice que no hay, y por qué puede ser.
     */
    private function buscarPorNombre(string $texto, Set $set): void
    {
        $this->soltarLoElegido($set);

        /*
         * Con menos de tres letras no se busca: «ac» trae medio catálogo
         * y nadie lo va a leer. Tres letras es lo que pide el texto del
         * campo.
         */
        if (mb_strlen($texto) < 3) {
            return;
        }

        $encontrados = $this->buscarEnElCatalogo($texto);

        if ($encontrados === []) {
            $this->avisoDelBuscador = 'No hay nada con «'.$texto.'», ni como código ni como nombre. Si el '
                .'producto existe, falta cargarle el código de barras en su ficha, en Identificación.';

            return;
        }

        if (count($encontrados) === 1) {
            $this->elegir((int) array_key_first($encontrados), $set);

            return;
        }

        $this->busquedaEscrita = $texto;
    }

    /**
     * Lo que se ofrece debajo del campo: los productos de la gaveta
     * escaneada, o los que encontró lo escrito.
     *
     * @return array<int, string>
     */
    private function resultadosDelBuscador(): array
    {
        if ($this->principioEscaneado !== null) {
            $clave = 'p:'.$this->principioEscaneado;

            if (! array_key_exists($clave, $this->resultadosDelBuscador)) {
                $principio = PrincipioActivo::query()->find($this->principioEscaneado);

                $this->resultadosDelBuscador[$clave] = $principio instanceof PrincipioActivo
                    ? $principio->productosVigentes()
                        ->mapWithKeys(fn (Item $item): array => [$item->id => $item->nombre])
                        ->all()
                    : [];
            }

            return $this->resultadosDelBuscador[$clave];
        }

        return $this->busquedaEscrita === null
            ? []
            : $this->buscarEnElCatalogo($this->busquedaEscrita);
    }

    /**
     * Productos vigentes y del área de quien cobra, por nombre o código.
     *
     * @return array<int, string>
     */
    private function buscarEnElCatalogo(string $texto): array
    {
        $clave = 't:'.$texto;

        if (array_key_exists($clave, $this->resultadosDelBuscador)) {
            return $this->resultadosDelBuscador[$clave];
        }

        return $this->resultadosDelBuscador[$clave] = Item::buscar(
            $texto,
            limite: self::resultadosALaVista(),
            soloVigentes: true,
            soloDelRol: true,
        )
            ->mapWithKeys(fn (Item $item): array => [$item->id => $item->nombre])
            ->all();
    }

    /**
     * Un botón por resultado. El elegido va marcado, y los demás siguen
     * ahí para cambiar de idea sin volver a escribir.
     *
     * ─────────────────────────────────────────────────────────────────
     * ⚠️ CASILLEROS FIJOS, NO UNA LISTA QUE SE ARMA CON LO ESCRITO
     * ─────────────────────────────────────────────────────────────────
     *
     * Filament guarda en memoria los hijos de un componente durante la
     * petición. Con la lista armada a partir de lo escrito, los botones
     * se calculaban ANTES de que corriera la búsqueda —al ubicar el campo
     * que cambió— y la pantalla mostraba los resultados de la tecla
     * anterior.
     *
     * Así son siempre los mismos casilleros, y lo que cambia —el rótulo,
     * si se ve, si es el elegido— se pregunta al pintar, cuando la
     * búsqueda ya corrió.
     *
     * @return list<Action>
     */
    private function botonesDelBuscador(): array
    {
        $botones = [];

        for ($casillero = 0; $casillero < self::resultadosALaVista(); $casillero++) {
            $botones[] = Action::make('elegir_resultado_'.$casillero)
                ->label(fn (): string => $this->resultadoEn($casillero)['nombre'] ?? '')
                ->visible(fn (): bool => $this->resultadoEn($casillero) !== null)
                ->size(Size::Small)
                ->color(fn (Get $get): string => $this->esElElegido($casillero, $get) ? 'primary' : 'gray')
                ->outlined(fn (Get $get): bool => ! $this->esElElegido($casillero, $get))
                ->icon(fn (Get $get): ?Heroicon => $this->esElElegido($casillero, $get) ? Heroicon::CheckCircle : null)
                ->action(function (Set $set) use ($casillero): void {
                    $resultado = $this->resultadoEn($casillero);

                    if ($resultado !== null) {
                        $this->elegir($resultado['id'], $set);
                    }
                });
        }

        return $botones;
    }

    private static function resultadosALaVista(): int
    {
        return max(1, (int) config('sihla.facturacion.resultados_de_busqueda', 12));
    }

    /**
     * @return array{id: int, nombre: string}|null
     */
    private function resultadoEn(int $casillero): ?array
    {
        $resultados = $this->resultadosDelBuscador();
        $id = array_keys($resultados)[$casillero] ?? null;

        return $id === null ? null : ['id' => $id, 'nombre' => $resultados[$id]];
    }

    private function esElElegido(int $casillero, Get $get): bool
    {
        $resultado = $this->resultadoEn($casillero);

        return $resultado !== null && $resultado['id'] === self::idDelItem($get('item_id'));
    }

    /**
     * La ficha corta de lo que quedó elegido, debajo del buscador:
     * «MED-101 · leído de CAJA X 100 TABLETAS · hay 230 TAB».
     *
     * ⚠️ El nombre va solo cuando NO hay lista de resultados —se escaneó,
     * o la búsqueda dio uno—. Con la lista a la vista, el nombre ya está
     * en el botón marcado, y repetirlo abajo es leer lo mismo dos veces.
     *
     * ⚠️ Sin recuadro. Con borde, un renglón que decía solo «MED-101» se
     * veía igual que un campo vacío, y daban ganas de escribirle encima.
     */
    private function resumenDeLoElegido(Get $get): HtmlString
    {
        $item = $this->itemDe($get('item_id'));

        if (! $item instanceof Item) {
            return new HtmlString('&nbsp;');
        }

        $detalles = [];

        if ($item->codigo !== '') {
            $detalles[] = e($item->codigo);
        }

        $leida = $this->presentacionDe($item, $this->presentacionEscaneada);

        if ($leida instanceof ItemPresentacion) {
            $detalles[] = 'leído de '.e(self::envaseCorto($leida));
        }

        /*
         * Cómo se cobra, solo cuando no hay que preguntarlo. Con la
         * tableta y la caja la respuesta está en el control de abajo, y
         * escribir una acá antes de que la persona elija sería afirmar
         * algo que todavía no se decidió.
         */
        $unica = $this->formaUnicaDe($item);

        if ($unica !== null) {
            $detalles[] = 'se cobra por '.e($this->rotuloDeLaForma($item, $unica));
        }

        if ($item->mueveInventario()) {
            /*
             * ⚠️ Se DICE que el hueco existe. Un producto de farmacia que
             * solo se ofrece en su unidad mínima es, casi siempre, uno al
             * que nadie le cargó las presentaciones.
             */
            if ($this->presentacionesDe($item)->isEmpty()) {
                $detalles[] = '<span style="color:rgb(217 119 6)">sin envases cargados en la ficha</span>';
            }

            $hay = $this->existenciaTotal($item);

            if ($hay->mayorQue('0')) {
                $detalles[] = 'hay '.self::cifraCorta($hay).' '.e(self::codigoDeLaUnidad($item) ?? '');
            }

            if ($item->tieneStockInfinito()) {
                $detalles[] = '<span style="color:rgb(217 119 6)">stock infinito</span>';
            }
        }

        $nombre = $this->resultadosDelBuscador() === []
            ? '<div style="font-weight:600;font-size:.9375rem;line-height:1.3">'.e($item->nombre).'</div>'
            : '';

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:.125rem">'
            .$nombre
            .'<div style="font-size:.8125rem;opacity:.7">'.implode(' · ', $detalles).'</div>'
            .'</div>'
        );
    }

    /**
     * Cuánto hay del producto en todos los estantes que esta persona
     * puede usar, en la unidad del kardex. Sale de la misma memoria que el
     * desplegable de estantes, así que no cuesta una consulta más.
     */
    private function existenciaTotal(Item $item): Decimal
    {
        $total = Decimal::cero();

        foreach ($this->estantesDe($item) as $estante) {
            $total = $total->sumar($estante['hay']);
        }

        return $total;
    }

    /**
     * El recuadro de lo que hay que leer antes de apretar Agregar.
     *
     * Con estilos en línea porque el CSS del panel viene compilado y una
     * clase de Tailwind nueva no existe ahí (§9.A7). Los colores son
     * transparencias, así que se leen igual en el tema claro y en el
     * oscuro.
     */
    private static function recuadro(string $contenido, bool $esAlerta = false): HtmlString
    {
        $colores = $esAlerta
            ? 'background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35);color:rgb(239 68 68)'
            : 'background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.30)';

        return new HtmlString(
            '<div style="'.$colores.';border-radius:.625rem;padding:.625rem .875rem;'
            .'font-size:.8125rem;line-height:1.45">'.$contenido.'</div>'
        );
    }

    /**
     * La cantidad de la línea, leída en envases: «1 BLISTER X 10 + 5 TAB».
     *
     * Es la traducción de un número a lo que la persona tiene enfrente en
     * el estante, y por eso recibe la cantidad YA convertida a la unidad
     * del kardex.
     */
    private function enEnvases(Item $item, Decimal $cantidad): ?string
    {
        return app(LecturaEnEnvases::class)->de($item, $cantidad);
    }

    /**
     * De qué lote sale, con su vencimiento. Null si no se puede saber.
     *
     * Recorre los lotes en el orden en que FEFO los va a consumir y se
     * detiene cuando ya cubrió lo pedido, así que una cantidad que abarca
     * dos lotes los nombra a los dos: es exactamente lo que la persona
     * tiene que ir a buscar al estante.
     *
     * ⚠️ Con la cantidad CONVERTIDA. Una caja de 100 sin abrir son 100
     * tabletas, y con el «1» tecleado el texto decía de qué lote sale UNA
     * tableta.
     */
    private function deQueLoteSale(Item $item, Get $get, string $forma): ?string
    {
        $almacen = $this->almacenElegido($get, $item, $forma);

        if (! $almacen instanceof Almacen) {
            return null;
        }

        $pedida = $this->loPedidoEnDispensacion($item, $forma, $get('cantidad'));

        if (! $pedida instanceof Decimal) {
            return null;
        }

        $lotes = app(ConsultorDeExistencias::class)->enOrdenFefo($item, $almacen);

        /*
         * El envase sin abrir sale con la misma regla que en
         * `RegistradorDeCargo`: entero, y de un lote que entró en ese
         * envase o en uno que lo trae adentro. Si acá se nombraran otros,
         * la pantalla mandaría a buscar la caja a un lote de blísteres, o
         * a la caja que ya está abierta.
         */
        $formas = app(FormasDeCobro::class);
        $presentaciones = $this->presentacionesDe($item);

        $sinAbrir = $formas->esEnvaseEntero($forma, $presentaciones)
            ? $this->presentacionDeLaUnidad($item, $forma)
            : null;

        $nombres = [];
        $restante = $pedida;

        foreach ($lotes as $saldo) {
            if (! $restante->mayorQue('0')) {
                break;
            }

            $lote = $saldo->lote;
            $hay = $saldo->cantidadDecimal();
            $delEnvase = $saldo->lote_id === null ? null : $lote->item_presentacion_id;

            if ($sinAbrir instanceof ItemPresentacion) {
                $hay = $formas->sinAbrirEnElLote($sinAbrir, $delEnvase, $hay, $presentaciones);

                if (! $hay->mayorQue('0')) {
                    continue;
                }
            }

            $sale = $hay->menorQue($restante) ? $hay : $restante;

            $nombres[] = ($lote instanceof Lote
                ? 'lote '.$lote->numero.($lote->fecha_vencimiento instanceof CarbonInterface
                    ? ' (vence '.$lote->fecha_vencimiento->format('d/m/Y').')'
                    : '')
                : 'sin lote')
                .$this->deQueEnvaseSeSaca($sinAbrir, $delEnvase, $presentaciones);

            $restante = $restante->restar($sale);
        }

        if ($nombres === []) {
            return null;
        }

        return count($nombres) === 1
            ? 'sacalo del '.$nombres[0]
            : 'sale de '.implode(' y después ', $nombres);
    }

    /**
     * «, de una CAJA X 100» cuando el blíster sin abrir sale de un lote
     * que entró en cajas: quien va al estante tiene que saber que hay que
     * abrir una caja, y no buscar un blíster suelto que no está.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    private function deQueEnvaseSeSaca(?ItemPresentacion $sinAbrir, ?int $delEnvase, Collection $presentaciones): string
    {
        if (! $sinAbrir instanceof ItemPresentacion || $delEnvase === null || $delEnvase === $sinAbrir->id) {
            return '';
        }

        $grande = $presentaciones->firstWhere('id', $delEnvase);

        return $grande instanceof ItemPresentacion ? ', de una '.self::envaseCorto($grande) : '';
    }

    /**
     * El almacén de la línea: el elegido, o el que se preseleccionó.
     */
    private function almacenElegido(Get $get, Item $item, ?string $unidadDeCobro): ?Almacen
    {
        $elegido = $get('almacen_id');

        if (is_numeric($elegido)) {
            $almacen = Almacen::query()->find((int) $elegido);

            return $almacen instanceof Almacen ? $almacen : null;
        }

        $estantes = $this->estantesConLoQueHay($item, $unidadDeCobro);

        if ($estantes === []) {
            return null;
        }

        $primero = array_key_first($estantes);
        $almacen = is_numeric($primero) ? Almacen::query()->find((int) $primero) : null;

        return $almacen instanceof Almacen ? $almacen : null;
    }

    /**
     * Lo que hay que leer antes de apretar Agregar: la equivalencia, la
     * cantidad leída en envases, y de qué lote hay que sacarlo.
     *
     * Vive en un solo lugar para que esté todo junto y para que los
     * campos de arriba queden del mismo alto.
     */
    private function comoQuedaLaLinea(Get $get): HtmlString
    {
        $item = $this->itemDe($get('item_id'));
        $forma = $this->formaEnUso($get);

        if (! $item instanceof Item || $forma === null) {
            return new HtmlString('&nbsp;');
        }

        $partes = [];

        $equivalencia = $this->equivalencia($item, $forma, $get('cantidad'));

        if (is_string($equivalencia) && trim($equivalencia) !== '') {
            $partes[] = '<strong>'.e(rtrim($equivalencia, '.')).'</strong>';
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * LA CANTIDAD, LEÍDA EN ENVASES
         * ─────────────────────────────────────────────────────────────
         *
         * «15 TAB» no le dice nada a quien está frente al estante;
         * «1 BLISTER X 10 + 5 TAB» sí. Es una LECTURA y no un saldo: el
         * kardex sigue en la unidad mínima.
         *
         * Sobra cuando lo que se vende ES el envase sin abrir: «1 CAJA X
         * 100 = 100 TAB · 1 CAJA X 100» dice dos veces lo mismo.
         */
        $pedida = $this->loPedidoEnDispensacion($item, $forma, $get('cantidad'));

        if ($pedida instanceof Decimal
            && ! app(FormasDeCobro::class)->esEnvaseEntero($forma, $this->presentacionesDe($item))
        ) {
            $enEnvases = $this->enEnvases($item, $pedida);

            if (is_string($enEnvases)) {
                $partes[] = e($enEnvases);
            }
        }

        /*
         * Stock infinito: no hay lote que buscar ni existencia que falte.
         * Se dice qué pasa con lo que se carga (13-oct-2026).
         */
        if ($item->tieneStockInfinito()) {
            $sinEseEnvase = $this->envaseQueNoEstaEnNingunLado($item, $forma);

            if ($sinEseEnvase instanceof ItemPresentacion) {
                return self::recuadro(e($this->porQueNoHayEseEnvase($sinEseEnvase)), esAlerta: true);
            }

            $partes[] = 'stock infinito: no descuenta del inventario, queda registrado lo que se usó';

            return self::recuadro(implode(' · ', $partes));
        }

        if ($item->mueveInventario()) {
            if ($this->estantesConLoQueHay($item, $forma) === []) {
                return self::recuadro($this->porQueNoHay($item, $forma), esAlerta: true);
            }

            /*
             * ─────────────────────────────────────────────────────────
             * DE QUÉ LOTE HAY QUE SACARLO, CON NOMBRE Y VENCIMIENTO
             * ─────────────────────────────────────────────────────────
             *
             * La RESPUESTA y no la regla: quien está por caminar al
             * estante no necesita que le expliquen FEFO, necesita saber
             * cuál caja agarrar. El sistema ya eligió —`enOrdenFefo()` lo
             * resuelve antes de guardar—, así que decirlo evita el error
             * que sí cuesta: sacar del lote equivocado y dejar venciendo
             * el que había que gastar.
             */
            $delLote = $this->deQueLoteSale($item, $get, $forma);

            $partes[] = $delLote ?? 'sale por FEFO: primero el lote que vence antes';
        }

        if ($partes === []) {
            return new HtmlString('&nbsp;');
        }

        return self::recuadro(implode(' · ', $partes));
    }

    /**
     * Stock infinito: el envase sin abrir que se eligió, cuando no hay de
     * ese envase en ninguno de los estantes donde está el producto. Nulo si
     * hay, si no se eligió un envase, o si quien carga no ve ningún estante
     * donde esté —ahí se carga sin almacén, como siempre—.
     */
    private function envaseQueNoEstaEnNingunLado(Item $item, ?string $forma): ?ItemPresentacion
    {
        if (! $item->tieneStockInfinito()) {
            return null;
        }

        $envase = $this->presentacionDeLaUnidad($item, $forma);

        if (! $envase instanceof ItemPresentacion) {
            return null;
        }

        return $this->estantesConLoQueHay($item) !== [] && $this->estantesConLoQueHay($item, $forma) === []
            ? $envase
            : null;
    }

    private function porQueNoHayEseEnvase(ItemPresentacion $envase): string
    {
        return 'En los almacenes donde está este producto no hay '.UbicadorDeProducto::comoSeLlama($envase)
            .'. Cargalo como lo que sí hay ahí —el envase más chico o la unidad suelta—, o anotá ese '
            .'envase en Existencias → Lugares.';
    }

    /**
     * Qué decir cuando el estante no alcanza.
     *
     * ⚠️ Con el envase sin abrir el problema suele ser otro: hay
     * tabletas, pero no hay CAJAS cerradas —se recibieron en blíster, o
     * la caja ya se abrió—. Decir «no hay existencia de este producto»
     * ahí manda a pedir prestado lo que está en el estante.
     */
    private function porQueNoHay(Item $item, string $forma): string
    {
        $envase = $this->presentacionDeLaUnidad($item, $forma);

        /*
         * Solo si hay tabletas sueltas tiene sentido mandar a venderlas.
         * Con el estante vacío del todo, «vendé por TABLETA» manda a
         * buscar lo que tampoco está.
         */
        if ($envase instanceof ItemPresentacion && $this->existenciaTotal($item)->mayorQue('0')) {
            return 'No hay '.e($this->rotuloDeLaForma($item, $forma)).' sin abrir en ningún almacén. '
                .'Vendé por '.e($this->rotuloDeLaForma($item, self::POR_UNIDAD)).', recibí o trasladá '
                .'envases, o registrá que se pidió prestado.';
        }

        return 'No hay existencia de este producto en ningún almacén. Recibilo, trasladalo, o '
            .'registrá que se pidió prestado.';
    }

    /**
     * Lo que la tarjeta muestra sin que nadie tenga que abrir nada.
     *
     * @return array<string, string>
     */
    public function resumenDe(Cuenta $cuenta): array
    {
        $encuentro = $cuenta->encuentro;
        $persona = $encuentro->persona;

        /*
         * El rango de edad va en la tarjeta porque decide cómo se cobra:
         * a un 3R o 4R la ley le descuenta en cada cargo. Se resuelve a
         * HOY —es orientación visual, no el cálculo—; cada cargo vuelve
         * a resolverlo contra su propia fecha de servicio. Sin fecha de
         * nacimiento (el NN) no hay sigla, y la tarjeta lo muestra así
         * en vez de inventar «normal».
         *
         * ⚠️ Si el pagador no aplica el Art. 30 (PALIG, 9-oct-2026), el
         * 3R o 4R sigue saliendo —la edad es un dato del paciente— pero
         * en gris y con el porqué en el título: en color, la cajera lo
         * leería como «este lleva descuento» y no lo lleva.
         */
        $ahora = now();
        $rango = $persona->rangoDeEdadEn($ahora);
        $edad = $persona->edadEn($ahora);
        $sinLey = $rango instanceof RangoEdad
            && $rango->tieneDescuentoLegal()
            && ! $cuenta->convenio->aplicaDescuentoLegal();

        return [
            'nombre'       => $persona->nombreCompleto(),
            'expediente'   => $encuentro->expediente->numero,
            'ingreso'      => $encuentro->abierto_en->format('d/m/Y H:i'),
            'desde'        => $encuentro->abierto_en->diffForHumans(),
            'tipo'         => $encuentro->tipo->etiqueta(),
            'servicio'     => $encuentro->servicio_id === null ? '' : $encuentro->servicio->nombre,
            'pagador'      => $cuenta->convenio->nombre,
            'total'        => $cuenta->saldo()->formateado(),
            'paciente'     => $cuenta->saldoDelPaciente()->formateado(),
            'aseguradora'  => $cuenta->saldoDeLaAseguradora()->formateado(),
            'lineas'       => (string) $cuenta->lineas,
            'estado'       => $cuenta->estado->etiqueta(),
            'rango'        => $rango instanceof RangoEdad ? $rango->sigla() : '',
            'rango_color'  => $rango instanceof RangoEdad && ! $sinLey ? $rango->color() : 'gray',
            'rango_titulo' => $rango instanceof RangoEdad && $edad !== null
                ? $rango->etiqueta().' · '.$edad.' años'.($persona->fechaNacimientoEsExacta() ? '' : ' (edad estimada)')
                    .($sinLey ? ' · '.$cuenta->convenio->nombre.' no aplica el descuento de ley' : '')
                : '',
        ];
    }

    public function colorDelEstado(Cuenta $cuenta): string
    {
        return $cuenta->estado === EstadoCuenta::Congelada ? 'warning' : 'success';
    }
}
