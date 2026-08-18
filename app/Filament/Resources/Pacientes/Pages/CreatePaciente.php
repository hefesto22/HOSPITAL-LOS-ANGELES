<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Coincidencia;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Models\Sede;
use App\Services\RegistradorDePacientes;
use App\Support\ContextoSede;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Alta de un paciente.
 *
 * ⚠️ NO usa el guardado por defecto de Filament, y esa es la razón de que
 * esta clase exista.
 *
 * Registrar un paciente son cuatro escrituras que van juntas —persona,
 * documentos, versión 1 del historial y expediente con su correlativo— y
 * antes de todas ellas corre la detección de duplicados. Nada de eso lo
 * sabe hacer un `create()` de formulario. Acá el formulario solo recoge
 * datos; quien decide es `RegistradorDePacientes` (§11).
 */
class CreatePaciente extends CreateRecord
{
    protected static string $resource = PacienteResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $sede = $this->sedeActual();

        $datos = new DatosDePaciente(
            primerNombre: (string) $data['primer_nombre'],
            primerApellido: self::texto($data, 'primer_apellido'),
            segundoNombre: self::texto($data, 'segundo_nombre'),
            segundoApellido: self::texto($data, 'segundo_apellido'),
            apellidoCasada: self::texto($data, 'apellido_casada'),
            sexoBiologico: SexoBiologico::from((string) ($data['sexo_biologico'] ?? SexoBiologico::Desconocido->value)),
            genero: self::texto($data, 'genero') === null ? null : Genero::from((string) $data['genero']),
            fechaNacimiento: self::fecha($data, 'fecha_nacimiento'),
            precisionFechaNacimiento: PrecisionFechaNacimiento::from(
                (string) ($data['precision_fecha_nacimiento'] ?? PrecisionFechaNacimiento::Exacta->value)
            ),
            esNn: (bool) ($data['es_nn'] ?? false),
            documentos: $this->documentos($data),
            notaIdentificacion: self::texto($data, 'nota_identificacion'),
            nacionalidad: self::texto($data, 'nacionalidad'),
            departamento: self::texto($data, 'departamento'),
            municipio: self::texto($data, 'municipio'),
            direccion: self::texto($data, 'direccion'),
            telefono: self::texto($data, 'telefono'),
            telefonoAlterno: self::texto($data, 'telefono_alterno'),
            email: self::texto($data, 'email'),
        );

        try {
            $expediente = app(RegistradorDePacientes::class)->registrar($datos, $sede);
        } catch (PosibleDuplicadoException $e) {
            /*
             * El duplicado NO se muestra como "error de validación". Es
             * información que admisión necesita para decidir: se listan
             * los candidatos con su fecha de nacimiento y el porqué de la
             * coincidencia, y se detiene el guardado.
             */
            Notification::make()
                ->danger()
                ->persistent()
                ->title('Este paciente ya parece estar registrado')
                ->body(
                    $e->coincidencias
                        ->map(fn (Coincidencia $c): string => '• '.$c->resumen())
                        ->implode("\n")
                    ."\n\nBuscalo en el listado y abrí su expediente en vez de crear otro."
                )
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->success()
            ->title('Paciente registrado')
            ->body("Expediente {$expediente->numero}")
            ->send();

        return $expediente->persona;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, DocumentoDeIdentidad>
     */
    private function documentos(array $data): array
    {
        $tipo = self::texto($data, 'documento_tipo');
        $valor = self::texto($data, 'documento_valor');

        if ($tipo === null || $valor === null) {
            return [];
        }

        try {
            return [new DocumentoDeIdentidad(
                tipo: TipoIdentificador::from($tipo),
                valor: $valor,
                paisEmision: self::texto($data, 'documento_pais'),
                esPrincipal: true,
            )];
        } catch (ValueObjectInvalidoException $e) {
            Notification::make()
                ->danger()
                ->title('El documento no es válido')
                ->body($e->getMessage())
                ->send();

            throw new Halt;
        }
    }

    /**
     * La sede en la que se abre el expediente.
     *
     * Explícita y verificada: dejar que un null silencioso decida es cómo
     * se termina con el expediente de un paciente colgando de la sede
     * equivocada.
     */
    private function sedeActual(): Sede
    {
        $sedeId = ContextoSede::actualId() ?? Auth::user()?->getAttribute('sede_id');

        $sede = is_int($sedeId) ? Sede::query()->find($sedeId) : null;

        if (! $sede instanceof Sede) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title('No hay sede seleccionada')
                ->body('Tu usuario no tiene sede asignada, así que no se sabe dónde abrir el expediente.')
                ->send();

            throw new Halt;
        }

        return $sede;
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

    /**
     * @param array<string, mixed> $data
     */
    private static function fecha(array $data, string $campo): ?CarbonInterface
    {
        $valor = $data[$campo] ?? null;

        if ($valor instanceof CarbonInterface) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
