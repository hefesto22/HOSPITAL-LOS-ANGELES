<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoExpediente;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\PersonaVersion;
use App\Models\Sede;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta para dar de alta a un paciente (§11).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO PUEDE VIVIR EN EL FORMULARIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Registrar un paciente son CUATRO escrituras que tienen que pasar o
 * fallar juntas:
 *
 *   1. la persona
 *   2. sus documentos
 *   3. la versión 1 de su historial demográfico
 *   4. el expediente, con un correlativo tomado bajo lock
 *
 * Si eso queda repartido entre el formulario de Filament y un par de
 * `create()` sueltos, el día que la cuarta falle quedan una persona sin
 * expediente y un historial que dice que existe alguien que en la práctica
 * no se puede atender. Peor: el correlativo ya se consumió.
 *
 * Adentro de una transacción, o están las cuatro o no está ninguna.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LAS TRES PUERTAS, Y CUÁNDO SE USA CADA UNA
 * ─────────────────────────────────────────────────────────────────────
 *
 *  · `registrar()` — el caso normal. Si el DNI ya existe, LANZA. El
 *    llamador tiene que decidir qué hacer, y casi siempre lo correcto es
 *    abrir el expediente que ya existe.
 *
 *  · `registrarPeseAlConflicto()` — el caso raro y real: el número está
 *    repetido pero de verdad son dos personas distintas. Marca los
 *    documentos en conflicto CON la justificación y sigue. La nota no es
 *    opcional: la base la exige.
 *
 *  · `registrarNn()` — la emergencia. No pide un solo dato y no consulta
 *    duplicados, porque un NN no tiene contra qué compararse.
 */
final class RegistradorDePacientes
{
    public function __construct(
        private readonly DetectorDeDuplicados $detector,
        private readonly AsignadorDeCorrelativo $asignador,
    ) {}

    /**
     * Registra un paciente nuevo y le abre expediente en la sede.
     *
     * @throws PosibleDuplicadoException si el documento ya existe
     */
    public function registrar(DatosDePaciente $datos, Sede $sede): Expediente
    {
        $coincidencias = $this->detector->buscar($datos);

        if ($this->detector->bloquean($coincidencias)) {
            throw new PosibleDuplicadoException($coincidencias);
        }

        return $this->crear($datos, $sede, enConflicto: false, notaDeConflicto: null);
    }

    /**
     * Registra aunque el documento choque, dejando el conflicto declarado.
     *
     * ⚠️ Esta es la salida de emergencia del §8.2 y existe para que nadie
     * tenga que inventar un número a las 3 de la mañana. La diferencia con
     * inventarlo es que acá el conflicto queda REGISTRADO como conflicto,
     * con nombre, hora y explicación — y sale en la bandeja de revisión.
     */
    public function registrarPeseAlConflicto(
        DatosDePaciente $datos,
        Sede $sede,
        string $justificacion,
    ): Expediente {
        return $this->crear($datos, $sede, enConflicto: true, notaDeConflicto: $justificacion);
    }

    /**
     * El paciente sin identificar. No exige nada.
     */
    public function registrarNn(Sede $sede, ?string $rasgos = null): Expediente
    {
        return $this->crear(DatosDePaciente::nn($rasgos), $sede, enConflicto: false, notaDeConflicto: null);
    }

    /**
     * Abre expediente a una persona que YA existe.
     *
     * Es el camino del paciente que llega por primera vez a la segunda
     * sede, y el de quien estaba registrado como acompañante y ahora se
     * enferma. Es idempotente a propósito: si ya tiene expediente acá,
     * devuelve el mismo en vez de fallar. Que abrir dos veces la misma
     * carpeta reviente no le sirve a nadie en un mostrador.
     */
    public function abrirExpedienteEn(Persona $persona, Sede $sede): Expediente
    {
        /*
         * `deTodasLasSedes()` acá no es una fuga: la sede viene como
         * parámetro y se filtra por ella igual. Lo que evita es que el
         * scope de sesión esconda un expediente que SÍ existe y el sistema
         * intente crear el segundo — que reventaría contra el índice único
         * con un error de base de datos en la cara de admisión.
         */
        $existente = Expediente::query()
            ->deTodasLasSedes()
            ->where('persona_id', $persona->getKey())
            ->where('sede_id', $sede->getKey())
            ->first();

        if ($existente instanceof Expediente) {
            return $existente;
        }

        return DB::transaction(fn (): Expediente => $this->abrirCarpeta($persona, $sede));
    }

    private function crear(
        DatosDePaciente $datos,
        Sede $sede,
        bool $enConflicto,
        ?string $notaDeConflicto,
    ): Expediente {
        return DB::transaction(function () use ($datos, $sede, $enConflicto, $notaDeConflicto): Expediente {
            /** @var Persona $persona */
            $persona = Persona::query()->create($datos->atributosDePersona());

            foreach ($datos->documentos as $documento) {
                if (! $documento instanceof DocumentoDeIdentidad) {
                    continue;
                }

                PersonaIdentificador::query()->create(array_merge(
                    $documento->atributos(),
                    [
                        'persona_id'     => $persona->getKey(),
                        'en_conflicto'   => $enConflicto,
                        'conflicto_nota' => $enConflicto ? $notaDeConflicto : null,
                    ],
                ));
            }

            /*
             * La versión 1 se escribe acá y no en un observer del modelo.
             *
             * Un observer se dispara también cuando una prueba, un seeder o
             * un import crean una persona, y llenaría el historial de
             * versiones que nadie pidió. El historial documenta decisiones
             * de negocio; que exista una fila no es una decisión.
             *
             * `$persona->refresh()` antes de fotografiar: los defaults de la
             * base (sexo_biologico, precision) no están en el modelo recién
             * creado si no se enviaron, y la foto tiene que ser de lo que
             * quedó guardado, no de lo que se intentó guardar.
             */
            $persona->refresh();

            PersonaVersion::query()->create([
                'persona_id'     => $persona->getKey(),
                'version'        => 1,
                'datos'          => $persona->only(PersonaVersion::camposVersionados()),
                'motivo'         => 'Registro inicial del paciente',
                'registrado_por' => Auth::id(),
                'registrado_en'  => now(),
            ]);

            return $this->abrirCarpeta($persona, $sede);
        });
    }

    /**
     * Crea el expediente con su correlativo.
     *
     * El `sede_id` se pone EXPLÍCITO aunque BelongsToSede lo rellenaría
     * solo: el registrador recibe la sede como parámetro, y dejar que el
     * contexto de sesión decida en qué sede se abre una carpeta es cómo se
     * termina con el expediente de un paciente colgando de la sede
     * equivocada porque alguien tenía otra seleccionada en su pantalla.
     */
    private function abrirCarpeta(Persona $persona, Sede $sede): Expediente
    {
        $numero = $this->asignador->siguiente($sede, TipoCorrelativo::Expediente);

        /** @var Expediente $expediente */
        $expediente = Expediente::query()->create([
            'sede_id'            => $sede->getKey(),
            'persona_id'         => $persona->getKey(),
            'numero'             => $numero,
            'abierto_el'         => now()->toDateString(),
            'estado'             => EstadoExpediente::Activo,
            'ultima_atencion_el' => null,
        ]);

        return $expediente;
    }
}
