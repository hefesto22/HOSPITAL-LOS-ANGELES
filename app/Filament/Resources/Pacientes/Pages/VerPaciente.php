<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Coincidencia;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Services\AgregadorDeDocumentos;
use App\Support\BitacoraDeLectura;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

/**
 * La ficha del paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ABRIR ESTA PANTALLA QUEDA REGISTRADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El §9 obliga a llevar bitácora de LECTURA del expediente: quién lo
 * abrió, cuándo, desde dónde. Se hace acá, en el `mount`, y no en un
 * middleware ni "más adelante", porque una bitácora no se puede
 * reconstruir hacia atrás: el mes que se use la pantalla sin registrar es
 * un mes que queda sin auditoría, para siempre.
 *
 * `BitacoraDeLectura::registrar()` nunca lanza excepción — si el registro
 * falla, el médico igual abre el expediente y el fallo se reporta. El
 * mecanismo que existe para proteger al paciente no puede ser el que le
 * impide ser atendido.
 */
class VerPaciente extends ViewRecord
{
    protected static string $resource = PacienteResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $persona = $this->getRecord();

        if (! $persona instanceof Persona) {
            return;
        }

        BitacoraDeLectura::registrar(
            recursoTipo: Persona::class,
            recursoId: $persona->getKey(),
            pacienteId: $persona->getKey(),
            accion: AccionDeLectura::Ver,
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->accionAgregarDocumento(),
            $this->accionVerificarDocumento(),
            EditAction::make()->label('Editar datos'),
        ];
    }

    /**
     * Agregar un documento después del alta.
     *
     * Es rutina, no excepción: el paciente que llegó sin cédula y la trae
     * al día siguiente, el que pide factura con RTN, el NN de anoche que
     * hoy resultó ser alguien.
     */
    private function accionAgregarDocumento(): Action
    {
        return Action::make('agregarDocumento')
            ->label('Agregar documento')
            ->icon('heroicon-o-plus-circle')
            ->modalHeading('Agregar documento de identidad')
            ->modalDescription(
                'Si el número ya pertenece a otra persona, el sistema no lo agrega y muestra a '
                .'quién. Para el caso real en que de verdad son dos personas distintas, marcá el '
                .'conflicto y explicá por qué.'
            )
            ->schema([
                Select::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoIdentificador::cases())
                        ->mapWithKeys(fn (TipoIdentificador $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->default(TipoIdentificador::Dni->value)
                    ->native(false)
                    ->required()
                    ->live(),

                TextInput::make('valor')
                    ->label('Número')
                    ->required()
                    ->maxLength(40)
                    ->helperText('Se guarda solo con dígitos cuando el tipo lo exige.'),

                TextInput::make('pais')
                    ->label('País emisor')
                    ->placeholder('HN')
                    ->maxLength(2)
                    ->visible(fn (callable $get): bool => in_array(
                        $get('tipo'),
                        [TipoIdentificador::Pasaporte->value, TipoIdentificador::CarnetResidencia->value],
                        true,
                    )),

                Toggle::make('es_principal')
                    ->label('Es el documento principal')
                    ->helperText('Con el que se factura. Solo puede haber uno: el anterior deja de serlo.'),

                Toggle::make('verificado')
                    ->label('Tuve el documento físico en la mano')
                    ->helperText('Un DNI dictado por teléfono y uno fotocopiado no valen lo mismo para facturar ni para reclamar a una aseguradora.'),

                Toggle::make('en_conflicto')
                    ->label('El número ya está registrado a otra persona')
                    ->live()
                    ->helperText('Marcalo solo si verificaste que de verdad son dos personas distintas.'),

                TextInput::make('conflicto_nota')
                    ->label('Explicá el conflicto')
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => (bool) $get('en_conflicto'))
                    ->required(fn (callable $get): bool => (bool) $get('en_conflicto'))
                    ->helperText('Mínimo 10 caracteres. Sale en la bandeja de revisión.'),
            ])
            ->action(function (array $data): void {
                $persona = $this->getRecord();

                if (! $persona instanceof Persona) {
                    throw new Halt;
                }

                try {
                    $documento = new DocumentoDeIdentidad(
                        tipo: TipoIdentificador::from((string) $data['tipo']),
                        valor: (string) $data['valor'],
                        paisEmision: self::texto($data, 'pais'),
                        esPrincipal: (bool) ($data['es_principal'] ?? false),
                    );
                } catch (ValueObjectInvalidoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('El documento no es válido')
                        ->body($e->getMessage())
                        ->send();

                    throw new Halt;
                }

                $agregador = app(AgregadorDeDocumentos::class);
                $verificado = (bool) ($data['verificado'] ?? false);

                try {
                    if ((bool) ($data['en_conflicto'] ?? false)) {
                        $agregador->agregarPeseAlConflicto(
                            $persona,
                            $documento,
                            (string) ($data['conflicto_nota'] ?? ''),
                            $verificado,
                        );
                    } else {
                        $agregador->agregar($persona, $documento, $verificado);
                    }
                } catch (PosibleDuplicadoException $e) {
                    Notification::make()
                        ->danger()
                        ->persistent()
                        ->title('Ese número ya pertenece a otra persona')
                        ->body(
                            $e->coincidencias
                                ->map(fn (Coincidencia $c): string => $c->resumen())
                                ->implode(' | ')
                            .'  ·  Si de verdad son dos personas distintas, marcá el conflicto y explicá por qué.'
                        )
                        ->send();

                    throw new Halt;
                }

                Notification::make()->success()->title('Documento agregado')->send();
            });
    }

    /**
     * Marcar que alguien tuvo el documento físico en la mano.
     */
    private function accionVerificarDocumento(): Action
    {
        return Action::make('verificarDocumento')
            ->label('Verificar documento')
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            ->visible(fn (): bool => $this->documentosSinVerificar() !== [])
            ->modalHeading('Confirmar que se vio el documento físico')
            ->modalDescription('No se puede deshacer: haberlo visto es un hecho, y borrarlo sería reescribir lo que pasó.')
            ->schema([
                Select::make('identificador')
                    ->label('Documento')
                    ->options(fn (): array => $this->documentosSinVerificar())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $identificador = PersonaIdentificador::query()->find($data['identificador']);

                if (! $identificador instanceof PersonaIdentificador) {
                    throw new Halt;
                }

                app(AgregadorDeDocumentos::class)->verificar($identificador);

                Notification::make()->success()->title('Documento verificado')->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private function documentosSinVerificar(): array
    {
        $persona = $this->getRecord();

        if (! $persona instanceof Persona) {
            return [];
        }

        return $persona->identificadores
            ->whereNull('verificado_en')
            ->mapWithKeys(fn (PersonaIdentificador $i): array => [
                $i->getKey() => $i->tipo->etiqueta().' '.$i->formateado(),
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function texto(array $data, string $campo): ?string
    {
        $valor = $data[$campo] ?? null;

        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }
}
