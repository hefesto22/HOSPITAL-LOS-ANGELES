<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\Genero;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEgreso;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCargo;
use App\Services\RegistradorDePacientes;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Deja el sistema listo para MOSTRAR el descuento del hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES PACIENTES, UNO POR CADA TOPE
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · 23 años → sin descuento de ley. El hospital puede rebajar hasta 30 %.
 *   · 68 años → 25 % de ley. El hospital puede agregar hasta 10 %.
 *   · 85 años → 40 % de ley. No le cabe nada encima: ese 40 % ES el techo
 *     con el que se calculó el precio de lista.
 *
 * Con los tres al lado se ve de un vistazo que el que la ley protege
 * siempre paga menos, que es lo que hace legal a todo el esquema.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ POR QUÉ CIERRA Y NO BORRA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Las cuentas abiertas que encuentra NO se eliminan: sus cargos se anulan
 * con el mismo servicio que usa la pantalla —que devuelve la existencia al
 * estante y deja la reversa escrita— y después se cierran. Borrar las filas
 * a mano dejaría el kardex descuadrado: el medicamento habría salido de
 * bodega y no habría a quién cobrárselo.
 *
 * Es idempotente: correrlo dos veces no duplica pacientes.
 */
class SembrarDemoDeDescuentos extends Command
{
    protected $signature = 'sihla:demo-descuentos';

    protected $description = 'Cierra las cuentas abiertas y deja tres pacientes de prueba: joven, tercera y cuarta edad.';

    /**
     * Los tres de la demostración, en el orden en que se explican.
     *
     * @var list<array{nombre: string, segundo: ?string, apellido: string, apellido2: string, sexo: SexoBiologico, edad: int}>
     */
    private const PACIENTES = [
        [
            'nombre'    => 'MAURICIO',
            'segundo'   => 'ORLANDO',
            'apellido'  => 'CRUZ',
            'apellido2' => 'GARCIA',
            'sexo'      => SexoBiologico::Masculino,
            'edad'      => 23,
        ],
        [
            'nombre'    => 'JOSE',
            'segundo'   => 'ANTONIO',
            'apellido'  => 'MEJIA',
            'apellido2' => 'FLORES',
            'sexo'      => SexoBiologico::Masculino,
            'edad'      => 68,
        ],
        [
            'nombre'    => 'ROSA',
            'segundo'   => 'AMELIA',
            'apellido'  => 'PINEDA',
            'apellido2' => 'LOPEZ',
            'sexo'      => SexoBiologico::Femenino,
            'edad'      => 85,
        ],
    ];

    public function handle(
        RegistradorDePacientes $registrador,
        AbridorDeEncuentro $abridor,
        AnuladorDeCargo $anulador,
    ): int {
        $sede = Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            $this->error('No hay ninguna sede registrada. Corré los seeders base primero.');

            return self::FAILURE;
        }

        $convenio = Convenio::query()
            ->where('codigo', Convenio::CODIGO_CONTADO)
            ->first();

        if (! $convenio instanceof Convenio) {
            $this->error('No existe el convenio de contado (PACIENTE PARTICULAR).');

            return self::FAILURE;
        }

        $this->cerrarLasAbiertas($anulador);

        $filas = [];

        foreach (self::PACIENTES as $quien) {
            $nacimiento = now()->subYears($quien['edad'])->subDays(3);

            $persona = $this->personaExistente($quien);

            if ($persona instanceof Persona) {
                /*
                 * Al que ya está registrado solo se le corrige la fecha de
                 * nacimiento. Crear un segundo Mauricio para la prueba
                 * sembraría justo el duplicado que el módulo de MPI existe
                 * para evitar.
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

            $cuenta = $abridor->abrir(
                persona: $persona,
                expediente: $expediente,
                tipo: TipoEncuentro::Ambulatorio,
                convenio: $convenio,
                sede: $sede,
                motivo: 'Prueba del descuento del hospital',
            );

            $rango = RangoEdad::paraEdad($quien['edad']);

            $filas[] = [
                $persona->primer_nombre.' '.$quien['apellido'],
                $quien['edad'].' años',
                $rango->etiqueta(),
                $this->topeDeLaDemostracion($rango),
                $cuenta->numero,
            ];
        }

        $this->newLine();
        $this->table(
            ['Paciente', 'Edad', 'Rango', 'Puede darle el hospital', 'Cuenta'],
            $filas,
        );
        $this->newLine();
        $this->info('Listo. Las tres cuentas están abiertas y vacías, esperando el primer medicamento.');

        return self::SUCCESS;
    }

    private function cerrarLasAbiertas(AnuladorDeCargo $anulador): void
    {
        $abiertas = Cuenta::query()
            ->where('estado', EstadoCuenta::Abierta->value)
            ->with('encuentro')
            ->get();

        if ($abiertas->isEmpty()) {
            return;
        }

        foreach ($abiertas as $cuenta) {
            $cargos = $cuenta->cargos()->get();

            foreach ($cargos as $cargo) {
                if (! $cargo instanceof Cargo || ! $cargo->admiteAnulacionDirecta()) {
                    continue;
                }

                $anulador->anular($cargo, 'Reinicio de los datos de prueba antes de la demostración');
            }

            /*
             * 🔴 Cerrar NO es cambiar una palabra en una columna.
             *
             * `cuentas_cierre_completo` exige `cerrada_en`, y
             * `encuentros_cierre_completo` exige además `tipo_egreso`. La
             * base se niega a guardar una cuenta «cerrada» sin decir CUÁNDO
             * y un encuentro «cerrado» sin decir CÓMO se fue el paciente,
             * porque un cierre sin fecha ni egreso no le sirve a nadie
             * cuando alguien pregunte seis meses después.
             */
            $cuenta->refresh();
            $cuenta->forceFill([
                'estado'     => EstadoCuenta::Cerrada->value,
                'cerrada_en' => now(),
            ])->save();

            $cuenta->encuentro?->forceFill([
                'estado'      => EstadoEncuentro::Cerrado->value,
                'cerrado_en'  => now(),
                'tipo_egreso' => TipoEgreso::Domicilio->value,
            ])->save();

            $this->line('Cerrada '.$cuenta->numero.'.');
        }
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
            /*
             * Es un paciente de prueba: el conflicto queda declarado en vez
             * de frenar la demostración, que es exactamente para lo que
             * existe esa salida (§8.2).
             */
            return $registrador->registrarPeseAlConflicto(
                $datos,
                $sede,
                'Paciente de prueba creado para la demostración del descuento del hospital',
            );
        }
    }

    private function topeDeLaDemostracion(RangoEdad $rango): string
    {
        return match ($rango) {
            RangoEdad::Tercera => 'hasta 10 % (ya lleva 25 % de ley)',
            RangoEdad::Cuarta  => 'nada (ya lleva 40 % de ley)',
            default            => 'hasta 30 %',
        };
    }
}
