<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\Genero;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Persona;
use App\Models\PlantillaPresupuesto;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\AnuladorDeCargo;
use App\Services\CotizadorDePresupuesto;
use App\Services\RegistradorDePacientes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Una apendicectomía como la cobra el hospital: un PAQUETE, no una lista.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 SE COBRA EL PAQUETE, NO LOS INSUMOS UNO POR UNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La primera versión de este comando le cargaba a la cuenta las
 * dieciocho líneas de la plantilla, cada una con su precio. Y así no es
 * como el hospital cobra una cirugía: la familia acepta un número
 * —«la apendicectomía sale L 21,499»— y ese número es lo que ve en su
 * cuenta, en UN renglón. Lo que hay adentro se despliega en «qué
 * incluye», pero no se le cobra suelto.
 *
 * El camino real, y el que este comando reproduce (ADR-0009):
 *
 *   1. Se cotiza desde la plantilla, que agrega su HOLGURA —el colchón
 *      del 10 %— por lo que siempre se sale de lo previsto.
 *   2. El presupuesto se agrega a la cuenta: entra un cargo por el monto
 *      acordado, con `precioAcordado`, que es el único camino legítimo
 *      para un precio que no sale del tarifario.
 *   3. Lo que se vaya consumiendo sale de bodega marcado
 *      `IncluidoEnTarifa`: descuenta existencia, congela su costo, y NO
 *      se le vuelve a cobrar al paciente.
 *
 * Cobrar los insumos sueltos Y el paquete sería cobrar dos veces lo
 * mismo, que es exactamente lo que la política de cargo existe para
 * impedir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTE CASO Y NO UNA CONSULTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una cirugía con un paciente de 72 años y seguro de por medio junta las
 * reglas que se pisan entre sí: el descuento del Art. 30 para
 * intervención quirúrgica —el más alto del catálogo—, la cobertura del
 * seguro que se aplica DESPUÉS del descuento, y un paquete cuyo precio
 * no sale del tarifario. En una consulta de L 900 un error de orden de
 * operaciones no se ve; acá sí.
 *
 * ⚠️ Es idempotente y NO borra: si Fausto ya tiene cargos sueltos de una
 * corrida anterior, se ANULAN con el mismo servicio que usa la pantalla
 * —que devuelve la existencia al estante y deja la reversa escrita— y
 * recién entonces entra el paquete. Borrar filas a mano dejaría el
 * kardex diciendo que salió mercadería que nadie devolvió.
 */
class SembrarDemoDeApendicectomia extends Command
{
    protected $signature = 'sihla:demo-apendicectomia {convenio=PALIG : El código del pagador}';

    protected $description = 'Deja una cuenta con una apendicectomía completa: paciente de tercera edad con seguro.';

    /**
     * El código de la plantilla que arma la cirugía.
     *
     * Se leen sus líneas de la base y no de una lista escrita acá: la
     * plantilla es del hospital y puede cambiar, y una copia en el código
     * se despega de la que se usa en presupuestos.
     */
    private const PLANTILLA = 'CX-APENDICE';

    private const EDAD = 72;

    public function handle(
        RegistradorDePacientes $registrador,
        AbridorDeEncuentro $abridor,
        CotizadorDePresupuesto $cotizador,
        AgregadorDePresupuestoALaCuenta $agregador,
        AnuladorDeCargo $anulador,
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

        $plantilla = PlantillaPresupuesto::query()
            ->where('codigo', self::PLANTILLA)
            ->first();

        if (! $plantilla instanceof PlantillaPresupuesto) {
            $this->error('No existe la plantilla '.self::PLANTILLA.'. Corré PlantillasDePresupuestoSeeder.');

            return self::FAILURE;
        }

        $cuenta = $this->cuentaDeFausto($registrador, $abridor, $sede, $convenio);

        if (! $cuenta instanceof Cuenta) {
            return self::FAILURE;
        }

        $this->limpiarLoQueSeCargoSuelto($cuenta, $anulador);

        /*
         * La cotización sale con la fecha de HOY y con el pagador de la
         * cuenta: el precio de un paquete es el de su día y el de su
         * convenio, no el de cuando alguien escribió la plantilla.
         */
        $presupuesto = $cotizador->desdePlantilla(
            plantilla: $plantilla,
            expediente: $cuenta->encuentro->expediente,
            convenio: $convenio,
            sede: $sede,
            fecha: now(),
            encuentro: $cuenta->encuentro,
        );

        $cargo = $agregador->sincronizar($presupuesto);

        if (! $cargo instanceof Cargo) {
            $this->error('El presupuesto quedó sin nada que cobrar. Revisá que la plantilla tenga renglones con precio.');

            return self::FAILURE;
        }

        $cuenta->refresh();
        $presupuesto->refresh();

        $this->newLine();
        $this->info('Cuenta '.$cuenta->numero.' — '.$cuenta->encuentro->persona->nombreCompleto());
        $this->line('Presupuesto '.$presupuesto->numero.' · '.$presupuesto->lineas()->count().' renglones adentro');

        $this->table(
            ['Total de la cuenta', 'Le toca al paciente', 'Le toca al seguro'],
            [[
                'L '.number_format((float) $cuenta->total, 2),
                'L '.number_format((float) $cuenta->total_paciente, 2),
                'L '.number_format((float) $cuenta->total_aseguradora, 2),
            ]],
        );

        $this->newLine();
        $this->line('En la cuenta se lee UN renglón —«'.$cargo->texto.'»— con su «qué incluye» adentro.');

        return self::SUCCESS;
    }

    /**
     * Anula lo que una corrida anterior haya dejado cargado suelto.
     *
     * ⚠️ ANULA, no borra. El servicio es el mismo que usa la pantalla:
     * devuelve la existencia al estante y deja la reversa escrita.
     * Borrar las filas dejaría el kardex diciendo que salió mercadería
     * que nadie devolvió, y eso no se arregla después.
     */
    private function limpiarLoQueSeCargoSuelto(Cuenta $cuenta, AnuladorDeCargo $anulador): void
    {
        $sueltos = $cuenta->cargos()
            ->whereNull('presupuesto_id')
            ->get()
            ->filter(static fn (Cargo $cargo): bool => $cargo->admiteAnulacionDirecta());

        if ($sueltos->isEmpty()) {
            return;
        }

        foreach ($sueltos as $cargo) {
            $anulador->anular($cargo, 'Se reemplaza por el paquete presupuestado de la apendicectomía.');
        }

        $this->line('Se anularon '.$sueltos->count().' cargos sueltos de una corrida anterior.');
    }

    /**
     * Fausto, 72 años, con su cuenta abierta en ese pagador.
     *
     * Si ya existe se le corrige la edad y se reusa su cuenta abierta:
     * crear un segundo Fausto sembraría el duplicado que el módulo de MPI
     * existe para evitar.
     */
    private function cuentaDeFausto(
        RegistradorDePacientes $registrador,
        AbridorDeEncuentro $abridor,
        Sede $sede,
        Convenio $convenio,
    ): ?Cuenta {
        $nacimiento = now()->subYears(self::EDAD)->subDays(5);

        $persona = Persona::query()
            ->where('primer_nombre', 'FAUSTO')
            ->where('primer_apellido', 'MORAZAN')
            ->orderBy('id')
            ->first();

        if ($persona instanceof Persona) {
            $persona->forceFill(['fecha_nacimiento' => $nacimiento->toDateString()])->save();
            $expediente = $registrador->abrirExpedienteEn($persona, $sede);
        } else {
            $datos = new DatosDePaciente(
                primerNombre: 'FAUSTO',
                primerApellido: 'MORAZAN',
                segundoNombre: 'ANTONIO',
                segundoApellido: 'CACERES',
                sexoBiologico: SexoBiologico::Masculino,
                genero: Genero::Masculino,
                fechaNacimiento: $nacimiento,
            );

            try {
                $expediente = $registrador->registrar($datos, $sede);
            } catch (PosibleDuplicadoException) {
                $expediente = $registrador->registrarPeseAlConflicto(
                    $datos,
                    $sede,
                    'Paciente de prueba de la demostración de apendicectomía',
                );
            }

            $persona = $expediente->persona;
        }

        if (! $persona instanceof Persona) {
            $this->error('El expediente quedó sin persona. Eso no debería poder pasar.');

            return null;
        }

        $abierta = Cuenta::query()
            ->with(['convenio', 'encuentro.persona', 'encuentro.expediente'])
            ->whereHas(
                'encuentro',
                fn (Builder $query): Builder => $query->where('persona_id', $persona->getKey()),
            )
            ->where('estado', EstadoCuenta::Abierta->value)
            ->orderByDesc('id')
            ->first();

        if ($abierta instanceof Cuenta) {
            $this->line('Fausto ya tenía la cuenta '.$abierta->numero.' abierta: se le carga ahí.');

            return $abierta;
        }

        return $abridor->abrir(
            persona: $persona,
            expediente: $expediente,
            /*
             * Hospitalización y no ambulatorio: una apendicectomía tiene
             * estancia, y el tipo de encuentro decide qué se puede cobrar.
             */
            tipo: TipoEncuentro::Hospitalizacion,
            convenio: $convenio,
            sede: $sede,
            motivo: 'Apendicectomía — prueba del cruce entre descuento de ley y cobertura',
        );
    }
}
