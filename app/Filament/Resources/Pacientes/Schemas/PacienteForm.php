<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Schemas;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoIdentificador;
use App\Filament\Schemas\Components\CampoMayusculas;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Alta y edición de un paciente — patrón §10.
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
            Tabs::make('paciente')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identidad(),
                    self::documento(),
                    self::contacto(),
                    self::estado(),
                ]),
        ]);
    }

    private static function identidad(): Tab
    {
        return Tab::make('Identidad')
            ->icon('heroicon-o-identification')
            ->schema([
                Section::make('Paciente sin identificar')
                    ->description(
                        'Marcar esto permite registrar sin apellido y sin documento. El paciente '
                        .'queda en la bandeja de "identificar antes del alta".'
                    )
                    ->icon('heroicon-o-question-mark-circle')
                    ->schema([
                        Toggle::make('es_nn')
                            ->label('Ingresó sin identificar (NN)')
                            ->live()
                            ->helperText('En emergencia, el sistema no debe frenar. Todo lo demás se completa después.'),

                        Textarea::make('nota_identificacion')
                            ->label('Rasgos y quién lo trajo')
                            ->placeholder('Varón, ~40 años, tatuaje en antebrazo derecho. Lo trajo la ambulancia de bomberos.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => (bool) $get('es_nn'))
                            ->helperText('Texto libre: se guarda tal como se escribe, sin pasar a mayúsculas.'),
                    ])
                    ->columnSpanFull(),

                CampoMayusculas::make('primer_nombre')
                    ->label('Primer nombre')
                    ->required()
                    ->maxLength(60),

                CampoMayusculas::make('segundo_nombre')
                    ->label('Segundo nombre')
                    ->maxLength(60),

                CampoMayusculas::make('primer_apellido')
                    ->label('Primer apellido')
                    ->maxLength(60)
                    ->required(fn (callable $get): bool => ! $get('es_nn'))
                    ->helperText('Obligatorio salvo que el paciente ingrese sin identificar.'),

                CampoMayusculas::make('segundo_apellido')
                    ->label('Segundo apellido')
                    ->maxLength(60),

                CampoMayusculas::make('apellido_casada')
                    ->label('Apellido de casada')
                    ->maxLength(60)
                    ->helperText(
                        'Se guarda aparte y NO reemplaza a los de nacimiento: el DNI sigue diciendo '
                        .'los originales y la factura tiene que coincidir con el DNI.'
                    ),

                Select::make('sexo_biologico')
                    ->label('Sexo biológico')
                    ->options(fn (): array => collect(SexoBiologico::cases())
                        ->mapWithKeys(fn (SexoBiologico $s): array => [$s->value => $s->etiqueta()])
                        ->all())
                    ->default(SexoBiologico::Desconocido->value)
                    ->required()
                    ->native(false)
                    ->helperText(
                        'Dato CLÍNICO: define los rangos de referencia de laboratorio y el cálculo '
                        .'de dosis. No es el género.'
                    ),

                Select::make('genero')
                    ->label('Género')
                    ->options(fn (): array => collect(Genero::cases())
                        ->mapWithKeys(fn (Genero $g): array => [$g->value => $g->etiqueta()])
                        ->all())
                    ->native(false)
                    ->placeholder('No registrado')
                    ->helperText('Dato administrativo y de trato. Nunca se usa para nada clínico.'),

                DatePicker::make('fecha_nacimiento')
                    ->label('Fecha de nacimiento')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->helperText('Define el rango de edad y, con él, el descuento legal.'),

                Select::make('precision_fecha_nacimiento')
                    ->label('Confiabilidad de la fecha')
                    ->options(fn (): array => collect(PrecisionFechaNacimiento::cases())
                        ->mapWithKeys(fn (PrecisionFechaNacimiento $p): array => [$p->value => $p->etiqueta()])
                        ->all())
                    ->default(PrecisionFechaNacimiento::Exacta->value)
                    ->required()
                    ->native(false)
                    ->helperText(
                        'Si es estimada, farmacia y laboratorio NO calculan sobre ella. El descuento '
                        .'de adulto mayor sí se concede: negarlo por una duda es una infracción.'
                    ),
            ])
            ->columns(2);
    }

    private static function documento(): Tab
    {
        return Tab::make('Documento')
            ->icon('heroicon-o-rectangle-stack')
            ->visibleOn('create')
            ->schema([
                Section::make('Documento de identidad')
                    ->description(
                        'Opcional. El documento NO es la identidad: un NN, un recién nacido y quien '
                        .'olvidó la cédula se registran igual. Si se digita, el sistema busca primero '
                        .'si ese número ya existe.'
                    )
                    ->schema([
                        Select::make('documento_tipo')
                            ->label('Tipo')
                            ->options(fn (): array => collect(TipoIdentificador::cases())
                                ->mapWithKeys(fn (TipoIdentificador $t): array => [$t->value => $t->etiqueta()])
                                ->all())
                            ->default(TipoIdentificador::Dni->value)
                            ->native(false)
                            ->live(),

                        TextInput::make('documento_valor')
                            ->label('Número')
                            ->placeholder('0801-1990-12345')
                            ->maxLength(40)
                            ->helperText('Se guarda solo con dígitos: da igual si se escribe con guiones o sin ellos.'),

                        TextInput::make('documento_pais')
                            ->label('País emisor')
                            ->placeholder('HN')
                            ->maxLength(2)
                            ->visible(fn (callable $get): bool => in_array(
                                $get('documento_tipo'),
                                [TipoIdentificador::Pasaporte->value, TipoIdentificador::CarnetResidencia->value],
                                true,
                            ))
                            ->helperText('El mismo número de pasaporte puede existir en dos países.'),
                    ])
                    ->columns(3),
            ]);
    }

    private static function contacto(): Tab
    {
        return Tab::make('Contacto')
            ->icon('heroicon-o-map-pin')
            ->schema([
                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(30),

                TextInput::make('telefono_alterno')
                    ->label('Teléfono alterno')
                    ->tel()
                    ->maxLength(30),

                TextInput::make('email')
                    ->label('Correo')
                    ->email()
                    ->maxLength(255)
                    ->helperText('No se pasa a mayúsculas: la parte local es sensible del lado del servidor.'),

                CampoMayusculas::make('departamento')
                    ->label('Departamento')
                    ->maxLength(60),

                CampoMayusculas::make('municipio')
                    ->label('Municipio')
                    ->maxLength(60),

                CampoMayusculas::make('nacionalidad')
                    ->label('Nacionalidad')
                    ->placeholder('HN')
                    ->maxLength(2),

                CampoMayusculas::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->visibleOn('edit')
            ->schema([
                Section::make('Motivo del cambio')
                    ->description(
                        'Queda en el historial junto a los datos. "Corrección de digitación" y '
                        .'"cambio de apellido por matrimonio" se ven igual en los datos y significan '
                        .'cosas distintas: uno dice que el dato anterior estaba MAL, el otro que era '
                        .'correcto y dejó de serlo. Eso decide si una factura vieja se reimprime como '
                        .'está o se corrige.'
                    )
                    ->icon('heroicon-o-pencil-square')
                    ->schema([
                        Select::make('motivo_cambio')
                            ->label('Por qué se corrige')
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
                            ->dehydrated()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Defunción')
                    ->description(
                        'La FECHA importa, no un simple "fallecido": cierra la cuenta, alimenta el '
                        .'certificado y evita que el sistema siga agendando citas.'
                    )
                    ->schema([
                        DatePicker::make('fecha_defuncion')
                            ->label('Fecha de defunción')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->afterOrEqual('fecha_nacimiento'),
                    ]),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
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
                    ])
                    ->columns(3),
            ]);
    }
}
