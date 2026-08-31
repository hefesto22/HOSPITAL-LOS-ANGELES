<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\Genero;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\PlantillaLinea;
use App\Models\PlantillaPresupuesto;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDePacientes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Una apendicectomía completa, con seguro y con descuento de tercera edad.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CASO DONDE SE CRUZA TODO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una cirugía con un paciente de 72 años y PALIG de por medio junta en
 * una sola cuenta las cuatro reglas que se pisan entre sí:
 *
 *   · El descuento del Art. 30 para intervención quirúrgica, que es el
 *     más alto del catálogo.
 *   · La cobertura del seguro, que se aplica DESPUÉS del descuento.
 *   · Veinte renglones de distinta naturaleza —quirófano, estancia,
 *     equipo, laboratorio— cada uno con su régimen de ISV y su categoría
 *     legal propia.
 *   · Medicamentos que descuentan existencia, junto a servicios que no.
 *
 * Es el escenario en el que un error de orden de operaciones se ve, y en
 * el que no se ve en una consulta de L 900.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO ALCANZA SE INFORMA, NO SE INVENTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * ⚠️ Los renglones que mueven inventario necesitan existencia de verdad.
 * Si no hay, el cargo se salta y se dice cuál: sembrar existencia por
 * atrás para que la demo se vea completa dejaría el kardex diciendo que
 * entró mercadería que nadie recibió.
 *
 * Es idempotente: correrlo dos veces no duplica el paciente, y si ya
 * tiene cuenta abierta le sigue cargando ahí.
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
        RegistradorDeCargo $cargador,
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
            ->with('lineas.item')
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

        $puestos = 0;
        $saltados = [];

        foreach ($plantilla->lineas as $linea) {
            if (! $linea instanceof PlantillaLinea || $linea->item === null) {
                continue;
            }

            try {
                $cargador->registrar($cuenta, new LineaDeCargo(
                    item: $linea->item,
                    cantidad: Decimal::de($linea->cantidad),
                    claveIdempotencia: (string) Str::uuid(),
                ));

                $puestos++;
            } catch (SihlaException $e) {
                /*
                 * Casi siempre es falta de existencia. Se dice cuál y por
                 * qué, en vez de sembrar inventario que nadie recibió.
                 */
                $saltados[] = [$linea->item->codigo, $linea->item->nombre, $e->getMessage()];
            }
        }

        $cuenta->refresh();

        $this->newLine();
        $this->info('Cuenta '.$cuenta->numero.' — '.$cuenta->encuentro->persona->nombreCompleto());
        $this->table(
            ['Renglones', 'Total', 'Le toca al paciente', 'Le toca al seguro'],
            [[
                $puestos,
                'L '.number_format((float) $cuenta->total, 2),
                'L '.number_format((float) $cuenta->total_paciente, 2),
                'L '.number_format((float) $cuenta->total_aseguradora, 2),
            ]],
        );

        if ($saltados !== []) {
            $this->newLine();
            $this->warn('No se pudieron cargar '.count($saltados).' renglones:');
            $this->table(['Código', 'Producto', 'Por qué'], $saltados);
            $this->line('Casi siempre es falta de existencia. Recibí mercadería y volvé a correr el comando.');
        }

        return self::SUCCESS;
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
            ->with(['convenio', 'encuentro.persona'])
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
