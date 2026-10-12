<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Schemas;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoIdentificador;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Alta y edición de un paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA PANTALLA, EN EL ORDEN EN QUE SE PREGUNTA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «el registro debe ser rápido». Antes eran tres pestañas
 * —Identidad, Documento, Contacto— y para poner el DNI y el teléfono
 * había que ir a buscarlas. Ahora es una pantalla que se llena de
 * arriba abajo con Tab, en el orden en que admisión le pregunta al
 * paciente:
 *
 *   1. El documento, primero y con el cursor ya puesto. Al salir del
 *      campo, si ese número ya está registrado, lo dice ahí mismo con un
 *      botón a su ficha — antes se descubría al guardar, después de
 *      haber llenado todo.
 *   2. Nombres y apellidos en una fila.
 *   3. Fecha de nacimiento, que se TECLEA (dd/mm/aaaa) o se elige del
 *      calendario —el selector nativo del navegador hace las dos—, y al
 *      lado la edad. Si el paciente no sabe la fecha, se escribe la edad
 *      y la fecha queda estimada sola.
 *   4. Sexo con un clic, no con un desplegable.
 *   5. Teléfono y dirección.
 *
 * Lo que se usa poco —apellido de casada, género, correo, municipio—
 * va en «Más datos», cerrado. Las explicaciones largas pasaron a la (i)
 * de cada campo: siguen ahí para quien las necesite y no empujan el
 * formulario.
 *
 * ⚠️ Se aparta del patrón de pestañas del §10 a propósito: el registro
 * de admisión es una pantalla de alta frecuencia (§10.7), y en esas
 * manda la velocidad.
 *
 * El documento se pide en el alta y NO se edita desde acá: cambiar un
 * número de identidad no es corregir un campo, es un cambio de identidad
 * que tiene que quedar registrado con su motivo. Se gestiona desde la
 * ficha, en su propia sección.
 */
final class PacienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::paciente(),
            self::masDatos(),
            self::motivoDelCambio(),
            self::defuncion(),
            self::registro(),
        ]);
    }

    // ── 1 · Lo que se pregunta siempre ────────────────────────────────

    private static function paciente(): Section
    {
        /*
         * Doce columnas desde pantalla ancha (lg); en el celular, una
         * debajo de otra. Los anchos (`columnSpan`) son de lg para arriba.
         */
        return Section::make('Paciente')
            ->icon('heroicon-o-user')
            ->columns(['lg' => 12])
            ->columnSpanFull()
            ->schema([
                Select::make('documento_tipo')
                    ->label('Documento')
                    ->options(fn (): array => collect(TipoIdentificador::cases())
                        ->mapWithKeys(fn (TipoIdentificador $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->default(TipoIdentificador::Dni->value)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visibleOn('create')
                    ->columnSpan(['lg' => 3]),

                TextInput::make('documento_valor')
                    ->label('Número')
                    ->placeholder(fn (Get $get): string => self::ejemploDe($get('documento_tipo')))
                    ->mask(fn (Get $get): ?string => self::mascaraDe($get('documento_tipo')))
                    ->maxLength(40)
                    ->autofocus()
                    ->live(onBlur: true)
                    ->hintIcon(
                        'heroicon-o-information-circle',
                        tooltip: 'Opcional: un NN, un recién nacido y quien olvidó la cédula se registran igual. '
                            .'Se guarda solo con dígitos, da igual si se escribe con guiones.',
                    )
                    ->visibleOn('create')
                    ->columnSpan(fn (Get $get): array => ['lg' => self::pideElPais($get('documento_tipo')) ? 5 : 6]),

                TextInput::make('documento_pais')
                    ->label('País')
                    ->placeholder('HN')
                    ->maxLength(2)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create'
                        && self::pideElPais($get('documento_tipo')))
                    ->hintIcon('heroicon-o-information-circle', tooltip: 'El mismo número de pasaporte puede existir en dos países.')
                    ->columnSpan(['lg' => 1]),

                Toggle::make('es_nn')
                    ->label('Ingresó sin identificar (NN)')
                    ->live()
                    ->inline(false)
                    ->hintIcon(
                        'heroicon-o-information-circle',
                        tooltip: 'Permite registrar sin apellido y sin documento. Queda en la bandeja de '
                            .'«identificar antes del alta». En emergencia el sistema no frena.',
                    )
                    ->columnSpan(['lg' => 3]),

                /*
                 * 🔴 EL DUPLICADO SE AVISA AL SALIR DEL NÚMERO, NO AL
                 * GUARDAR. `RegistradorDePacientes` lo frena igual al
                 * final; esto le ahorra a admisión llenar todo para
                 * enterarse de que el paciente ya existía.
                 */
                Callout::make(fn (Get $get): string => 'Ya hay un paciente con ese documento: '
                    .(self::yaRegistrado($get)?->nombreCompleto() ?? ''))
                    ->description(fn (Get $get): HtmlString => self::detalleDe(self::yaRegistrado($get)))
                    ->warning()
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create'
                        && self::yaRegistrado($get) instanceof Persona)
                    ->columnSpanFull(),

                CampoMayusculas::area('nota_identificacion')
                    ->label('Rasgos y quién lo trajo')
                    ->placeholder('VARÓN, ~40 AÑOS, TATUAJE EN ANTEBRAZO DERECHO. LO TRAJO LA AMBULANCIA DE BOMBEROS.')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => (bool) $get('es_nn'))
                    ->columnSpanFull(),

                CampoMayusculas::make('primer_nombre')
                    ->label('Primer nombre')
                    ->required()
                    ->maxLength(60)
                    ->columnSpan(['lg' => 3]),

                CampoMayusculas::make('segundo_nombre')
                    ->label('Segundo nombre')
                    ->maxLength(60)
                    ->columnSpan(['lg' => 3]),

                CampoMayusculas::make('primer_apellido')
                    ->label('Primer apellido')
                    ->maxLength(60)
                    ->required(fn (Get $get): bool => ! $get('es_nn'))
                    ->columnSpan(['lg' => 3]),

                CampoMayusculas::make('segundo_apellido')
                    ->label('Segundo apellido')
                    ->maxLength(60)
                    ->columnSpan(['lg' => 3]),

                /*
                 * Nativo a propósito: el `<input type="date">` del
                 * navegador deja TECLEAR la fecha (dd/mm/aaaa, saltando
                 * solo de día a mes a año) y también abrir el calendario.
                 * El selector propio de Filament solo se maneja con clics,
                 * y navegar hasta 1958 mes por mes es justo lo lento.
                 */
                DatePicker::make('fecha_nacimiento')
                    ->label('Fecha de nacimiento')
                    ->native()
                    ->maxDate(now())
                    ->minDate(now()->subYears(130))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $set('edad', self::edadDe($state));

                        /*
                         * Si la fecha venía estimada por la edad y ahora
                         * alguien teclea la de verdad, deja de ser estimada.
                         */
                        if ($get('precision_fecha_nacimiento') === PrecisionFechaNacimiento::Estimada->value && $get('edad_estimada')) {
                            $set('precision_fecha_nacimiento', PrecisionFechaNacimiento::Exacta->value);
                            $set('edad_estimada', false);
                        }
                    })
                    ->hintIcon('heroicon-o-information-circle', tooltip: 'Se escribe o se elige del calendario. Define el rango de edad y, con él, el descuento de ley.')
                    ->columnSpan(['lg' => 3]),

                /*
                 * La edad no se guarda: se calcula de la fecha. Sirve para
                 * leer de un vistazo, y para el que no sabe su fecha:
                 * escribir «70» deja la fecha estimada al 1 de enero de
                 * hace setenta años, marcada como estimada — farmacia y
                 * laboratorio no calculan sobre ella, y el descuento de
                 * adulto mayor sí se concede.
                 */
                TextInput::make('edad')
                    ->label('Edad')
                    ->suffix('años')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(130)
                    ->dehydrated(false)
                    ->live(onBlur: true)
                    ->afterStateHydrated(function (TextInput $component, Get $get): void {
                        $component->state(self::edadDe($get('fecha_nacimiento')));
                    })
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        if (! is_numeric($state) || (int) $state < 0 || (int) $state > 130) {
                            return;
                        }

                        $edad = (int) $state;

                        if (self::edadDe($get('fecha_nacimiento')) === $edad) {
                            return;
                        }

                        $set('fecha_nacimiento', now()->subYears($edad)->startOfYear()->toDateString());
                        $set('precision_fecha_nacimiento', PrecisionFechaNacimiento::Estimada->value);
                        $set('edad_estimada', true);
                    })
                    ->helperText(fn (Get $get): ?string => $get('edad_estimada') ? 'Fecha estimada por la edad.' : null)
                    ->hintIcon('heroicon-o-information-circle', tooltip: 'Si no sabe la fecha, escribí la edad: la fecha queda estimada.')
                    ->columnSpan(['lg' => 2]),

                ToggleButtons::make('sexo_biologico')
                    ->label('Sexo biológico')
                    ->options([
                        SexoBiologico::Masculino->value     => 'Masculino',
                        SexoBiologico::Femenino->value      => 'Femenino',
                        SexoBiologico::Indeterminado->value => 'Indeterminado',
                        SexoBiologico::Desconocido->value   => 'Sin evaluar',
                    ])
                    ->default(SexoBiologico::Desconocido->value)
                    ->required()
                    ->grouped()
                    ->hintIcon(
                        'heroicon-o-information-circle',
                        tooltip: 'Dato CLÍNICO: define los rangos de referencia de laboratorio y el cálculo de dosis. No es el género.',
                    )
                    ->columnSpan(['lg' => 7]),

                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->placeholder('9999-9999')
                    ->maxLength(30)
                    ->columnSpan(['lg' => 4]),

                CampoMayusculas::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255)
                    ->columnSpan(['lg' => 8]),

                // Marca de lectura: la fecha salió de la edad. No se guarda.
                Hidden::make('edad_estimada')
                    ->default(false)
                    ->dehydrated(false),
            ]);
    }

    // ── 2 · Lo que se usa poco ────────────────────────────────────────

    private static function masDatos(): Section
    {
        return Section::make('Más datos')
            ->description('Opcional: apellido de casada, género, correo y procedencia.')
            ->icon('heroicon-o-ellipsis-horizontal-circle')
            ->collapsible()
            ->collapsed()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                CampoMayusculas::make('apellido_casada')
                    ->label('Apellido de casada')
                    ->maxLength(60)
                    ->hintIcon(
                        'heroicon-o-information-circle',
                        tooltip: 'Se guarda aparte y NO reemplaza a los de nacimiento: el DNI sigue diciendo los '
                            .'originales y la factura tiene que coincidir con el DNI.',
                    ),

                Select::make('genero')
                    ->label('Género')
                    ->options(fn (): array => collect(Genero::cases())
                        ->mapWithKeys(fn (Genero $g): array => [$g->value => $g->etiqueta()])
                        ->all())
                    ->placeholder('No registrado')
                    ->hintIcon('heroicon-o-information-circle', tooltip: 'Dato administrativo y de trato. Nunca se usa para nada clínico.'),

                Select::make('precision_fecha_nacimiento')
                    ->label('Confiabilidad de la fecha')
                    ->options(fn (): array => collect(PrecisionFechaNacimiento::cases())
                        ->mapWithKeys(fn (PrecisionFechaNacimiento $p): array => [$p->value => $p->etiqueta()])
                        ->all())
                    ->default(PrecisionFechaNacimiento::Exacta->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->hintIcon(
                        'heroicon-o-information-circle',
                        tooltip: 'Si es estimada, farmacia y laboratorio NO calculan sobre ella. El descuento de adulto '
                            .'mayor sí se concede: negarlo por una duda es una infracción.',
                    ),

                TextInput::make('telefono_alterno')
                    ->label('Teléfono alterno')
                    ->tel()
                    ->maxLength(30),

                TextInput::make('email')
                    ->label('Correo')
                    ->email()
                    ->maxLength(255),

                CampoMayusculas::make('nacionalidad')
                    ->label('Nacionalidad')
                    ->placeholder('HN')
                    ->maxLength(2),

                CampoMayusculas::make('departamento')
                    ->label('Departamento')
                    ->maxLength(60),

                CampoMayusculas::make('municipio')
                    ->label('Municipio')
                    ->maxLength(60),
            ]);
    }

    // ── 3 · Solo al editar ────────────────────────────────────────────

    private static function motivoDelCambio(): Section
    {
        return Section::make('¿Por qué se corrige?')
            ->description(
                'Queda en el historial junto a los datos. «Corrección de digitación» dice que el dato anterior '
                .'estaba MAL; «cambio de apellido por matrimonio», que era correcto y dejó de serlo. Eso decide si '
                .'una factura vieja se reimprime como está o se corrige.'
            )
            ->icon('heroicon-o-pencil-square')
            ->visibleOn('edit')
            ->columnSpanFull()
            ->schema([
                Select::make('motivo_cambio')
                    ->label('Motivo')
                    ->options([
                        'Corrección de digitación'                          => 'Corrección de digitación',
                        'Cambio de apellido por matrimonio'                 => 'Cambio de apellido por matrimonio',
                        'Identificación de un paciente que ingresó como NN' => 'Identificación de un paciente que ingresó como NN',
                        'Actualización de datos de contacto'                => 'Actualización de datos de contacto',
                        'Registro de defunción'                             => 'Registro de defunción',
                    ])
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->dehydrated(),
            ]);
    }

    private static function defuncion(): Section
    {
        return Section::make('Defunción')
            ->description(
                'La FECHA importa, no un simple «fallecido»: cierra la cuenta, alimenta el certificado y evita '
                .'que el sistema siga agendando citas.'
            )
            ->icon('heroicon-o-document-minus')
            ->visibleOn('edit')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull()
            ->schema([
                DatePicker::make('fecha_defuncion')
                    ->label('Fecha de defunción')
                    ->native()
                    ->maxDate(now())
                    ->afterOrEqual('fecha_nacimiento'),
            ]);
    }

    private static function registro(): Section
    {
        return Section::make('Información del registro')
            ->icon('heroicon-o-information-circle')
            ->visibleOn('edit')
            ->collapsible()
            ->collapsed()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('uuid')
                    ->label('Identificador interno')
                    ->copyable(),

                TextEntry::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i'),

                TextEntry::make('createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('Sistema'),
            ]);
    }

    // ── Ayudas ────────────────────────────────────────────────────────

    /**
     * El paciente que ya tiene ese documento, o null.
     *
     * Solo pregunta cuando el número está completo (13 dígitos un DNI,
     * 14 un RTN): con la mitad tecleada no hay nada que avisar. Si el
     * documento quedó en una persona que después se fusionó, devuelve la
     * sobreviviente — esa es la ficha que hay que abrir.
     */
    private static function yaRegistrado(Get $get): ?Persona
    {
        $tipo = $get('documento_tipo');
        $tipo = is_string($tipo) ? TipoIdentificador::tryFrom($tipo) : null;
        $valor = $get('documento_valor');

        if (! $tipo instanceof TipoIdentificador || ! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $normalizado = $tipo->normalizar($valor);
        $largo = $tipo->longitudExacta();

        if ($normalizado === '' || ($largo !== null && mb_strlen($normalizado) !== $largo)) {
            return null;
        }

        $persona = PersonaIdentificador::query()
            ->deNumero($tipo, $valor)
            ->with('persona')
            ->first()
            ?->getRelationValue('persona');

        return $persona instanceof Persona ? $persona->raiz() : null;
    }

    /**
     * La fecha de nacimiento —para confirmar que es la misma persona— y
     * el enlace a su ficha.
     */
    private static function detalleDe(?Persona $persona): HtmlString
    {
        if (! $persona instanceof Persona) {
            return new HtmlString('');
        }

        $nacimiento = $persona->fecha_nacimiento?->format('d/m/Y');

        return new HtmlString(
            e($nacimiento === null ? 'Sin fecha de nacimiento.' : "Nació el {$nacimiento}.")
            .' Abrí su ficha en vez de registrarlo de nuevo: dos expedientes de la misma persona terminan en una fusión. '
            .'<a href="'.e(PacienteResource::getUrl('view', ['record' => $persona])).'" '
            .'style="font-weight: 600; text-decoration: underline;">Abrir su ficha</a>'
        );
    }

    private static function edadDe(mixed $fecha): ?int
    {
        if (! is_string($fecha) || trim($fecha) === '') {
            return null;
        }

        try {
            $nacimiento = Carbon::parse($fecha);
        } catch (Throwable) {
            return null;
        }

        return $nacimiento->isFuture() ? null : (int) $nacimiento->diffInYears(now());
    }

    /**
     * El mismo número de pasaporte puede existir en dos países.
     */
    private static function pideElPais(mixed $tipo): bool
    {
        return in_array($tipo, [TipoIdentificador::Pasaporte->value, TipoIdentificador::CarnetResidencia->value], true);
    }

    private static function mascaraDe(mixed $tipo): ?string
    {
        return match ($tipo) {
            TipoIdentificador::Dni->value => '9999-9999-99999',
            TipoIdentificador::Rtn->value => '9999-9999-999999',
            default                       => null,
        };
    }

    private static function ejemploDe(mixed $tipo): string
    {
        return match ($tipo) {
            TipoIdentificador::Dni->value => '0801-1990-12345',
            TipoIdentificador::Rtn->value => '0801-1990-123456',
            default                       => 'Número',
        };
    }
}
