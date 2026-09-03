<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Schemas;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Services\CopiadorDeBaseDePrecios;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Alta de un pagador — patrón §10.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA SECCIÓN QUE JUSTIFICA LA PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Descuento de ley» no es un campo más: es una decisión legal que el
 * sistema se niega a tomar solo. Las tres opciones se muestran con su
 * explicación completa —por eso es `Radio` y no `Select`, que las
 * esconde detrás de un clic— y el fundamento es obligatorio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ COLUMNA PRINCIPAL Y BARRA LATERAL, Y NO DOS COLUMNAS SUELTAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Con secciones sueltas en una grilla de dos, Filament las va acomodando
 * por orden de aparición: una sección corta al lado de una larguísima
 * deja media pantalla en blanco, y el ojo tiene que saltar de izquierda
 * a derecha para seguir un solo trámite.
 *
 * Acá hay dos columnas con roles distintos. En la ANCHA va lo que se
 * lee y se decide —quién es, cuánto cubre, sobre qué monto corre el
 * descuento de ley—. En la ANGOSTA va lo que se configura una vez y no
 * se vuelve a mirar: de qué base hereda, si exige autorización, desde
 * cuándo rige. Debajo de `lg` las dos se apilan en una sola columna,
 * que es como se ve en una tablet en admisión.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE:
 * `$get`, `$set`, `$state`, `$record`. Un parámetro con otro nombre
 * recibe un objeto vacío del contenedor y falla EN SILENCIO.
 */
final class ConvenioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    self::identificacion(),
                    self::cobertura(),
                    self::descuentoDeLey(),
                    self::contacto(),
                ])->columnSpan(2),

                Group::make([
                    self::inicializacion(),
                    self::condiciones(),
                    self::vigencia(),
                ])->columnSpan(1),
            ]);
    }

    private static function identificacion(): Section
    {
        return Section::make('Identificación')
            ->description('Cómo se llama este pagador y qué clase de pagador es.')
            ->columns(3)
            ->schema([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(20)
                    /*
                     * No se edita: el código viaja en cada cuenta y en
                     * cada factura ya emitida. Cambiarlo dejaría facturas
                     * apuntando a un pagador que ya no se llama así.
                     */
                    ->disabledOn('edit')
                    ->helperText('CONTADO, PALIG, IHSS, MILITAR.')
                    ->rule('regex:/^[A-Z0-9\-]{3,20}$/')
                    ->validationMessages([
                        'regex' => 'Solo letras mayúsculas, números y guiones, de 3 a 20 caracteres.',
                    ]),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(120)
                    ->helperText('El nombre completo, como aparece en el contrato.')
                    ->columnSpan(2),

                Select::make('tipo')
                    ->label('Tipo de pagador')
                    ->options(fn (): array => collect(TipoConvenio::cases())
                        ->mapWithKeys(fn (TipoConvenio $tipo): array => [$tipo->value => $tipo->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->live()
                    /*
                     * Al contado no se fía: la base lo rechaza con un
                     * CHECK, así que el formulario limpia el campo en vez
                     * de dejar que el error salte al guardar.
                     */
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $tipo = self::tipoDe($state);

                        if ($tipo instanceof TipoConvenio && ! $tipo->admiteCredito()) {
                            $set('dias_credito', null);
                        }

                        if ($tipo instanceof TipoConvenio && ! $tipo->pagaUnTercero()) {
                            $set('cobertura_fraccion', 0);
                            $set('tope_por_evento', null);
                            $set('cubre_por_defecto', false);
                        }
                    })
                    ->helperText(fn (mixed $state): ?string => self::tipoDe($state)?->explicacion())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * HEREDAR LA BASE DE PRECIOS EN EL MISMO ACTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Firmar con una aseguradora y cargarle sus ciento treinta precios
     * son el mismo trabajo para quien lo hace. Separarlos deja el hueco
     * de siempre: el convenio existe, nadie cargó los precios, y el
     * primer paciente con esa póliza se atiende contra un «este ítem no
     * tiene precio para este pagador».
     *
     * Solo al CREAR. En la edición no aparece: copiar sobre un pagador
     * que ya opera es otra cosa —y bastante más peligrosa—, y para eso
     * está la acción de la pantalla de bases de precios, que además
     * respeta lo que ya estaba cargado.
     *
     * El selector solo ofrece bases que TIENEN precios; ver
     * `CopiadorDeBaseDePrecios::opcionesDeOrigen()`.
     */
    private static function inicializacion(): Section
    {
        return Section::make('Base de precios')
            ->description('Opcional. Copia el catálogo completo desde otra base como punto de partida.')
            ->hiddenOn('edit')
            ->schema([
                Select::make('heredar_de')
                    ->label('Heredar desde')
                    ->native(false)
                    ->live()
                    ->default(CopiadorDeBaseDePrecios::ORIGEN_VACIO)
                    ->selectablePlaceholder(false)
                    ->options(fn (): array => app(CopiadorDeBaseDePrecios::class)
                        ->opcionesDeOrigen(conVacio: true))
                    ->helperText(
                        'El precio de lista es el del hospital, sin pagador de por medio: '
                        .'es el mismo que paga el paciente particular.'
                    ),

                TextInput::make('porcentaje_heredado')
                    ->label('¿Qué porcentaje del origen?')
                    ->numeric()
                    ->default(100)
                    ->minValue(1)
                    ->maxValue(500)
                    ->suffix('%')
                    ->required(fn (Get $get): bool => ! app(CopiadorDeBaseDePrecios::class)
                        ->noCopiaNada($get('heredar_de')))
                    ->visible(fn (Get $get): bool => ! app(CopiadorDeBaseDePrecios::class)
                        ->noCopiaNada($get('heredar_de')))
                    ->helperText('100 = el mismo precio. 85 = un 15 % menos. 120 = un 20 % más.'),
            ]);
    }

    /**
     * Cuánto pone el pagador de cada cuenta (§8.6.3).
     *
     * ⚠️ Nace en CERO y no en el 80 % típico del mercado: un porcentaje
     * puesto por defecto es el hospital regalando plata sin que nadie lo
     * haya decidido. Con cero, el paciente aparece debiendo todo — que
     * es visible y se corrige acá mismo.
     */
    private static function cobertura(): Section
    {
        return Section::make('Cobertura')
            ->description('Qué parte de cada cargo paga este pagador y hasta cuánto.')
            ->columns(3)
            ->visible(fn (Get $get): bool => self::tipoDe($get('tipo'))?->pagaUnTercero() ?? false)
            ->schema([
                TextInput::make('cobertura_fraccion')
                    ->label('Cobertura')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(1)
                    ->step('0.0001')
                    ->helperText('0.80 es el 80 %. Cero = el paciente paga todo.'),

                TextInput::make('tope_por_evento')
                    ->label('Tope por evento')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('L')
                    ->helperText('Vacío = sin tope. El excedente lo paga el paciente.'),

                Toggle::make('cubre_por_defecto')
                    ->label('Cubre lo que no tiene precio propio')
                    ->helperText('Apagado: solo cubre lo que está declarado.'),
            ]);
    }

    private static function descuentoDeLey(): Section
    {
        return Section::make('Descuento de ley — Art. 30 del Decreto 199-2006')
            ->description(BaseDelDescuentoLegal::advertencia())
            ->schema([
                /*
                 * `Radio` y no `Select`: las tres lecturas tienen que
                 * estar a la vista con su explicación cuando se elige.
                 * Esconderlas detrás de un desplegable convierte una
                 * decisión legal en un campo que se llena de apuro.
                 */
                Radio::make('base_descuento_legal')
                    ->label('Sobre qué monto se calcula el descuento')
                    ->options(fn (): array => collect(BaseDelDescuentoLegal::cases())
                        ->mapWithKeys(fn (BaseDelDescuentoLegal $base): array => [
                            $base->value => $base->etiqueta(),
                        ])
                        ->all())
                    ->descriptions(fn (): array => collect(BaseDelDescuentoLegal::cases())
                        ->mapWithKeys(fn (BaseDelDescuentoLegal $base): array => [
                            $base->value => $base->explicacion(),
                        ])
                        ->all())
                    ->required(),

                Textarea::make('fundamento_descuento')
                    ->label('Con qué criterio se decidió')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText(
                        'Quedan las dos cosas: la opción elegida y por qué. Si hay dictamen del '
                        .'abogado o cláusula del contrato que lo respalde, citalo acá.'
                    ),
            ]);
    }

    private static function condiciones(): Section
    {
        return Section::make('Condiciones')
            ->schema([
                Toggle::make('requiere_autorizacion')
                    ->label('Exige autorización previa')
                    ->helperText(
                        'Sin autorización, el hospital atiende y después descubre que el pagador '
                        .'no cubría. La factura queda en el aire y el que reclama es el paciente.'
                    ),

                TextInput::make('dias_credito')
                    ->label('Días de crédito')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->suffix('días')
                    /*
                     * El contado no admite crédito ni siquiera en cero:
                     * cero días sería "se paga hoy", que es lo mismo que
                     * nulo y agrega una segunda forma de decir lo mismo.
                     */
                    ->visible(fn (Get $get): bool => self::tipoDe($get('tipo'))?->admiteCredito() ?? false)
                    ->helperText('Vacío = se cobra al momento.'),
            ]);
    }

    /**
     * El estado del `Select` llega como el `value` del enum, pero el
     * mismo campo se lee también desde el registro al editar. Este
     * método acepta las dos formas en vez de tipar `?string` y reventar
     * el día que Filament cambie de opinión sobre cuál manda.
     */
    private static function tipoDe(mixed $valor): ?TipoConvenio
    {
        if ($valor instanceof TipoConvenio) {
            return $valor;
        }

        return is_string($valor) ? TipoConvenio::tryFrom($valor) : null;
    }

    private static function contacto(): Section
    {
        return Section::make('Contacto y facturación')
            ->description('Opcional, pero es lo que se busca cuando una factura se atrasa.')
            ->columns(2)
            ->collapsed()
            ->schema([
                RTNField::make(),

                TextInput::make('contacto')
                    ->label('Persona de contacto')
                    ->maxLength(120)
                    ->helperText('Se guarda tal cual se escribe: un nombre en mayúsculas se lee como un grito.'),

                TelefonoHondurasField::make('telefono', 'Teléfono'),

                TextInput::make('correo')
                    ->label('Correo')
                    ->email()
                    ->maxLength(120),

                /*
                 * ─────────────────────────────────────────────────────
                 * CÓMO QUIERE EL PAPEL ESTE PAGADOR
                 * ─────────────────────────────────────────────────────
                 *
                 * Una aseguradora que adjudica renglón por renglón
                 * necesita ver los renglones; al paciente de contado le
                 * sirve más el papel corto. Es preferencia del pagador,
                 * así que vive acá y no en el código: el convenio que se
                 * firme el mes que viene llega con la suya sin tocar
                 * nada (§1.1).
                 *
                 * Es solo el valor por defecto. Quien factura la ve ya
                 * marcada en el modal y puede cambiarla para esa factura
                 * — y lo que decida ahí queda congelado en el papel.
                 */
                Toggle::make('desglosa_paquetes')
                    ->label('Detallar las cirugías presupuestadas en la factura')
                    ->columnSpanFull()
                    ->helperText(
                        'Apagado: la cirugía sale como un solo renglón con su precio de paquete. '
                        .'Encendido: sale en los renglones que se prestaron —sala, habitación, '
                        .'medicamentos— con el monto repartido entre ellos. El total es el mismo.'
                    ),
            ]);
    }

    private static function vigencia(): Section
    {
        return Section::make('Vigencia')
            ->columns(2)
            ->schema([
                DatePicker::make('vigencia_desde')
                    ->label('Desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                DatePicker::make('vigencia_hasta')
                    ->label('Hasta')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterOrEqual('vigencia_desde')
                    ->placeholder('Sin fecha de fin'),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('Un convenio terminado se cierra con la fecha de arriba, no se borra.'),
            ]);
    }
}
