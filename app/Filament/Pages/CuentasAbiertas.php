<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RangoEdad;
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
use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\MedioDePago;
use App\Domain\ValueObjects\Monto;
use App\Filament\Concerns\OperaElTurnoDeCaja;
use App\Models\Abono;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Cie10;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Diagnostico;
use App\Models\Existencia;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Persona;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Models\PrincipioActivo;
use App\Models\Unidad;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\AnuladorDeCargo;
use App\Services\ConsultorDeExistencias;
use App\Services\EmisorDeFactura;
use App\Services\PoliticaDeDescuentoComercial;
use App\Services\ReceptorDeAbono;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeDiagnostico;
use App\Services\ResolutorDePrecio;
use App\Support\AlmacenesDelUsuario;
use App\Support\CatalogoDelRol;
use App\Support\NormalizadorDeTexto;
use App\Support\NumeroDeFormulario;
use App\Support\UsuarioAutenticado;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
     * Lo que se teclea en el buscador. Sin `live()` con debounce corto:
     * cada tecla sería una consulta sobre pacientes (§13.2).
     */
    /**
     * Las claves del selector de unidad de cobro. Constantes y no strings
     * sueltos porque viajan entre el formulario, la conversión y la vista.
     */
    private const POR_UNIDAD = 'dispensacion';

    private const POR_FRACCION = 'fraccion';

    private const POR_PRESENTACION = 'presentacion:';

    /**
     * Lo que separa el ítem de la forma en que se entrega dentro del
     * valor del selector: «705|presentacion:12» es «ese jarabe, en el
     * frasco de 60». Un producto con una sola forma viaja pelado —«705»—,
     * que es como viajaba antes de que el selector se abriera por envase.
     */
    private const SEPARADOR_DE_FORMA = '|';

    public string $busqueda = '';

    /**
     * El token que distingue «lo cargué otra vez» de «apreté dos veces».
     * Vive en el estado de Livewire, así que un reenvío de la misma
     * acción trae el mismo valor y el servicio devuelve el cargo que ya
     * existía en vez de duplicarlo.
     */
    public string $claveDeEnvio = '';

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL DESCUENTO DE LA TANDA
     * ─────────────────────────────────────────────────────────────────
     *
     * Lo que se puso arriba en «Descuento sobre medicamentos» sobrevive a
     * cada «Agregar». Vive en el componente y no en los argumentos de la
     * acción porque el modal se vuelve a montar por tres caminos
     * distintos —Agregar, el atajo de lo más cargado, y el atajo que solo
     * deja el ítem puesto—, y un dato que hay que acordarse de pasar en
     * los tres se pierde en el que alguien olvide.
     *
     * 🔴 `cuentaDelDescuento` es lo que impide que se cruce de paciente:
     * la propiedad sobrevive a que el modal se cierre, así que sin ella
     * el 30 % autorizado a uno aparecería puesto al abrir la cuenta del
     * siguiente — y nadie lo mira dos veces cuando el campo ya está
     * lleno.
     */
    /**
     * Cuántas líneas van cargadas en esta tanda.
     *
     * No se muestra en ningún lado y no es un dato del negocio: es lo que
     * hace que el contexto de la acción cambie en cada ítem para que
     * Filament no cierre el modal. El porqué está en `cargarEnCuenta`.
     */
    /**
     * Memoria por pintada de «¿este paciente ya tiene cuenta viva?».
     *
     * @var array<int, Cuenta|null>
     */
    private array $cuentasVivas = [];

    public int $renglonDeLaTanda = 0;

    public ?int $cuentaDelDescuento = null;

    public ?string $descuentoDeLaTanda = null;

    public ?string $motivoDeLaTanda = null;

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
     * «¿Qué se le agrega?» ofrece solo los productos que lo llevan.
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
     * Memoria por pintada de los productos de ese principio, ya abiertos
     * por forma de entrega.
     *
     * @var array<int, array<int|string, string>>
     */
    private array $productosPorPrincipio = [];

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

    public function getSubheading(): string
    {
        return 'Lo que cada paciente lleva acumulado. Se le agrega escaneando o buscando por nombre.';
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
        $termino = trim($this->busqueda);

        return Cuenta::query()
            /*
             * Columnas nombradas y no `with('encuentro.persona')` a
             * secas: la tarjeta necesita seis campos y traer la persona
             * entera son cuarenta columnas por fila (§12).
             */
            ->with([
                'encuentro:id,numero,tipo,estado,abierto_en,persona_id,expediente_id,servicio_id',
                'encuentro.persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,apellido_casada,fecha_nacimiento,sexo_biologico,es_nn',
                'encuentro.expediente:id,numero',
                'encuentro.servicio:id,nombre',
                'convenio:id,codigo,nombre,tipo',
            ])
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
            ))
            ->orderByDesc('abierta_en')
            ->limit((int) config('sihla.facturacion.tarjetas_por_pantalla', 24))
            ->get();
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
     * Cuántos diagnósticos vigentes lleva la cuenta.
     *
     * Va en la tarjeta como un contador rojo cuando hay alguno, y NO
     * cuando no hay: un cero en cada tarjeta se vuelve invisible en dos
     * días. Lo que se quiere ver de un vistazo es cuáles ya tienen y
     * cuáles siguen sin diagnóstico — que son las que no se le pueden
     * cobrar a una aseguradora.
     */
    public function cuantosDiagnosticos(Cuenta $cuenta): int
    {
        return Diagnostico::query()
            ->where('encuentro_id', $cuenta->encuentro_id)
            ->vigentes()
            ->count();
    }

    public function puedeCargar(): bool
    {
        return Gate::allows('create', Cargo::class);
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
         * ⚠️ SE REGISTRAN LAS DOS, y cada una decide si se ve.
         *
         * Filament cachea las acciones del encabezado al montar la
         * página: devolviendo solo una, el botón se quedaba en «Abrir
         * turno» después de abrirlo y solo cambiaba al recargar. Con las
         * dos registradas, `visible()` se evalúa en cada render y el
         * botón se transforma solo.
         */
        return [
            $this->abrirTurnoAction(),
            $this->cerrarTurnoAction(),
        ];
    }

    public function abrirCuentaAction(): Action
    {
        return Action::make('abrirCuenta')
            ->label('Abrir cuenta')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('Abrir la cuenta de un paciente')
            ->modalDescription(
                'Se abre el encuentro y su cuenta a la vez. El pagador se puede cambiar después '
                .'sin perder lo ya cargado.'
            )
            ->modalSubmitActionLabel('Abrir la cuenta')
            ->visible(fn (): bool => Gate::allows('create', Cuenta::class))
            ->schema([
                Section::make('¿Quién es el paciente?')
                    ->columns(2)
                    ->schema([
                        Select::make('persona_id')
                            ->label('Paciente')
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->columnSpanFull()
                            ->helperText('Buscá por nombre, apellido o número de expediente antes de crear nada.')
                            /*
                             * ─────────────────────────────────────────
                             * 🔴 EL QUE YA TIENE CUENTA SE VE, PERO NO
                             * SE PUEDE ELEGIR
                             * ─────────────────────────────────────────
                             *
                             * Sacarlo del buscador era lo primero que se
                             * pensó y es peor: quien lo busca y no lo
                             * encuentra concluye «no está registrado» y
                             * lo crea de nuevo. Este sistema tiene una
                             * pantalla entera para fusionar duplicados
                             * justamente porque eso pasa y sale caro.
                             *
                             * Apagado y con el número de su cuenta al
                             * lado: no se puede elegir, se entiende por
                             * qué, y dice a dónde ir. El aviso aparte
                             * sobraba.
                             */
                            ->getSearchResultsUsing(function (string $search): array {
                                $encontrados = Persona::buscar($search);

                                /*
                                 * `array_values(array_map(...))` y no
                                 * `->pluck('id')->all()`: los dos dan lo
                                 * mismo en tiempo de ejecución, pero solo
                                 * el primero le prueba a PHPStan que son
                                 * enteros y que las llaves quedaron
                                 * 0,1,2… `pluck` devuelve `mixed` porque
                                 * el nombre de la columna es una cadena
                                 * que el analizador no puede resolver.
                                 */
                                $this->precargarCuentasVivas(array_values(array_map(
                                    fn (Persona $p): int => (int) $p->id,
                                    $encontrados->all(),
                                )));

                                return $encontrados
                                    ->mapWithKeys(fn (Persona $p): array => [
                                        $p->id => $this->conSuCuentaAbierta($p),
                                    ])
                                    ->all();
                            })
                            ->disableOptionWhen(fn (mixed $value): bool => $this->cuentaVivaDe($value) instanceof Cuenta)
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Persona::query()
                                ->find(is_numeric($value) ? (int) $value : 0)?->nombreCompleto())
                            ->afterStateUpdated(fn (Set $set) => $set('expediente_id', null)),

                        Select::make('expediente_id')
                            ->label('Expediente')
                            ->required()
                            ->native(false)
                            ->options(fn (Get $get): array => $this->expedientesDe($get('persona_id')))
                            ->helperText('El expediente es de la sede. Un paciente puede tener uno por sede.'),

                        Select::make('tipo')
                            ->label('Tipo de atención')
                            ->required()
                            ->native(false)
                            ->default(TipoEncuentro::Hospitalizacion->value)
                            ->options(TipoEncuentro::opciones()),
                    ]),

                Section::make('¿Quién paga?')
                    ->columns(2)
                    ->schema([
                        Select::make('convenio_id')
                            ->label('Pagador')
                            ->required()
                            ->native(false)
                            ->columnSpanFull()
                            ->options(fn (): array => app(AbridorDeEncuentro::class)
                                ->pagadoresDisponibles()
                                ->mapWithKeys(fn (Convenio $c): array => [$c->id => $c->nombre])
                                ->all())
                            ->helperText(
                                'Contado también es un pagador. Si todavía no aparece la póliza, abrila '
                                .'como contado: cambiarla después no pierde ningún cargo.'
                            ),

                        TextInput::make('numero_poliza')
                            ->label('Número de póliza')
                            ->maxLength(60),

                        TextInput::make('numero_autorizacion')
                            ->label('Número de autorización')
                            ->maxLength(60)
                            ->helperText('Si el seguro ya la dio. Se puede cargar después.'),

                        Textarea::make('motivo')
                            ->label('Motivo de la atención')
                            ->maxLength(200)
                            ->columnSpanFull()
                            ->rows(2),
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
                        numeroPoliza: is_string($data['numero_poliza'] ?? null) && $data['numero_poliza'] !== ''
                            ? $data['numero_poliza'] : null,
                        numeroAutorizacion: is_string($data['numero_autorizacion'] ?? null) && $data['numero_autorizacion'] !== ''
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
                     * Doce columnas y no cuatro: con cuatro, los cuatro
                     * campos no entran en una fila y el almacén cae solo
                     * abajo, desalineado. Con doce se reparten 5-3-2-2 y
                     * la fila se lee de un vistazo.
                     */
                    ->columns(12)
                    ->schema([
                        /*
                         * ⚠️ `dehydrated()` en TRUE, al revés que en la
                         * pantalla de conteo.
                         *
                         * Una pistola de código de barras teclea el código
                         * y manda Enter de una. Ese Enter envía el
                         * formulario ANTES de que el `afterStateUpdated`
                         * termine de resolver el ítem, así que si el
                         * escaneo no viaja con los datos, el envío llega
                         * sin nada y no pasa nada. Viajando, el servicio
                         * lo resuelve como respaldo y escanear + Enter
                         * carga la línea sin tocar el mouse.
                         */
                        BarcodeInput::make('escaneo')
                            ->label('Escaneá el código o escribí el nombre')
                            ->live()
                            ->autofocus()
                            ->columnSpanFull()
                            ->helperText(
                                'Pistola, cámara o teclado. El código del envase carga el producto; el de '
                                .'la gaveta —PA-0001— acota la lista al principio activo; y si escribís un '
                                .'nombre, busca en el catálogo.'
                            )
                            ->afterStateUpdated(fn (mixed $state, Set $set) => $this->resolverEscaneo($state, $set)),

                        /*
                         * ─────────────────────────────────────────────
                         * LO QUE ENCONTRÓ, EN UNA LÍNEA
                         * ─────────────────────────────────────────────
                         *
                         * Después de pasar la pistola, el único lugar
                         * donde se veía qué producto quedó elegido era el
                         * desplegable de abajo, y una notificación que se
                         * va sola a los pocos segundos. Con el paciente
                         * enfrente eso se lee mal: acá queda fijo el
                         * nombre, la presentación de la que se leyó y por
                         * qué unidad se está cobrando.
                         */
                        Placeholder::make('lo_encontrado')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->itemDe($get('item_id')) instanceof Item)
                            ->content(fn (Get $get): HtmlString => $this->resumenDeLoElegido($get)),

                        Select::make('item_id')
                            ->label('¿Qué se le agrega?')
                            /*
                             * 🔴 Obligatorio también cuando lo escaneado
                             * fue una gaveta.
                             *
                             * El código de un principio activo NO resuelve
                             * un ítem —esa es toda su gracia: son varios—,
                             * así que sin esto el Enter de la pistola
                             * mandaría el formulario con el selector vacío
                             * y el respaldo de `agregar()` no encontraría
                             * nada. Terminaba en «falta decir qué se
                             * agrega» después de apretar, en vez de antes.
                             */
                            ->required(fn (Get $get): bool => blank($get('escaneo'))
                                || $this->principioEscaneado !== null)
                            ->searchable()
                            ->native(false)
                            ->live()
                            /*
                             * Se queda con las tres columnas que dejó
                             * «Se cobra por»: ahora el renglón dice
                             * producto Y envase, y en cuatro columnas se
                             * partía en dos líneas.
                             */
                            ->columnSpan(7)
                            /*
                             * Con la gaveta escaneada la lista ya viene
                             * puesta: se abre el desplegable y están los
                             * tres o cuatro, sin teclear nada. Sin gaveta
                             * queda vacía y manda la búsqueda de siempre.
                             */
                            ->options(fn (): array => $this->opcionesDelSelector())
                            ->getSearchResultsUsing(fn (string $search): array => $this->resultadosDeBusqueda($search))
                            /*
                             * ⚠️ El valor elegido se rotula con la MISMA
                             * frase que la opción —«ACETAMINOFEN JARABE —
                             * FRASCO 60 ML»—, no solo con el nombre del
                             * producto. Con el envase adentro de esta
                             * elección, mostrar nada más el nombre dejaba
                             * el campo diciendo «ACETAMINOFEN JARABE» con
                             * tres frascos distintos posibles y ninguna
                             * forma de saber cuál quedó.
                             */
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->etiquetaDeLoElegido($value))
                            /*
                             * 🔴 Un filtro que no se ve es una lista corta
                             * que nadie entiende por qué está corta —y de
                             * ahí sale «el sistema no tiene el producto»
                             * cuando sí lo tiene. Se dice qué acotó la
                             * lista, y se sale de ahí con un clic.
                             */
                            ->hint(fn (): ?string => $this->principioEscaneado === null
                                ? null
                                : 'Solo lo que lleva '.$this->nombreDelPrincipioEscaneado())
                            ->hintAction(
                                Action::make('quitar_filtro_de_principio')
                                    ->label('Ver todo el catálogo')
                                    ->icon(Heroicon::OutlinedXMark)
                                    ->color('gray')
                                    ->visible(fn (): bool => $this->principioEscaneado !== null)
                                    ->action(function (Set $set): void {
                                        $this->principioEscaneado = null;
                                        $set('item_id', null);
                                        $set('escaneo', null);
                                    }),
                            )
                            ->placeholder(fn (): string => $this->principioEscaneado === null
                                ? 'Escribí tres letras o el código'
                                : 'Elegí en qué forma se le dio')
                            /*
                             * Elegir el producto elige también el estante
                             * del que se va a sacar. Sin esto el campo de
                             * abajo queda vacío y hay que abrirlo en cada
                             * línea de una ronda de veinte.
                             */
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $this->prepararLaLinea($state, $set);
                            }),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 «SE COBRA POR» YA NO ES UN CAMPO
                         * ─────────────────────────────────────────────
                         *
                         * Eran dos desplegables encadenados para una sola
                         * decisión: arriba «ACETAMINOFEN JARABE», abajo
                         * «FRASCO 60 ML». Pero el producto no se entrega
                         * en abstracto —se entrega un frasco de 60 o uno
                         * de 120—, así que cada forma pasó a ser un
                         * renglón del selector de arriba, con el nombre
                         * adelante. Elegir ya es elegir el envase.
                         *
                         * La CLAVE sigue viajando en el formulario: de
                         * ella dependen la conversión a unidad de
                         * dispensación, cuántos frascos hay en cada
                         * estante y la equivalencia que se lee antes de
                         * apretar Agregar. Lo que se fue es el campo, no
                         * el dato.
                         */
                        Hidden::make('unidad_cobro')
                            ->default(self::POR_UNIDAD),

                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(0.0001)
                            ->step('0.0001')
                            ->columnSpan(2)
                            ->live(onBlur: true)
                            /*
                             * 🔴 La equivalencia, a la vista y en vivo.
                             *
                             * «1» escrito en una caja de cien tabletas y «1»
                             * escrito en una tableta son el mismo carácter y
                             * son L 900 de diferencia. Que la pantalla diga
                             * «1 CAJA X 100 TABLETAS = 100 TABLETA» antes de
                             * apretar Agregar es lo único que impide ese
                             * error, porque después ya está cobrado.
                             */
                            ->helperText(function (Get $get): ?string {
                                $item = $this->itemDe($get('item_id'));

                                if (! $item instanceof Item) {
                                    return null;
                                }

                                return $this->equivalencia($item, $this->formaEnUso($get), $get('cantidad'));
                            }),

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
                         * ⚠️ TRES COLUMNAS Y TEXTO DE UNA LÍNEA.
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
                            ->columnSpan(3)
                            ->options(fn (Get $get): array => $this->estantesConLoQueHay($this->itemDe($get('item_id')), $this->formaEnUso($get)))
                            ->required(fn (Get $get): bool => $this->itemDe($get('item_id'))?->mueveInventario() === true)
                            ->visible(fn (Get $get): bool => $this->itemDe($get('item_id'))?->mueveInventario() === true)
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
                            ->columnSpan(3)
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

                                return $this->precioPropuesto($item) === null
                                    ? 'Este honorario no tiene precio en el tarifario: escribí cuánto se le cobra.'
                                    : 'Propuesto del tarifario. Cambialo si este médico cobra otra cosa.';
                            }),

                        TextInput::make('referencia_acordada')
                            ->label('¿De quién es el honorario?')
                            ->maxLength(120)
                            ->columnSpan(4)
                            ->visible(fn (Get $get): bool => $this->esHonorario($this->itemDe($get('item_id'))))
                            ->placeholder('Dr. Fulano · cirujano')
                            ->helperText('Queda escrito en el renglón de la cuenta y en la factura.'),

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
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->itemDe($get('item_id')) instanceof Item)
                            ->content(fn (Get $get): HtmlString => $this->comoQuedaLaLinea($get)),
                    ]),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless(Gate::allows('create', Cargo::class), 403);

                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                /*
                 * Y sobre ESTA cuenta en particular: el id llega del
                 * cliente. `CuentaPolicy::update()` ya niega las cerradas
                 * y las anuladas.
                 */
                abort_unless(Gate::allows('update', $cuenta), 403);

                $this->agregar($cuenta, $data);

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
             * `fillForm` con el ítem que venga en los argumentos: es lo
             * que permite que un atajo de «lo más usado» que SÍ mueve
             * inventario deje el ítem puesto y solo pida el almacén, en
             * vez de fallar con un aviso.
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

                /*
                 * Cada línea arranca sin filtro. Corre al abrir el modal y
                 * también en cada remontaje —o sea después de cada ítem
                 * agregado—, que es justo lo que hace falta: la gaveta que
                 * se escaneó para el renglón anterior no tiene por qué
                 * seguir acotando el siguiente.
                 */
                $this->principioEscaneado = null;

                $itemPuesto = is_numeric($arguments['item'] ?? null) ? (int) $arguments['item'] : null;

                /*
                 * ⚠️ El precio también se propone ACÁ y no solo en
                 * `prepararLaLinea()`. Cuando el ítem llega puesto —desde
                 * un atajo de la banda de arriba— nadie tocó el selector,
                 * así que su `afterStateUpdated` nunca corre y el campo
                 * se quedaba vacío justo en el camino más rápido.
                 */
                $puesto = $this->itemDe($itemPuesto);

                return [
                    /*
                     * El valor del selector y no el id pelado: con el
                     * selector abierto por envase, «705» no es una de sus
                     * opciones cuando el producto tiene tres frascos, y
                     * el campo aparecía en blanco en el camino más rápido.
                     */
                    'item_id'         => $this->valorDelSelector($puesto),
                    'cantidad'        => 1,
                    'precio_acordado' => $this->esHonorario($puesto)
                        ? $this->precioPropuesto($puesto)
                        : null,
                    'referencia_acordada' => null,

                    /*
                     * El default va acá también: `fillForm` pisa el estado
                     * entero, así que sin esta línea el selector volvía a
                     * «Seleccione una opción» después de cada ítem cargado.
                     */
                    'unidad_cobro' => $puesto instanceof Item
                        ? $this->unidadDeCobroPorDefecto($puesto)
                        : self::POR_UNIDAD,
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
                $rango = $this->rangoDelPacienteDe($arguments);

                return view('filament.pages.partials.atajos-de-cargo', [
                    'cuenta'    => $this->cuentaDe($arguments),
                    'items'     => $this->itemsFrecuentes($arguments),
                    'puede'     => $this->puedeCargar(),
                    'tope'      => $this->topeDeDescuento($rango)->por('100')->redondeado(2),
                    'ayuda'     => $this->ayudaDelDescuento($rango),
                    'descuento' => $this->descuentoDeLaTanda,
                    'motivo'    => $this->motivoDeLaTanda,
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
     * @param array<string, mixed> $data
     */
    private function agregar(Cuenta $cuenta, array $data): void
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

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL GUARDIÁN ESTÁ ACÁ, NO EN EL SELECTOR
         * ─────────────────────────────────────────────────────────────
         *
         * El selector ya filtra por área, pero esto es un método público
         * de Livewire: el id del ítem llega del cliente y se puede armar
         * a mano. Una regla que solo vive en la pantalla no es una regla,
         * y lo que limita qué se le puede cobrar a un paciente tiene que
         * estar donde se escribe.
         */
        if ($item instanceof Item) {
            try {
                CatalogoDelRol::exigirQuePuedaCargar($item);
            } catch (CargoException $e) {
                Notification::make()
                    ->danger()
                    ->title('Eso no se carga desde tu área')
                    ->body($e->getMessage())
                    ->persistent()
                    ->send();

                return;
            }
        }

        /*
         * Respaldo del Enter de la pistola: si el selector quedó vacío
         * pero vino un código, se resuelve acá. Sin esto, escanear y
         * presionar Enter no hace nada y el operador vuelve a escanear —
         * que es como nacen los cargos duplicados.
         */
        if (! $item instanceof Item) {
            $item = $this->itemPorCodigo($data['escaneo'] ?? null);
        }

        if (! $item instanceof Item) {
            Notification::make()
                ->warning()
                ->title('Falta decir qué se agrega')
                ->body('Escaneá el código o elegí el ítem del catálogo.')
                ->send();

            return;
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

            return;
        }

        /*
         * 🔴 Acá se convierte a unidad de dispensación, y en un solo
         * lugar. Todo lo que sale de esta línea hacia el motor ya está en
         * la unidad del kardex: si la conversión viviera repartida, una
         * caja terminaría descontando una tableta.
         */
        $unidadDeCobro = self::formaDe($data['item_id'] ?? null)
            ?? (is_string($data['unidad_cobro'] ?? null) && $data['unidad_cobro'] !== ''
                ? $data['unidad_cobro']
                : $this->unidadDeCobroPorDefecto($item));

        /*
         * ⚠️ Y si lo que llegó NO está entre las formas de cobrar este
         * ítem, se repone la que corresponde. No es pisar una decisión:
         * una unidad fuera de la lista nadie la pudo elegir mirando —
         * viene de un valor pegado del ítem anterior o del Enter de la
         * pistola, que manda el formulario antes de que el selector se
         * actualice—. Dejarla pasar cobraría un jarabe entero por
         * mililitro.
         */
        if (! isset($this->unidadesDeCobro($item)[$unidadDeCobro])) {
            $unidadDeCobro = $this->unidadDeCobroPorDefecto($item);
        }

        $cantidad = $this->aUnidadesDeDispensacion($item, $unidadDeCobro, $tecleada);

        if (! $cantidad instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('Esa unidad no aplica a este ítem')
                ->body(
                    'Elegí otra forma de cobrarlo. Si el producto se fracciona y no aparece la '
                    .'fracción, hay que declararla en el catálogo antes de poder venderla así.'
                )
                ->persistent()
                ->send();

            return;
        }

        $almacen = isset($data['almacen_id']) && is_numeric($data['almacen_id'])
            ? Almacen::query()->find((int) $data['almacen_id'])
            : null;

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
                    autorizadoPor: $descuento instanceof Decimal ? $cuenta->descuento_hospital_por : null,

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
                ),
            );
        } catch (CargoException|CuentaException|EncuentroException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo agregar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        } catch (ExistenciaInsuficienteException $e) {
            Notification::make()
                ->danger()
                ->title('No hay suficiente en el estante')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        /*
         * Clave nueva: lo que venga después es un hecho distinto y tiene
         * que poder registrarse. Renovarla acá —y no al empezar la
         * petición— es lo que hace que el reintento del MISMO envío siga
         * trayendo la clave vieja y no duplique.
         */
        $this->claveDeEnvio = (string) Str::uuid();

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

        abort_unless(Gate::allows('update', $laLinea->cuenta), 403);

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

        if (! $almacen instanceof Almacen) {
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

        $saldos = Existencia::query()
            ->where('item_id', $item->id)
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 SOLO LOS DEL ENVASE QUE SE ESTÁ COBRANDO
             * ─────────────────────────────────────────────────────────
             *
             * Sin este filtro, la existencia sumaba TODOS los mililitros
             * del jarabe —los frascos de 60, los de 80 y los de 120— y
             * después los dividía entre 60. El resultado era «38 FRASCO»:
             * un número que no corresponde a ningún frasco que exista en
             * el estante, y que promete una entrega que el hospital no
             * puede cumplir.
             *
             * Cada lote sabe de qué presentación es, así que la pregunta
             * correcta es cuántos frascos DE ESE ENVASE hay, no cuántos
             * cabrían si todo el líquido estuviera en frascos de 60.
             *
             * Nulo = sin envase elegido, y ahí sí se suma todo: es el
             * caso del pase de presupuesto y del ítem que se cobra por
             * unidad suelta.
             */
            ->when(
                $presentacionId !== null,
                fn (Builder $consulta): Builder => $consulta->whereHas(
                    'lote',
                    fn (Builder $lote): Builder => $lote->where('item_presentacion_id', $presentacionId),
                ),
            )
            ->selectRaw('almacen_id, sum(cantidad) as total')
            ->groupBy('almacen_id')
            ->pluck('total', 'almacen_id');

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
     * Las opciones del desplegable, cada una diciendo cuánto hay.
     *
     * @return array<int, string>
     */
    private function estantesConLoQueHay(?Item $item, ?string $unidadDeCobro = null): array
    {
        if (! $item instanceof Item) {
            return [];
        }

        $presentacion = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

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
                    .' · '.$this->existenciaComoSeCobra($item, $estante['hay'], $unidadDeCobro),
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
     */
    private function existenciaComoSeCobra(Item $item, Decimal $hay, ?string $unidadDeCobro): string
    {
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
                return self::cifraCorta($hay->entre($contenido)).' '.$presentacion->unidad->codigo;
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
     * Deja listo lo que se puede deducir del producto elegido: de qué
     * estante sale y en qué unidad se cobra.
     *
     * Se llama desde los TRES caminos por los que queda elegido un
     * producto —la pistola, la gaveta de un principio activo con un solo
     * producto, y la búsqueda por nombre con un solo resultado—, porque
     * el `afterStateUpdated` del selector NO corre cuando el valor se
     * pone con `$set()` desde el código. Sin esto, escanear cargaba el
     * producto y dejaba «¿de dónde sale?» en blanco, que es justo el
     * campo que se quería ahorrar.
     */
    private function prepararLaLinea(mixed $valor, Set $set): void
    {
        $item = $this->itemDe($valor);

        /*
         * 🔴 LA FORMA PRIMERO, Y DESPUÉS EL ESTANTE.
         *
         * La forma la trae el propio valor elegido —«705|presentacion:12»
         * es el frasco de 60—, y solo cuando el valor viene pelado se
         * cae al despacho por defecto. Sin esto, un jarabe quedaba con
         * «dispensacion» pegado del ítem anterior —un valor que ya no
         * está entre sus opciones—, y el desplegable de estantes se veía
         * vacío justo después de escanear.
         *
         * El orden importa: cuántos frascos hay en cada estante depende
         * del envase, así que el estante se sugiere con la forma ya
         * resuelta y no con la que quedó del producto anterior.
         */
        $unidad = null;

        if ($item instanceof Item) {
            $elegida = self::formaDe($valor);

            $unidad = $elegida !== null && isset($this->unidadesDeCobro($item)[$elegida])
                ? $elegida
                : $this->unidadDeCobroPorDefecto($item);

            $set('unidad_cobro', $unidad);
        }

        $set('almacen_id', $this->estanteSugerido($item, $unidad)?->id);

        /*
         * El honorario arranca con el precio del tarifario a la vista.
         * Vacío obligaría a teclear el número entero incluso cuando es el
         * de siempre, y eso convierte un campo de excepción en un campo
         * obligatorio.
         */
        $set('precio_acordado', $this->esHonorario($item) ? $this->precioPropuesto($item) : null);
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

        $texto = is_string($data['referencia_acordada'] ?? null)
            ? trim($data['referencia_acordada'])
            : '';

        return $texto === '' ? null : mb_substr($texto, 0, 120);
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
    private function precioPropuesto(?Item $item): ?string
    {
        if (! $item instanceof Item || $this->cuentaDelFormulario === null) {
            return null;
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
        if (array_key_exists($item->id, $this->precioPropuestoPorItem)) {
            return $this->precioPropuestoPorItem[$item->id];
        }

        $cuenta = Cuenta::query()->with(['convenio', 'sede'])->find($this->cuentaDelFormulario);

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

        $presentacion = $this->presentacionDeLaUnidad($item, $unidadDeCobro);

        $primero = $this->estantesDe($item, $presentacion?->id)->first();

        return $primero !== null && ! $primero['hay']->esCero() ? $primero['almacen'] : null;
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

        abort_unless(Gate::allows('update', $laLinea->cuenta), 403);

        if ($this->anular($laLinea, trim($this->motivoDeQuitar))) {
            $this->cancelarQuitar();
        }
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
     * Sirve para que el selector de abajo se abra ya con los resultados:
     * quien está en el mostrador escribe «acetamin», presiona Enter y
     * elige — sin volver a teclear lo mismo en otro campo.
     */
    public ?string $busquedaEscrita = null;

    public ?int $cuentaDelAbono = null;

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
     * 🔴 CON SEGURO, LA FACTURA SALE A NOMBRE DEL SEGURO
     * ─────────────────────────────────────────────────────────────────
     *
     * Es lo que el hospital ya viene haciendo en papel: el renglón del
     * cliente dice «PAN-AMERICAN LIFE / NAHUM ANDRÉ RODRÍGUEZ», con el
     * RTN DEL SEGURO y el número de póliza como código de cliente.
     *
     * Tiene que ser así: quien paga esa factura es la aseguradora, y una
     * factura a nombre del paciente no le sirve para reembolsar. El
     * nombre del paciente va igual porque sin él la aseguradora no sabe
     * a qué asegurado corresponde.
     *
     * Sin convenio —paciente particular— es el paciente y su propio
     * documento.
     *
     * @return array{nombre: string, tipo: string|null, numero: string|null, codigo: string|null}
     */
    private function clientePropuesto(): array
    {
        $cuenta = $this->cuentaFacturando();
        $persona = $cuenta?->encuentro->persona ?? null;

        $paciente = $persona instanceof Persona
            ? $persona->nombreCompleto()
            : (string) config('sihla.facturacion.consumidor_final', 'CONSUMIDOR FINAL');

        $convenio = $cuenta?->convenio;

        if ($convenio instanceof Convenio && $convenio->tipo !== TipoConvenio::Contado) {
            $rtn = $convenio->rtn;
            $tieneRtn = $rtn !== null && trim($rtn) !== '';

            /*
             * ⚠️ SI LA ASEGURADORA NO TIENE RTN CARGADO, VA EL DOCUMENTO
             * DEL PACIENTE.
             *
             * Lo correcto es el RTN del seguro —es quien paga y quien
             * reembolsa— pero mientras no esté cargado el campo salía
             * vacío y quien factura tenía que teclear a mano un número
             * que el sistema ya conoce, o emitir sin documento.
             *
             * No es lo ideal, es lo menos malo: identificado con la
             * identidad del asegurado es mejor que «sin documento» en
             * una factura que pasa el umbral. Cargá el RTN del seguro en
             * «Seguros y convenios» y esto deja de hacer falta.
             */
            $documento = $tieneRtn
                ? ['tipo' => TipoIdentificador::Rtn->value, 'numero' => trim((string) $rtn)]
                : $this->documentoDelPaciente();

            return [
                'nombre' => $convenio->nombre.' / '.$paciente,
                'tipo'   => $documento['tipo'],
                'numero' => $documento['numero'],

                /* El código de cliente del papel es la póliza. */
                'codigo' => $cuenta?->numero_poliza,
            ];
        }

        $documento = $this->documentoDelPaciente();

        return [
            'nombre' => $paciente,
            'tipo'   => $documento['tipo'],
            'numero' => $documento['numero'],
            'codigo' => null,
        ];
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

    private function cuentaFacturando(): ?Cuenta
    {
        return $this->cuentaAFacturar === null
            ? null
            : Cuenta::query()->with('encuentro.persona.identificadores')->find($this->cuentaAFacturar);
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
            ->modalWidth('2xl')
            ->modalHeading(function (array $arguments): string {
                $cuenta = $this->cuentaDe($arguments);

                return 'Emitir la factura'.($cuenta instanceof Cuenta ? ' · '.$cuenta->numero : '');
            })
            ->modalDescription(
                'Se consume un número del rango del SAR, los cargos pasan a facturados y la cuenta se cierra. '
                .'El número no se libera aunque después se anule el papel.'
            )
            ->modalSubmitActionLabel('Emitir')
            ->visible(fn (): bool => Gate::allows('create', Factura::class))
            ->schema([
                Placeholder::make('resumen_a_facturar')
                    ->hiddenLabel()
                    ->content(function (): HtmlString {
                        $cuenta = $this->cuentaFacturando();

                        if (! $cuenta instanceof Cuenta) {
                            return new HtmlString('&nbsp;');
                        }

                        $saldo = $cuenta->saldoPendiente();

                        $texto = 'Total <strong>L '.number_format((float) $cuenta->total, 2).'</strong>';

                        $texto .= $saldo->mayorQue('0')
                            ? ' · <span style="color:rgb(220 38 38)">debe L '
                                .number_format((float) $saldo->redondeado(2), 2)
                                .', hay que recibir el abono antes de facturar</span>'
                            : ' · <span style="color:rgb(22 163 74)">saldada</span>';

                        return new HtmlString('<span style="font-size:.95rem">'.$texto.'</span>');
                    }),

                /*
                 * ─────────────────────────────────────────────────────
                 * DOS SECCIONES Y NO OCHO CAMPOS SUELTOS
                 * ─────────────────────────────────────────────────────
                 *
                 * El modal era una columna de ocho campos con un párrafo
                 * abajo de cada uno: había que bajar dos pantallas para
                 * llegar a «Emitir», y el ojo no tenía dónde apoyarse.
                 *
                 * Son dos preguntas distintas: A QUIÉN se le factura, y
                 * CON QUÉ se paga. La segunda ya estaba separada; la
                 * primera ahora también, con el documento y su número en
                 * la misma fila —se llenan juntos, se leen juntos—.
                 */
                Section::make('A quién se le factura')
                    ->columns(2)
                    ->schema([
                        /*
                 * ⚠️ A nombre de quién sale NO es siempre el paciente: la
                 * factura puede ir a la empresa que lo mandó o al
                 * familiar que paga. Se propone el paciente y se puede
                 * cambiar.
                 */
                        TextInput::make('cliente_nombre')
                            ->columnSpanFull()
                            ->label('A nombre de')
                            ->required()
                            ->maxLength(200)
                            ->default(fn (): string => $this->clientePropuesto()['nombre'])
                            ->helperText(fn (): ?string => $this->cuentaFacturando()?->convenio?->tipo === TipoConvenio::Contado
                                ? null
                                : 'Con seguro la factura sale a nombre de la aseguradora con el paciente: es ella la que paga y la que reembolsa.'),

                        /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 NO TODO PACIENTE TIENE RTN
                 * ─────────────────────────────────────────────────────
                 *
                 * Y arriba del umbral igual hay que identificarlo. Se
                 * acepta RTN, identidad o pasaporte, precargado con lo
                 * que el paciente ya tiene en su expediente: en el
                 * mostrador nadie debería teclear un número que el
                 * sistema ya conoce.
                 */
                        Select::make('cliente_documento_tipo')
                            ->label('Documento')
                            ->options(fn (): array => self::tiposDeDocumento())
                            ->native(false)
                            ->default(fn (): ?string => $this->clientePropuesto()['tipo'])
                            ->requiredWith('cliente_documento'),

                        TextInput::make('cliente_documento')
                            ->label('Número')
                            ->maxLength(20)
                            ->default(fn (): ?string => $this->clientePropuesto()['numero'])
                            /*
                     * Si viene vacío no es un error del sistema: a ese
                     * paciente nunca le registraron documento. Decirlo
                     * evita que alguien lo teclee a mano cada vez en vez
                     * de agregarlo al expediente una sola vez.
                     */
                            ->hint(fn (): ?string => $this->clientePropuesto()['numero'] === null
                                ? 'Sin documento en el expediente'
                                : null)
                            ->helperText(function (): string {
                                $umbral = config('sihla.facturacion.umbral_rtn_obligatorio');

                                return 'Arriba de L '.number_format((float) (is_string($umbral) ? $umbral : '10000'), 2)
                                    .' el SAR exige identificar al cliente. Si no tiene RTN, sirve la identidad.';
                            }),

                        TextInput::make('cliente_direccion')
                            ->label('Dirección')
                            ->columnSpanFull()
                            ->maxLength(250),

                        /*
                 * El «Código de Cliente» del formulario: con seguro es
                 * el número de póliza, que es por donde la aseguradora
                 * busca al asegurado cuando reembolsa.
                 */
                        TextInput::make('cliente_codigo')
                            ->label('Código de cliente / póliza')
                            ->maxLength(40)
                            ->columnSpanFull()
                            ->default(fn (): ?string => $this->clientePropuesto()['codigo'])
                            ->hint(fn (): ?string => $this->clientePropuesto()['codigo'] === null
                                ? 'Sin póliza en la cuenta'
                                : null)
                            ->helperText('Es por donde la aseguradora busca al asegurado cuando reembolsa.')
                            ->visible(fn (): bool => $this->cuentaFacturando()?->convenio?->tipo !== TipoConvenio::Contado),

                        Textarea::make('nota')
                            ->label('Nota')
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(300),
                    ]),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 COBRAR Y FACTURAR SON UN SOLO ACTO
                 * ─────────────────────────────────────────────────────
                 *
                 * El caso más común del mostrador: consulta, paga y se
                 * va. Obligarlo a pasar por «Abonar» y volver a
                 * «Facturar» son dos pantallas para una sola cosa, con
                 * el paciente parado enfrente.
                 *
                 * Solo aparece si la cuenta debe algo: al internado que
                 * fue abonando durante la estadía no hay nada que
                 * cobrarle acá.
                 */
                Section::make('Cobrar ahora')
                    ->description('Lo que falta, en el mismo acto: se recibe el abono y se emite la factura seguido.')
                    ->visible(fn (): bool => $this->cuentaFacturando()?->saldoPendiente()->mayorQue('0') ?? false)
                    ->schema([
                        Repeater::make('medios')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Forma de pago')->width('38%'),
                                TableColumn::make('Monto')->width('27%'),
                                TableColumn::make('¿A qué banco?')->width('35%'),
                            ])
                            ->addActionLabel('Agregar otra forma de pago')
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
                                 * Con el saldo exacto ya puesto: lo
                                 * normal es que pague todo, y así es un
                                 * clic en vez de teclear un número que
                                 * la pantalla ya sabe.
                                 */
                                TextInput::make('monto')
                                    ->hiddenLabel()
                                    ->prefix('L')
                                    ->inputMode('decimal')
                                    ->default(fn (): ?string => $this->cuentaFacturando()?->saldoPendiente()->redondeado(2)),

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

                        TextInput::make('entregado_por_factura')
                            ->label('¿Quién paga, si no es el paciente?')
                            ->maxLength(120),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                $cuenta = $this->cuentaDe($arguments);

                if (! $cuenta instanceof Cuenta) {
                    return;
                }

                abort_unless(Gate::allows('create', Factura::class), 403);

                /*
                 * ⚠️ El cobro va PRIMERO y en su propia transacción.
                 *
                 * Si después falla la emisión —no hay CAI cargado, el
                 * rango venció— la plata ya quedó registrada como abono
                 * y se factura cuando eso se resuelva. Al revés, un
                 * cobro perdido es plata que entró y el sistema no vio.
                 */
                $usuario = Auth::user();

                if ($usuario instanceof User && $cuenta->saldoPendiente()->mayorQue('0')) {
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
                        cliente: new ClienteDeFactura(
                            nombre: is_string($data['cliente_nombre'] ?? null) ? $data['cliente_nombre'] : '',
                            documento: is_string($data['cliente_documento'] ?? null) ? $data['cliente_documento'] : null,
                            tipoDocumento: TipoIdentificador::tryFrom(
                                is_string($data['cliente_documento_tipo'] ?? null) ? $data['cliente_documento_tipo'] : ''
                            ),
                            direccion: is_string($data['cliente_direccion'] ?? null) ? $data['cliente_direccion'] : null,
                            codigo: is_string($data['cliente_codigo'] ?? null) ? $data['cliente_codigo'] : null,
                        ),
                        quien: UsuarioAutenticado::id(),
                        nota: is_string($data['nota'] ?? null) ? $data['nota'] : null,
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
                    ->persistent()
                    ->send();
            });
    }

    public function abonarAction(): Action
    {
        return Action::make('abonar')
            ->label('Abonar')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalWidth('3xl')
            ->modalHeading(function (array $arguments): string {
                $cuenta = $this->cuentaDe($arguments);

                return 'Recibir un abono'.($cuenta instanceof Cuenta ? ' · '.$cuenta->numero : '');
            })
            ->modalSubmitActionLabel('Recibir')
            ->visible(fn (): bool => Gate::allows('create', Abono::class))
            ->schema([
                /*
                 * ─────────────────────────────────────────────────────
                 * LOS TRES NÚMEROS, ARRIBA Y GRANDES
                 * ─────────────────────────────────────────────────────
                 *
                 * Antes iban en una frase en la descripción del modal.
                 * Quien está cobrando con la familia enfrente no lee una
                 * frase: mira el número que falta y teclea. Estos tres
                 * son los que decide todo lo demás.
                 *
                 * ⚠️ Los estilos van EN LÍNEA. El CSS de Filament viene
                 * precompilado y las clases que el panel no usa no
                 * existen (§9.A7); dentro de un modal no hay dónde poner
                 * un `<style>` propio.
                 */
                Section::make('Cómo va la cuenta')
                    ->compact()
                    ->columns(3)
                    ->schema([
                        Placeholder::make('resumen_total')
                            ->label('Total de la cuenta')
                            ->content(function (): HtmlString {
                                $cuenta = $this->cuentaAbonando();

                                return self::numeroGrande($cuenta instanceof Cuenta ? $cuenta->total : '0');
                            }),

                        Placeholder::make('resumen_abonado')
                            ->label('Ya abonado')
                            ->content(function (): HtmlString {
                                $cuenta = $this->cuentaAbonando();

                                return self::numeroGrande(
                                    $cuenta instanceof Cuenta ? $cuenta->abonado()->redondeado(2) : '0',
                                    'rgb(22 163 74)',
                                );
                            }),

                        Placeholder::make('resumen_saldo')
                            ->label(function (): string {
                                $cuenta = $this->cuentaAbonando();

                                return $cuenta instanceof Cuenta && $cuenta->saldoPendiente()->esNegativo()
                                    ? 'A favor del paciente'
                                    : 'Falta por pagar';
                            })
                            ->content(function (): HtmlString {
                                $cuenta = $this->cuentaAbonando();

                                if (! $cuenta instanceof Cuenta) {
                                    return self::numeroGrande('0');
                                }

                                $saldo = $cuenta->saldoPendiente();

                                return $saldo->esNegativo()
                                    ? self::numeroGrande($cuenta->saldoAFavor()->redondeado(2), 'rgb(22 163 74)')
                                    : self::numeroGrande($saldo->redondeado(2), 'rgb(217 119 6)');
                            }),
                    ]),

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
                Repeater::make('medios')
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
                    ]),

                /*
                 * Lo que se está recibiendo AHORA y cómo queda la cuenta
                 * si se aprieta Recibir. Es la verificación que hoy la
                 * cajera hace de cabeza con el paciente esperando.
                 */
                Placeholder::make('recibiendo')
                    ->hiddenLabel()
                    ->content(function (Get $get): HtmlString {
                        $cuenta = $this->cuentaAbonando();
                        $suma = $this->sumaDeMedios($get('medios'));

                        if (! $cuenta instanceof Cuenta || $suma->esCero()) {
                            return new HtmlString('&nbsp;');
                        }

                        $queda = $cuenta->saldoPendiente()->restar($suma);

                        $texto = 'Recibiendo <strong>L '.number_format((float) $suma->redondeado(2), 2).'</strong> · ';

                        $texto .= $queda->esNegativo()
                            ? 'quedarían L '.number_format((float) Decimal::cero()->restar($queda)->redondeado(2), 2).' a favor del paciente'
                            : ($queda->esCero()
                                ? 'la cuenta queda saldada'
                                : 'faltarían L '.number_format((float) $queda->redondeado(2), 2));

                        return new HtmlString(
                            '<span style="font-size:.95rem">'.$texto.'</span>'
                        );
                    }),

                /*
                 * Casi siempre lo deja el paciente, así que ese es el
                 * default y no hay nada que teclear. Cuando lo deja otro
                 * —la hija, el patrón, el vecino— ahí sí se pide el
                 * nombre: es la primera pregunta cuando alguien reclama
                 * un recibo perdido.
                 */
                Section::make()
                    ->compact()
                    ->columns(2)
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

                        TextInput::make('entregado_por')
                            ->label('¿Quién?')
                            ->maxLength(120)
                            ->required()
                            ->placeholder('Nombre de quien deja el dinero')
                            ->visible(fn (Get $get): bool => $get('quien_entrega') === 'otro'),

                        Textarea::make('nota')
                            ->label('Nota')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),
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
     * Un monto en grande, para los tres números de arriba del modal.
     *
     * ⚠️ Estilo en línea a propósito: el CSS de Filament viene
     * precompilado y las clases de Tailwind que el panel no usa no
     * existen (§9.A7).
     */
    private static function numeroGrande(string $monto, ?string $color = null): HtmlString
    {
        $estilo = 'font-size:1.35rem;font-weight:700;font-variant-numeric:tabular-nums';

        if ($color !== null) {
            $estilo .= ';color:'.$color;
        }

        return new HtmlString('<span style="'.$estilo.'">L '.number_format((float) $monto, 2).'</span>');
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

                        Textarea::make('observacion')
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
            ->with(['item', 'almacen'])
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
                        descuentoComercialPorcentaje: $fraccion,
                        motivoDescuento: $fraccion instanceof Decimal ? $motivo : null,
                        autorizadoPor: $fraccion instanceof Decimal ? $cuenta->descuento_hospital_por : null,
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

            return;
        }

        $this->cuentaDelDescuento = $cuenta->id;

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

        $motivo = is_string($this->motivoDeLaTanda) ? trim($this->motivoDeLaTanda) : '';

        if (mb_strlen($motivo) < 10) {
            return;
        }

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

    /**
     * El rango de edad del paciente de esta cuenta, como texto.
     *
     * @param array<string, mixed> $arguments
     */
    private function rangoDelPacienteDe(array $arguments): ?string
    {
        $cuenta = $this->cuentaDe($arguments);

        if (! $cuenta instanceof Cuenta) {
            return null;
        }

        return $cuenta->encuentro->persona->rangoDeEdadEn(now())?->value;
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
     * La cuenta viva del paciente, si la tiene.
     *
     * Congelada cuenta como viva: congelar es el paso previo a cerrar, no
     * un cierre, y esa cuenta todavía va a salir en una factura.
     */
    /**
     * El nombre del paciente, con su cuenta viva al lado si la tiene.
     *
     * El rótulo va en la propia opción y no en un aviso aparte: la opción
     * apagada explica sola por qué no se puede elegir, y de paso dice a
     * qué cuenta hay que ir.
     */
    private function conSuCuentaAbierta(Persona $persona): string
    {
        $abierta = $this->cuentaVivaDe($persona->id);

        return $abierta instanceof Cuenta
            ? $persona->nombreCompleto().' — ya tiene '.$abierta->numero.' abierta'
            : $persona->nombreCompleto();
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
     *      pistola frente al estante;
     *   2. el `codigo` del ítem — lo que teclea alguien que se lo sabe.
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

        if (! is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        $item = Item::query()
            ->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])
            ->first();

        return $item instanceof Item ? $item : null;
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
     */
    public function topeDeDescuento(mixed $rango): Decimal
    {
        return app(PoliticaDeDescuentoComercial::class)->topePara(
            is_string($rango) ? RangoEdad::tryFrom($rango) : null
        );
    }

    /**
     * Corto a propósito: el porqué está en el código y en el mensaje de
     * error, no debajo de un campo que alguien mira de reojo entre dos
     * pacientes. Acá solo va el número que necesita para decidir.
     */
    public function ayudaDelDescuento(mixed $rango): string
    {
        $tope = $this->topeDeDescuento($rango);

        if ($tope->esCero()) {
            return 'No aplica: ya recibe el descuento de ley más alto.';
        }

        $caso = is_string($rango) ? RangoEdad::tryFrom($rango) : null;

        /*
         * «Aplica a lo que agregues» y no «aplica a la cuenta»: los cargos
         * que ya están asentados NO se tocan. Un cargo asentado tiene su
         * movimiento de kardex y su fila en la bitácora; cambiarlo por
         * atrás dejaría dos verdades sobre a cuánto se vendió. Si hay que
         * rehacer una línea, se anula y se vuelve a cargar — y ahí queda
         * escrito que se rehizo.
         */
        return $caso instanceof RangoEdad && $caso->tieneDescuentoLegal()
            ? 'Hasta '.$tope->comoPorcentaje().', además del de ley. Aplica a toda la cuenta.'
            : 'Hasta '.$tope->comoPorcentaje().'. Aplica a toda la cuenta.';
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
     * Las tres formas de contar lo mismo.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ESTO ES UNA CONVERSIÓN Y NO UN PRECIO DISTINTO
     * ─────────────────────────────────────────────────────────────────
     *
     * El kardex y el tarifario se llevan SIEMPRE en unidad de
     * dispensación (§8.7). Vender la caja de cien tabletas no es otro
     * precio: son cien tabletas. Por eso este selector convierte la
     * CANTIDAD y no toca el precio unitario.
     *
     * Si algún día el hospital quiere cobrar la caja más barata que cien
     * tabletas sueltas, eso es una fila de tarifario para otro ítem —o
     * un descuento con motivo y autorizador—, nunca un número escondido
     * en una conversión de unidades. Un descuento invisible es la fuga de
     * caja que el §8.6.2-4 existe para evitar.
     *
     * @return array<string, string>
     */
    public function unidadesDeCobro(?Item $item): array
    {
        if (! $item instanceof Item) {
            return [];
        }

        $unidad = $this->unidadDe($item);

        $opciones = [];

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 LO QUE NO SE FRACCIONA NO SE COBRA POR MILILITRO
         * ─────────────────────────────────────────────────────────────
         *
         * Un jarabe que el hospital NO fracciona se entrega en frascos
         * cerrados. Si la receta pide 20 ml cada 6 horas por 3 días —240
         * ml—, lo que se le cobra son DOS frascos de 120, no «240 ml»:
         * el hospital entregó dos frascos y ya no puede vender lo que
         * sobre.
         *
         * Ofrecer el mililitro ahí no es una comodidad, es una fuga: se
         * cobran 240 ml y salen del estante 240 de un frasco de 120 que
         * queda abierto, sin dueño y sin cobrar. Al mes son varios
         * frascos que nadie pagó.
         *
         * Los que SÍ se fraccionan —contados, y marcados como tales en el
         * catálogo— conservan el mililitro, que es justamente su gracia.
         *
         * La condición mira la MAGNITUD y no una lista de productos: una
         * unidad de conteo —TABLETA, AMPOLLA— se entrega suelta por
         * naturaleza y nunca pierde esta opción.
         */
        if ($this->seEntregaSuelta($item)) {
            $opciones[self::POR_UNIDAD] = $unidad ?? 'Unidad';
        }

        /*
         * La fracción primero entre las «menores»: un frasco que se
         * fracciona se cobra por ml muchas más veces que por frasco.
         */
        if ($item->fraccionable && $item->unidad_fraccion_id !== null && $item->fracciones_por_unidad !== null) {
            $opciones[self::POR_FRACCION] = $item->unidadFraccion->etiqueta()
                .' — '.rtrim(rtrim($item->fracciones_por_unidad, '0'), '.')
                .' por '.($unidad ?? 'unidad');
        }

        $codigo = self::codigoDeLaUnidad($item) ?? $unidad ?? 'unidades';

        foreach ($this->presentacionesDe($item) as $presentacion) {
            $opciones[self::POR_PRESENTACION.$presentacion->id] = self::envaseCorto($item, $presentacion)
                .' — '.rtrim(rtrim((string) $presentacion->unidades_por_presentacion, '0'), '.')
                .' '.$codigo;
        }

        /*
         * ⚠️ NUNCA VACÍO. Un ítem de volumen que todavía no tiene su
         * presentación cargada se quedaría sin ninguna forma de cobrarse
         * —el mostrador vería un desplegable en blanco y el paciente
         * esperando—. Ahí vuelve el mililitro: cobrar en la unidad del
         * kardex es peor que la regla, pero es infinitamente mejor que no
         * poder cobrar. El hueco se arregla cargando la presentación.
         */
        if ($opciones === []) {
            $opciones[self::POR_UNIDAD] = $unidad ?? 'Unidad';
        }

        return $opciones;
    }

    /**
     * Cómo se llama el envase sin repetir el producto: «FRASCO 60 ML»,
     * «CAJA X 100 TABLETAS».
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL NOMBRE DEL PRODUCTO YA ESTÁ ARRIBA, TRES VECES
     * ─────────────────────────────────────────────────────────────────
     *
     * El nombre guardado de una presentación es «PRODUCTO / envase», y en
     * este catálogo la mitad de atrás vuelve a nombrar el producto:
     * «ACETAMINOFEN JARABE / ACETAMINOFEN JARABE 60 ML».
     *
     * Puesto entero en el desplegable, el renglón salía
     * «ACETAMINOFEN JARABE 60 ML — 60 MILILITRO (ml)»: cuarenta y cinco
     * caracteres en una columna de tres, partidos en tres líneas, con el
     * dato que distingue —60 ML— al final. Cuatro campos así uno al lado
     * del otro es la pantalla amontonada que nadie quiere leer con el
     * paciente enfrente.
     *
     * Se recorta palabra por palabra lo que el producto ya dice, y se
     * antepone la unidad del envase solo si no está.
     */
    private static function envaseCorto(Item $item, ItemPresentacion $presentacion): string
    {
        $envase = trim(Str::after((string) $presentacion->nombre, ' / '));
        $producto = trim((string) $item->nombre);

        $delEnvase = preg_split('/\s+/', $envase) ?: [];
        $delProducto = preg_split('/\s+/', $producto) ?: [];

        $comunes = 0;

        while (
            isset($delEnvase[$comunes], $delProducto[$comunes])
            && $delEnvase[$comunes] === $delProducto[$comunes]
        ) {
            $comunes++;
        }

        $resto = trim(implode(' ', array_slice($delEnvase, $comunes)));
        $corto = $resto === '' ? $envase : $resto;

        $unidad = $presentacion->unidad->codigo;

        return str_contains($corto, $unidad) ? $corto : trim($unidad.' '.$corto);
    }

    /**
     * ¿Este producto se entrega suelto, sin envase?
     *
     * Una TABLETA o una AMPOLLA sí: son unidades de conteo, se sacan del
     * blíster y se entregan. Un jarabe medido en ML no, salvo que el
     * hospital lo fraccione —y eso es una decisión declarada en el
     * catálogo, no algo que se deduzca del producto—.
     *
     * Sin presentaciones cargadas también devuelve true: no hay envase
     * que ofrecer, así que la unidad suelta es lo único que hay.
     */
    private function seEntregaSuelta(Item $item): bool
    {
        if ($item->fraccionable) {
            return true;
        }

        $magnitud = $item->unidadDispensacion?->magnitud;

        if ($magnitud === null || $magnitud === MagnitudDeMedida::Conteo) {
            return true;
        }

        return $this->presentacionesDe($item)->isEmpty();
    }

    /**
     * Un renglón del selector por cada forma en que se entrega este
     * producto, con el nombre adelante.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL PRODUCTO NO SE ENTREGA EN ABSTRACTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Nadie despacha «ACETAMINOFEN JARABE»: despacha un frasco de 60 o
     * uno de 120. Eran dos desplegables encadenados para una sola
     * decisión —qué producto arriba, en qué envase abajo—, y el de abajo
     * era además el que nadie miraba: se elegía el producto, se tecleaba
     * la cantidad y se apretaba Agregar con el envase que hubiera
     * quedado.
     *
     * Acá cada forma es una opción con su propio nombre, así que elegir
     * ya es elegir el envase.
     *
     * ⚠️ El producto que tiene UNA sola forma no se abre: un honorario,
     * una consulta o un examen no tienen envase, y «CONSULTA GENERAL —
     * Unidad» es ruido en el único renglón que iba a haber. Ahí el valor
     * sigue siendo el id pelado.
     *
     * @return array<int|string, string>
     */
    private function formasDeEntrega(Item $item): array
    {
        $formas = $this->unidadesDeCobro($item);

        if (count($formas) <= 1) {
            return [$item->id => $item->etiqueta()];
        }

        $filas = [];

        foreach ($formas as $clave => $etiqueta) {
            $filas[$item->id.self::SEPARADOR_DE_FORMA.$clave] = $item->nombre.' — '.self::soloLaForma($etiqueta);
        }

        return $filas;
    }

    /**
     * Cómo se lee lo que quedó elegido en el selector.
     */
    private function etiquetaDeLoElegido(mixed $valor): ?string
    {
        $item = $this->itemDe($valor);

        if (! $item instanceof Item) {
            return null;
        }

        $filas = $this->formasDeEntrega($item);

        return is_int($valor) || is_string($valor)
            ? ($filas[$valor] ?? $item->nombre)
            : $item->nombre;
    }

    /**
     * «FRASCO 60 ML — 60 ml» → «FRASCO 60 ML».
     *
     * El contenido lo repite el envase casi siempre —un frasco de 60 ml
     * trae 60 ml— y lo que no repite lo dice la equivalencia debajo de la
     * cantidad, que es donde hace falta leerlo. Acá alargaba el renglón
     * hasta partirlo en dos.
     */
    private static function soloLaForma(string $etiqueta): string
    {
        $corto = mb_strstr($etiqueta, ' —', true);

        return $corto === false || $corto === '' ? $etiqueta : $corto;
    }

    /**
     * El valor que hay que ponerle al selector para dejar elegido este
     * producto en la forma en que se despacha por defecto.
     *
     * ⚠️ NO se usa el envase que se acaba de escanear aunque se conozca.
     * El código de barras vive en la presentación, así que pasar la
     * pistola por una caja de cien dejaría «1 CAJA X 100» cargado, y en
     * un hospital lo que se dispensa son dos tabletas. El default sigue
     * siendo el más barato de los posibles: el escaneo dice QUÉ es,
     * cuánto y en qué forma lo dice la persona.
     */
    private function valorDelSelector(?Item $item): int|string|null
    {
        if (! $item instanceof Item) {
            return null;
        }

        $formas = $this->unidadesDeCobro($item);

        if (count($formas) <= 1) {
            return $item->id;
        }

        return $item->id.self::SEPARADOR_DE_FORMA.$this->unidadDeCobroPorDefecto($item);
    }

    /**
     * Varios productos abiertos por forma, listos para el desplegable.
     *
     * @param  iterable<int, Item>  $items
     * @return array<int|string, string>
     */
    private function filasDelSelector(iterable $items): array
    {
        $filas = [];

        foreach ($items as $item) {
            foreach ($this->formasDeEntrega($item) as $clave => $etiqueta) {
                $filas[$clave] = $etiqueta;
            }
        }

        return $filas;
    }

    /**
     * En qué forma se está cobrando la línea que se está tecleando.
     *
     * Manda lo que dice el SELECTOR, no el campo escondido: el valor del
     * selector es la elección que la persona vio y tocó, y el campo
     * escondido puede venir pegado del ítem anterior cuando el Enter de
     * la pistola manda el formulario antes de que el formulario se
     * vuelva a pintar.
     */
    private function formaEnUso(Get $get): string
    {
        $valor = $get('item_id');
        $item = $this->itemDe($valor);
        $forma = self::formaDe($valor);

        if ($forma === null) {
            $escondida = $get('unidad_cobro');
            $forma = is_string($escondida) && $escondida !== '' ? $escondida : null;
        }

        if (! $item instanceof Item) {
            return $forma ?? self::POR_UNIDAD;
        }

        return $forma !== null && isset($this->unidadesDeCobro($item)[$forma])
            ? $forma
            : $this->unidadDeCobroPorDefecto($item);
    }

    /**
     * Con qué unidad viene marcado el selector al elegir el producto.
     *
     * El envase habitual primero —el que el hospital marcó como
     * predeterminado—, después el más chico, y recién al final la unidad
     * suelta. Es el orden en que se despacha: quien pide un jarabe se
     * lleva un frasco, no una lista de mililitros.
     */
    private function unidadDeCobroPorDefecto(Item $item): string
    {
        $opciones = $this->unidadesDeCobro($item);

        if ($opciones === []) {
            return self::POR_UNIDAD;
        }

        if (isset($opciones[self::POR_UNIDAD]) && $this->seEntregaSuelta($item)) {
            return self::POR_UNIDAD;
        }

        $habitual = $this->presentacionesDe($item)
            ->sortByDesc('es_predeterminada')
            ->first();

        $clave = $habitual instanceof ItemPresentacion
            ? self::POR_PRESENTACION.$habitual->id
            : null;

        return $clave !== null && isset($opciones[$clave])
            ? $clave
            : (string) array_key_first($opciones);
    }

    /**
     * Las presentaciones vigentes del ítem, en orden de contenido.
     *
     * @return ColeccionDeModelos<int, ItemPresentacion>
     */
    private function presentacionesDe(Item $item): ColeccionDeModelos
    {
        /*
         * 🔴 Memoria por pintada, por la misma razón que los estantes.
         * Desde que la unidad de cobro depende del envase, esto se
         * pregunta seis veces por render —las opciones del selector, si
         * el producto se entrega suelto, cuál viene marcado, y la
         * conversión de cada estante—. Sin la memoria son seis consultas
         * por tecla en el buscador.
         */
        if (isset($this->presentacionesPorItem[$item->id])) {
            return $this->presentacionesPorItem[$item->id];
        }

        return $this->presentacionesPorItem[$item->id] = ItemPresentacion::query()
            ->with('unidad:id,codigo,nombre')
            ->where('item_id', $item->id)
            ->orderBy('unidades_por_presentacion')
            ->get();
    }

    /**
     * Lo tecleado, llevado a unidades de dispensación.
     *
     * Devuelve `null` cuando la unidad elegida no se puede resolver —una
     * presentación de otro ítem, una fracción en algo que no se
     * fracciona—. Nunca un cero: el cero es una cantidad legal y
     * confundirlos sería cobrar la nada (lección de `NumeroDeFormulario`).
     */
    private function aUnidadesDeDispensacion(Item $item, string $unidad, Decimal $cantidad): ?Decimal
    {
        if ($unidad === self::POR_UNIDAD) {
            return $cantidad;
        }

        if ($unidad === self::POR_FRACCION) {
            if (! $item->fraccionable || $item->fracciones_por_unidad === null) {
                return null;
            }

            return $cantidad->entre(Decimal::de($item->fracciones_por_unidad));
        }

        if (! str_starts_with($unidad, self::POR_PRESENTACION)) {
            return null;
        }

        $presentacion = ItemPresentacion::query()
            ->find((int) mb_substr($unidad, mb_strlen(self::POR_PRESENTACION)));

        if (! $presentacion instanceof ItemPresentacion || $presentacion->item_id !== $item->id) {
            return null;
        }

        return Decimal::de($presentacion->aUnidadesDeDispensacion($cantidad->redondeado(4)));
    }

    /**
     * «2 CAJA X 100 TABLETAS = 200 TABLETA», para leerlo antes de apretar.
     */
    private function equivalencia(Item $item, string $unidad, mixed $cantidad): ?string
    {
        $enLaUnidad = $this->unidadDe($item);

        if ($unidad === self::POR_UNIDAD) {
            return $enLaUnidad === null ? null : 'En '.$enLaUnidad.'.';
        }

        $tecleada = NumeroDeFormulario::aDecimal($cantidad);

        if (! $tecleada instanceof Decimal) {
            return null;
        }

        $convertida = $this->aUnidadesDeDispensacion($item, $unidad, $tecleada);

        if (! $convertida instanceof Decimal) {
            return null;
        }

        $etiqueta = $this->unidadesDeCobro($item)[$unidad] ?? '';

        return sprintf(
            '%s %s = %s %s',
            rtrim(rtrim($tecleada->redondeado(4), '0'), '.'),
            mb_strstr($etiqueta, ' —', true) ?: $etiqueta,
            rtrim(rtrim($convertida->redondeado(4), '0'), '.'),
            /*
             * El CÓDIGO y no la etiqueta completa: «60 ml» en vez de «60
             * MILILITRO (ml)». Es la línea que va debajo de un campo
             * angosto, y la palabra larga la partía en tres.
             */
            self::codigoDeLaUnidad($item) ?? $enLaUnidad ?? 'unidades',
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

    /**
     * Los ocho ítems que más se cargaron últimamente en esta sede.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ESTO AHORRA MÁS TIEMPO QUE CUALQUIER OTRA COSA
     * ─────────────────────────────────────────────────────────────────
     *
     * Una ronda de medicamentos son veinte líneas y casi siempre las
     * mismas seis cosas. Buscarlas por nombre veinte veces es el trabajo;
     * apretarlas es el atajo. Sale de lo que el hospital REALMENTE carga,
     * no de una lista que alguien tenga que mantener.
     *
     * Con la base recién sembrada todavía no hay historial, así que cae
     * en los primeros ítems con precio de lista vigente: el día uno la
     * pantalla ya sirve para algo.
     *
     * @param array<string, mixed> $arguments
     *
     * @return ColeccionDeModelos<int, Item>
     */
    public function itemsFrecuentes(array $arguments): ColeccionDeModelos
    {
        $cuenta = $this->cuentaDe($arguments);

        if (! $cuenta instanceof Cuenta) {
            return new ColeccionDeModelos;
        }

        $ids = Cargo::query()
            ->where('sede_id', $cuenta->sede_id)
            ->where('fecha_operacion', '>=', now()->subDays(60)->toDateString())
            ->where('estado', '<>', EstadoCargo::Anulado->value)
            ->selectRaw('item_id, count(*) as veces')
            ->groupBy('item_id')
            ->orderByDesc('veces')
            ->limit(8)
            ->pluck('item_id');

        /*
         * 🔴 Los atajos también respetan la vigencia. Un ítem retirado
         * —el nombre mal escrito que alguien creó por error— fue de lo
         * más cargado justamente PORQUE se estuvo cobrando, así que sin
         * este filtro se quedaría de botón en la banda: el lugar de la
         * pantalla donde cobrarlo cuesta un solo clic.
         */
        if ($ids->isEmpty()) {
            $consulta = Item::query()
                ->whereHas('precios', fn ($precios) => $precios->whereNull('convenio_id'))
                ->vigentesEn(now());

            CatalogoDelRol::filtrar($consulta);

            return $consulta->orderBy('nombre')->limit(8)->get();
        }

        $consulta = Item::query()
            ->whereIn('id', $ids)
            ->vigentesEn(now());

        /*
         * 🔴 Y también por área. Los atajos salen de lo que MÁS se cargó
         * en la sede, o sea de lo que carga caja: medicamentos, estancias,
         * consultas. Sin este filtro, el laboratorista abría la cuenta y
         * la banda de arriba le ofrecía ocho botones de un solo clic para
         * cobrar exactamente lo que no le corresponde.
         */
        CatalogoDelRol::filtrar($consulta);

        return $consulta->orderBy('nombre')->get();
    }

    /**
     * Un clic = una unidad. El atajo de «lo más usado».
     *
     * ⚠️ Es un método público de Livewire, así que **se puede invocar
     * desde el cliente aunque el botón no exista**. La autorización se
     * verifica acá adentro, no en la vista.
     */
    public function agregarRapido(int $cuenta, int $item): void
    {
        abort_unless(Gate::allows('create', Cargo::class), 403);

        $laCuenta = $this->cuentaDe(['cuenta' => $cuenta]);
        $elItem = $this->itemDe($item);

        if (! $laCuenta instanceof Cuenta || ! $elItem instanceof Item) {
            return;
        }

        abort_unless(Gate::allows('update', $laCuenta), 403);

        /*
         * El botón puede no existir en la pantalla y este método sigue
         * siendo invocable desde el cliente: la verificación de área va
         * acá, igual que la de permisos.
         */
        if (! CatalogoDelRol::puedeCargar($elItem)) {
            Notification::make()
                ->danger()
                ->title('Eso no se carga desde tu área')
                ->body(CargoException::noEsDeSuArea($elItem->nombre, $elItem->tipo->etiqueta())->getMessage())
                ->persistent()
                ->send();

            return;
        }

        /*
         * Lo que mueve inventario NO se carga de un clic: hay que decir de
         * qué almacén sale, y adivinarlo sería inventar un faltante en el
         * estante equivocado. Se deja el ítem puesto en el formulario y se
         * pide lo único que falta.
         */
        if ($elItem->mueveInventario()) {
            Notification::make()
                ->info()
                ->title($elItem->nombre)
                ->body('Decí de qué almacén sale y presioná Agregar.')
                ->send();

            $this->replaceMountedAction('cargarEnCuenta', [
                'cuenta' => $laCuenta->id,
                'item'   => $elItem->id,
            ]);

            return;
        }

        $this->agregar($laCuenta, ['item_id' => $elItem->id, 'cantidad' => 1]);

        $this->replaceMountedAction('cargarEnCuenta', ['cuenta' => $laCuenta->id]);
    }

    private function itemDe(mixed $valor): ?Item
    {
        $id = self::idDelItem($valor);

        if ($id === null) {
            return null;
        }

        $item = Item::query()->find($id);

        return $item instanceof Item ? $item : null;
    }

    /**
     * El ítem que hay dentro del valor del selector.
     *
     * «705» y «705|presentacion:12» son el mismo producto: lo segundo
     * dice además en qué envase se está entregando.
     */
    private static function idDelItem(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (! is_string($valor) || $valor === '') {
            return null;
        }

        $izquierda = mb_strstr($valor, self::SEPARADOR_DE_FORMA, true);
        $id = $izquierda === false ? $valor : $izquierda;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * La forma de entrega que trae el valor del selector, o nulo cuando
     * el producto tiene una sola y el valor viaja pelado.
     */
    private static function formaDe(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $derecha = mb_strstr($valor, self::SEPARADOR_DE_FORMA);

        if ($derecha === false) {
            return null;
        }

        $forma = mb_substr($derecha, 1);

        return $forma === '' ? null : $forma;
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
     * @return array<int, string>
     */
    private function expedientesDe(mixed $personaId): array
    {
        if (! is_numeric($personaId)) {
            return [];
        }

        return Expediente::query()
            ->where('persona_id', (int) $personaId)
            ->orderByDesc('abierto_el')
            ->pluck('numero', 'id')
            ->all();
    }

    /**
     * El código escaneado resuelve el ítem y deja el foco listo para la
     * cantidad. Si no lo encuentra, lo dice y no adivina.
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
     * El prefijo alcanza para distinguirlas sin preguntarle nada a quien
     * escanea, que es todo el punto de que el prefijo exista.
     */
    private function resolverEscaneo(mixed $codigo, Set $set): void
    {
        if (! is_string($codigo) || trim($codigo) === '') {
            return;
        }

        $this->principioEscaneado = null;

        if (PrincipioActivo::pareceUnCodigoSuyo($codigo)) {
            $this->resolverGaveta($codigo, $set);

            return;
        }

        $presentacion = $this->presentacionPorCodigo($codigo);
        $item = $this->itemPorCodigo($codigo);

        /*
         * ─────────────────────────────────────────────────────────────
         * NO ERA UN CÓDIGO: SE BUSCA POR NOMBRE
         * ─────────────────────────────────────────────────────────────
         *
         * El mismo campo sirve para las dos cosas, que es como se usa de
         * verdad: la pistola escribe un código y una persona escribe
         * «acetamin». Antes lo segundo terminaba en un aviso de «ese
         * código no está», obligando a repetir el nombre en el selector
         * de abajo.
         */
        if (! $item instanceof Item) {
            $this->buscarPorNombre($codigo, $set);

            return;
        }

        $this->busquedaEscrita = null;

        $valor = $this->valorDelSelector($item);

        $set('item_id', $valor);
        $this->prepararLaLinea($valor, $set);

        /*
         * ⚠️ La cantidad queda en UNO, no en el contenido de la caja.
         *
         * La tentación es poner 100 al escanear una caja de 100 tabletas.
         * Sería cobrarle una caja entera al paciente por pasar la pistola:
         * en un hospital se dispensan dos tabletas, no el envase. El
         * escaneo dice QUÉ es; cuánto lo dice la persona.
         */
        $set('cantidad', 1);

        $unidad = $this->unidadDe($item);

        Notification::make()
            ->success()
            ->title($item->nombre)
            ->body(
                ($presentacion instanceof ItemPresentacion
                    ? 'Leído de «'.$presentacion->nombre.'». '
                    : '')
                .($unidad === null
                    ? 'Escribí la cantidad y presioná Agregar.'
                    /*
                     * Se avisa que quedó en la unidad suelta, no en la
                     * caja. El default nunca cobra de más: si de verdad
                     * se vende el envase entero, hay que decirlo.
                     */
                    : 'Se cobra por '.$unidad.'. Si vendés el envase entero, elegí ese renglón en '
                        .'«¿Qué se le agrega?»: el mismo producto está una vez por cada envase.')
            )
            ->send();
    }

    /**
     * La etiqueta de la gaveta: acota la lista a lo que lleva ese
     * principio activo, y solo elige cuando no hay nada que elegir.
     */
    private function resolverGaveta(string $codigo, Set $set): void
    {
        $principio = PrincipioActivo::query()
            ->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])
            ->first();

        if (! $principio instanceof PrincipioActivo) {
            Notification::make()
                ->warning()
                ->title('Esa etiqueta no está en el catálogo')
                ->body(
                    'El código arranca con «'.PrincipioActivo::PREFIJO.'», así que es una etiqueta de '
                    .'gaveta, pero ningún principio activo la tiene. Puede ser de una gaveta vieja: '
                    .'reimprimí la etiqueta desde Farmacia → Principios activos.'
                )
                ->persistent()
                ->send();

            return;
        }

        $productos = $this->productosVigentesDe($principio);

        if ($productos === []) {
            Notification::make()
                ->warning()
                ->title('Nada vigente con '.$principio->nombre)
                ->body(
                    'La gaveta está etiquetada pero hoy ningún producto del catálogo lo lleva. Se '
                    .'vincula desde la ficha del producto, en «Principios activos».'
                )
                ->persistent()
                ->send();

            return;
        }

        $this->principioEscaneado = (int) $principio->getKey();
        $set('cantidad', 1);

        /*
         * Uno solo no es una elección: es el producto. Obligar a abrir un
         * desplegable de un renglón es un clic que no decide nada.
         */
        if (count($productos) === 1) {
            $valor = array_key_first($productos);

            $set('item_id', $valor);
            $this->prepararLaLinea($valor, $set);

            Notification::make()
                ->success()
                ->title($principio->nombre)
                ->body(
                    'El único vigente que lo lleva: '.reset($productos)
                    .'. Escribí la cantidad y presioná Agregar.'
                )
                ->send();

            return;
        }

        $set('item_id', null);

        /*
         * ⚠️ Se cuentan PRODUCTOS, no renglones. Desde que el selector se
         * abre por envase, un jarabe con tres frascos son tres renglones
         * y un solo producto: decir «3 productos» mandaba a buscar dos
         * que no existen.
         */
        $cuantos = count(array_unique(array_map(
            static fn (int|string $clave): ?int => self::idDelItem($clave),
            array_keys($productos),
        )));

        Notification::make()
            ->success()
            ->title($principio->nombre.' · '.$cuantos.' '.($cuantos === 1 ? 'producto' : 'productos'))
            ->body(
                'Abrí «¿Qué se le agrega?» y elegí en qué forma se le dio. La lista quedó acotada a '
                .'los que llevan este principio activo.'
            )
            ->send();
    }

    /**
     * Los productos vigentes que llevan ese principio activo, ya
     * rotulados para el desplegable.
     *
     * La consulta vive en el modelo —`PrincipioActivo::productosVigentes()`—
     * y no acá: es una pregunta del negocio, se hace desde más de una
     * pantalla, y en un método privado de una página de Filament no hay
     * forma de probarla.
     *
     * @return array<int, string>
     */
    private function productosVigentesDe(PrincipioActivo $principio): array
    {
        $id = (int) $principio->getKey();

        if (array_key_exists($id, $this->productosPorPrincipio)) {
            return $this->productosPorPrincipio[$id];
        }

        $productos = $this->filasDelSelector($principio->productosVigentes());

        return $this->productosPorPrincipio[$id] = $productos;
    }

    /**
     * Las opciones que ve el desplegable: los del principio escaneado, o
     * ninguna cuando no hay gaveta de por medio —ahí manda la búsqueda—.
     *
     * @return array<int|string, string>
     */
    private function productosDelPrincipioEscaneado(): array
    {
        if ($this->principioEscaneado === null) {
            return [];
        }

        $principio = PrincipioActivo::query()->find($this->principioEscaneado);

        return $principio instanceof PrincipioActivo
            ? $this->productosVigentesDe($principio)
            : [];
    }

    private function nombreDelPrincipioEscaneado(): string
    {
        if ($this->principioEscaneado === null) {
            return '';
        }

        $principio = PrincipioActivo::query()->find($this->principioEscaneado);

        return $principio instanceof PrincipioActivo ? $principio->nombre : '';
    }

    /**
     * Lo que devuelve el buscador del selector.
     *
     * Con una gaveta escaneada busca DENTRO de esos productos y no en
     * todo el catálogo: si la lista dice «solo lo que lleva
     * ACETAMINOFÉN», teclear tiene que seguir respetando eso. Un filtro
     * que se rompe al escribir es peor que no tenerlo.
     *
     * @return array<int|string, string>
     */
    /**
     * Lo escrito no era un código: se busca en el catálogo.
     *
     *   · Un solo resultado → se elige solo. Escribir «hemogr» y apretar
     *     Enter carga el hemograma sin tocar el mouse.
     *   · Varios            → el selector de abajo se abre con esos, sin
     *     volver a teclear.
     *   · Ninguno           → se dice que no hay, y por qué puede ser.
     */
    private function buscarPorNombre(string $texto, Set $set): void
    {
        $termino = trim($texto);

        $encontrados = Item::buscar($termino, soloVigentes: true, soloDelRol: true)
            ->take((int) config('sihla.facturacion.resultados_de_busqueda', 12));

        if ($encontrados->isEmpty()) {
            $this->busquedaEscrita = null;

            Notification::make()
                ->warning()
                ->title('No hay nada con «'.$termino.'»')
                ->body(
                    'Ni como código ni como nombre. Ojo: el código de barras se registra en la '
                    .'PRESENTACIÓN —la caja, el frasco, el blíster—, no en el ítem; si el producto existe '
                    .'pero nunca se le cargó su presentación, la pistola no lo va a encontrar.'
                )
                ->persistent()
                ->send();

            return;
        }

        if ($encontrados->count() === 1) {
            /*
             * `firstOrFail` y no `first`: devuelve el ítem sin `null`,
             * así no hace falta un `instanceof` que en este punto ya es
             * siempre verdadero.
             */
            $primero = $encontrados->firstOrFail();

            $this->busquedaEscrita = null;

            $valor = $this->valorDelSelector($primero);

            $set('item_id', $valor);
            $set('cantidad', 1);
            $this->prepararLaLinea($valor, $set);

            Notification::make()
                ->success()
                ->title($primero->nombre)
                ->body('Escribí la cantidad y presioná Agregar.')
                ->send();

            return;
        }

        $this->busquedaEscrita = $termino;

        Notification::make()
            ->info()
            ->title($encontrados->count().' resultados con «'.$termino.'»')
            ->body('Abrí «¿Qué se le agrega?» y elegí: la lista ya viene con esos.')
            ->send();
    }

    /**
     * Con qué se abre el selector cuando nadie ha escrito nada en él.
     *
     * Primero manda la gaveta escaneada —es el filtro más específico— y
     * después lo que se escribió arriba. Sin ninguno de los dos, vacío:
     * el catálogo entero en un desplegable no se navega, se busca.
     *
     * ⚠️ Las llaves son `int|string` porque PHP convierte solo a entero
     * cualquier llave numérica: `['5' => …]` termina siendo `[5 => …]`.
     * Declararlo `array<string, string>` era mentirle al analizador.
     *
     * @return array<int|string, string>
     */
    private function opcionesDelSelector(): array
    {
        if ($this->principioEscaneado !== null) {
            return $this->productosDelPrincipioEscaneado();
        }

        if ($this->busquedaEscrita === null) {
            return [];
        }

        return $this->filasDelSelector(
            Item::buscar($this->busquedaEscrita, soloVigentes: true, soloDelRol: true)
                ->take((int) config('sihla.facturacion.resultados_de_busqueda', 12))
        );
    }

    /**
     * «ACETAMINOFÉN TABLETA · se cobra por TABLETA» — lo que quedó
     * elegido, fijo debajo del campo de escaneo.
     */
    private function resumenDeLoElegido(Get $get): HtmlString
    {
        $item = $this->itemDe($get('item_id'));

        if (! $item instanceof Item) {
            return new HtmlString('&nbsp;');
        }

        $partes = ['<strong>'.e($item->nombre).'</strong>'];

        /*
         * 🔴 Dice cómo se cobra DE VERDAD, no la unidad del kardex.
         *
         * Un jarabe que no se fracciona se cobra por frasco, y el
         * encabezado seguía anunciando «se cobra por MILILITRO (ml)»
         * mientras el selector de abajo solo ofrecía frascos. Dos
         * afirmaciones contradictorias a diez centímetros de distancia.
         */
        $comoSeCobra = $this->unidadesDeCobro($item)[$this->formaEnUso($get)] ?? null;

        if (is_string($comoSeCobra)) {
            $partes[] = 'se cobra por '.e(mb_strstr($comoSeCobra, ' —', true) ?: $comoSeCobra);
        }

        if ($item->codigo !== '') {
            $partes[] = e($item->codigo);
        }

        return new HtmlString(
            '<span style="font-size:.9rem">'.implode(' · ', $partes).'</span>'
        );
    }

    /**
     * La línea que va debajo de los cuatro campos: la equivalencia, el
     * FEFO, y el aviso cuando no hay existencia en ningún estante.
     *
     * Vive en un solo lugar para que lo que hay que leer antes de apretar
     * Agregar esté junto, y para que los campos de arriba queden todos
     * del mismo alto.
     */
    private function comoQuedaLaLinea(Get $get): HtmlString
    {
        $item = $this->itemDe($get('item_id'));

        if (! $item instanceof Item) {
            return new HtmlString('&nbsp;');
        }

        $unidadCobro = $this->formaEnUso($get);

        $partes = [];

        $equivalencia = $this->equivalencia($item, $unidadCobro, $get('cantidad'));

        if (is_string($equivalencia) && trim($equivalencia) !== '') {
            $partes[] = '<strong>'.e(rtrim($equivalencia, '.')).'</strong>';
        }

        if ($item->mueveInventario()) {
            if ($this->estantesConLoQueHay($item, $unidadCobro) === []) {
                return new HtmlString(
                    '<span style="font-size:.8125rem;color:rgb(248 113 113)">'
                    .'No hay existencia de este producto en ningún almacén. '
                    .'Hay que recibirlo o trasladarlo antes de cobrarlo.'
                    .'</span>'
                );
            }

            $partes[] = 'sale por FEFO: primero el lote que vence antes';
        }

        if ($partes === []) {
            return new HtmlString('&nbsp;');
        }

        return new HtmlString(
            '<span style="font-size:.8125rem">'.implode(' · ', $partes).'</span>'
        );
    }

    private function resultadosDeBusqueda(string $search): array
    {
        if ($this->principioEscaneado === null) {
            return $this->filasDelSelector(
                Item::buscar($search, soloVigentes: true, soloDelRol: true)
                    ->take((int) config('sihla.facturacion.resultados_de_busqueda', 12))
            );
        }

        $clave = NormalizadorDeTexto::clave($search);

        if ($clave === '') {
            return $this->productosDelPrincipioEscaneado();
        }

        return array_filter(
            $this->productosDelPrincipioEscaneado(),
            fn (string $etiqueta): bool => str_contains(NormalizadorDeTexto::clave($etiqueta), $clave),
        );
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

        return [
            'nombre'      => $persona->nombreCompleto(),
            'expediente'  => $encuentro->expediente->numero,
            'ingreso'     => $encuentro->abierto_en->format('d/m/Y H:i'),
            'desde'       => $encuentro->abierto_en->diffForHumans(),
            'tipo'        => $encuentro->tipo->etiqueta(),
            'servicio'    => $encuentro->servicio_id === null ? '' : $encuentro->servicio->nombre,
            'pagador'     => $cuenta->convenio->nombre,
            'total'       => $cuenta->saldo()->formateado(),
            'paciente'    => $cuenta->saldoDelPaciente()->formateado(),
            'aseguradora' => $cuenta->saldoDeLaAseguradora()->formateado(),
            'lineas'      => (string) $cuenta->lineas,
            'estado'      => $cuenta->estado->etiqueta(),
        ];
    }

    public function colorDelEstado(Cuenta $cuenta): string
    {
        return $cuenta->estado === EstadoCuenta::Congelada ? 'warning' : 'success';
    }
}
