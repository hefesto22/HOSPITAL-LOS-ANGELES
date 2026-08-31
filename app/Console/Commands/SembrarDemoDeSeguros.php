<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\Genero;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\RegistradorDePacientes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Console\Command;

/**
 * Tres pacientes con seguro, uno por cada rango de edad.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ SIRVEN LOS TRES JUNTOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El descuento de ley y la cobertura del seguro se cruzan, y ese cruce
 * es donde se cometen los errores caros. Con los tres al lado, en el
 * mismo convenio y con el mismo servicio cargado, se ve de un vistazo:
 *
 *   · 30 años → sin descuento de ley. El seguro cubre su parte y el
 *     paciente el resto, limpio.
 *   · 65 años → tercera edad. Acá aparece la pregunta de sobre qué monto
 *     cae el descuento, que es la que decide `base_descuento_legal`.
 *   · 85 años → cuarta edad, con el porcentaje más alto. Es el caso donde
 *     más plata absorbe el hospital y donde una regla mal puesta se nota.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO CIERRA NADA
 * ─────────────────────────────────────────────────────────────────────
 *
 * ⚠️ A diferencia de `sihla:demo-descuentos`, este comando NO toca las
 * cuentas que ya están abiertas: son las de otras pruebas y cerrarlas
 * borraría el escenario que alguien estaba armando. Si uno de estos tres
 * ya tiene cuenta abierta, se reporta la que tiene y se sigue.
 *
 * Es idempotente: correrlo dos veces no duplica pacientes ni cuentas.
 */
class SembrarDemoDeSeguros extends Command
{
    protected $signature = 'sihla:demo-seguros {convenio=PALIG : El código del pagador — PALIG, MILITAR, IHSS}';

    protected $description = 'Deja tres pacientes con seguro —30, 65 y 85 años— con su cuenta abierta.';

    /**
     * Los tres, uno por rango de edad.
     *
     * Nombres distintos de los de `sihla:demo-descuentos` a propósito:
     * los dos escenarios tienen que poder convivir sin que uno le cambie
     * la fecha de nacimiento al paciente del otro.
     *
     * @var list<array{nombre: string, segundo: ?string, apellido: string, apellido2: string, sexo: SexoBiologico, edad: int}>
     */
    private const PACIENTES = [
        [
            'nombre'    => 'CARLOS',
            'segundo'   => 'ALBERTO',
            'apellido'  => 'VELASQUEZ',
            'apellido2' => 'MEZA',
            'sexo'      => SexoBiologico::Masculino,
            'edad'      => 30,
        ],
        [
            'nombre'    => 'MARTA',
            'segundo'   => 'ELENA',
            'apellido'  => 'SANDOVAL',
            'apellido2' => 'RIVERA',
            'sexo'      => SexoBiologico::Femenino,
            'edad'      => 65,
        ],
        [
            'nombre'    => 'PEDRO',
            'segundo'   => 'JOAQUIN',
            'apellido'  => 'LAINEZ',
            'apellido2' => 'CASTRO',
            'sexo'      => SexoBiologico::Masculino,
            'edad'      => 85,
        ],
    ];

    public function handle(
        RegistradorDePacientes $registrador,
        AbridorDeEncuentro $abridor,
    ): int {
        $sede = Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            $this->error('No hay ninguna sede registrada. Corré los seeders base primero.');

            return self::FAILURE;
        }

        $codigo = mb_strtoupper(trim((string) $this->argument('convenio')));

        $convenio = Convenio::query()->where('codigo', $codigo)->first();

        if (! $convenio instanceof Convenio) {
            $this->error("No existe ningún pagador con código {$codigo}.");
            $this->line('Los que hay: '.Convenio::query()->orderBy('codigo')->pluck('codigo')->implode(', '));

            return self::FAILURE;
        }

        if (! $convenio->tipo->pagaUnTercero()) {
            $this->warn(
                "⚠️  {$convenio->nombre} no es un pagador tercero: las cuentas van a salir con el "
                .'paciente pagando todo. Para probar cobertura elegí un seguro.'
            );
        }

        $filas = [];

        foreach (self::PACIENTES as $quien) {
            $nacimiento = now()->subYears($quien['edad'])->subDays(3);

            $persona = $this->personaExistente($quien);

            if ($persona instanceof Persona) {
                /*
                 * Al que ya está solo se le corrige la fecha de
                 * nacimiento: crear un segundo Carlos sembraría justo el
                 * duplicado que el módulo de MPI existe para evitar.
                 */
                $persona->forceFill(['fecha_nacimiento' => $nacimiento->toDateString()])->save();
                $expediente = $registrador->abrirExpedienteEn($persona, $sede);
            } else {
                $expediente = $this->registrar($registrador, $quien, $nacimiento, $sede);
                $persona = $expediente->persona;
            }

            if (! $persona instanceof Persona) {
                $this->error('El expediente quedó sin persona. Eso no debería poder pasar.');

                return self::FAILURE;
            }

            $cuenta = $this->cuentaAbiertaDe($persona) ?? $abridor->abrir(
                persona: $persona,
                expediente: $expediente,
                tipo: TipoEncuentro::Ambulatorio,
                convenio: $convenio,
                sede: $sede,
                motivo: 'Prueba de cobertura de seguro',
            );

            $rango = RangoEdad::paraEdad($quien['edad']);

            $filas[] = [
                $persona->primer_nombre.' '.$quien['apellido'],
                $quien['edad'].' años',
                $rango->etiqueta(),
                $cuenta->convenio->codigo,
                $cuenta->numero,
            ];
        }

        $this->newLine();
        $this->table(['Paciente', 'Edad', 'Rango', 'Pagador', 'Cuenta'], $filas);

        $this->newLine();
        $this->line($this->comoCubre($convenio));
        $this->info('Las tres cuentas están abiertas y vacías, esperando el primer cargo.');

        return self::SUCCESS;
    }

    /**
     * Cómo cubre este pagador, dicho en una línea.
     *
     * Va impreso porque es el dato que hay que tener a la vista mientras
     * se prueba: un porcentaje y un monto tope se comportan distinto y
     * confundirlos es leer mal cada número que salga después.
     */
    private function comoCubre(Convenio $convenio): string
    {
        if (! $convenio->tipo->pagaUnTercero()) {
            return $convenio->nombre.' no cubre nada: el paciente paga todo.';
        }

        $fraccion = $convenio->cobertura_fraccion;

        $porcentaje = is_numeric($fraccion)
            ? rtrim(rtrim(number_format((float) $fraccion * 100, 2, '.', ''), '0'), '.').' %'
            : '0 %';

        $tope = $convenio->tope_por_evento;

        return $convenio->nombre.' cubre el '.$porcentaje
            .(is_numeric($tope) ? ', con tope de L '.number_format((float) $tope, 2).' por evento.' : ', sin tope.')
            .' Al facturar se puede cambiar por cuenta.';
    }

    /**
     * La cuenta abierta que ya tenga, si tiene.
     *
     * Evita chocar contra `elPacienteYaTieneUnaAbierta`: correr el
     * comando dos veces tiene que ser inofensivo.
     */
    private function cuentaAbiertaDe(Persona $persona): ?Cuenta
    {
        $cuenta = Cuenta::query()
            ->with('convenio')
            ->whereHas(
                'encuentro',
                fn (Builder $query): Builder => $query->where('persona_id', $persona->getKey()),
            )
            ->where('estado', EstadoCuenta::Abierta->value)
            ->orderByDesc('id')
            ->first();

        return $cuenta instanceof Cuenta ? $cuenta : null;
    }

    /**
     * @param array{nombre: string, segundo: ?string, apellido: string, apellido2: string, sexo: SexoBiologico, edad: int} $quien
     */
    private function personaExistente(array $quien): ?Persona
    {
        $persona = Persona::query()
            ->where('primer_nombre', $quien['nombre'])
            ->where('primer_apellido', $quien['apellido'])
            ->orderBy('id')
            ->first();

        return $persona instanceof Persona ? $persona : null;
    }

    /**
     * @param array{nombre: string, segundo: ?string, apellido: string, apellido2: string, sexo: SexoBiologico, edad: int} $quien
     */
    private function registrar(
        RegistradorDePacientes $registrador,
        array $quien,
        CarbonInterface $nacimiento,
        Sede $sede,
    ): Expediente {
        $datos = new DatosDePaciente(
            primerNombre: $quien['nombre'],
            primerApellido: $quien['apellido'],
            segundoNombre: $quien['segundo'],
            segundoApellido: $quien['apellido2'],
            sexoBiologico: $quien['sexo'],
            genero: $quien['sexo'] === SexoBiologico::Masculino ? Genero::Masculino : Genero::Femenino,
            fechaNacimiento: $nacimiento,
        );

        try {
            return $registrador->registrar($datos, $sede);
        } catch (PosibleDuplicadoException) {
            return $registrador->registrarPeseAlConflicto(
                $datos,
                $sede,
                'Paciente de prueba creado para la demostración de cobertura de seguros',
            );
        }
    }
}
