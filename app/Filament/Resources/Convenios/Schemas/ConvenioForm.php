<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Schemas;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE:
 * `$get`, `$set`, `$state`, `$record`. Un parámetro con otro nombre
 * recibe un objeto vacío del contenedor y falla EN SILENCIO.
 */
final class ConvenioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::identificacion(),
            self::descuentoDeLey(),
            self::condiciones(),
            self::contacto(),
            self::vigencia(),
        ]);
    }

    private static function identificacion(): Section
    {
        return Section::make('Identificación')
            ->description('Cómo se llama este pagador y qué clase de pagador es.')
            ->columns(2)
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
                    ->helperText('Corto, en mayúsculas y sin espacios: CONTADO, IHSS, MILITAR.')
                    ->rule('regex:/^[A-Z0-9\-]{3,20}$/')
                    ->validationMessages([
                        'regex' => 'Solo letras mayúsculas, números y guiones, de 3 a 20 caracteres.',
                    ]),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(120),

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
                    })
                    ->helperText(fn (mixed $state): ?string => self::tipoDe($state)?->explicacion())
                    ->columnSpanFull(),
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
                    ->rows(4)
                    ->helperText(
                        'Quedan las dos cosas: la opción elegida y por qué. Si hay dictamen del '
                        .'abogado o cláusula del contrato que lo respalde, citalo acá.'
                    ),
            ]);
    }

    private static function condiciones(): Section
    {
        return Section::make('Condiciones')
            ->columns(2)
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
                    ->helperText('Vacío = sigue vigente. Un convenio terminado se cierra acá, no se borra.'),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
