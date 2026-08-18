<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoFusion;
use App\Domain\Exceptions\FusionInvalidaException;
use App\Models\FusionDePersona;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fusión de duplicados con doble aprobación y reversa (§9.D4).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA FUSIÓN NO BORRA NI MUEVE NADA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Unir dos pacientes es escribir UN puntero: `merged_into`. Las dos filas
 * quedan intactas, y cualquier documento emitido antes sigue apuntando a
 * la fila que lo generó — por eso una factura de hace dos años se
 * reimprime igual. Deshacer es borrar ese puntero.
 *
 * La alternativa —mover las filas hijas de una persona a la otra— es
 * destructiva y no tiene vuelta atrás. Por eso `merged_into` existe desde
 * la primera migración del MPI: no para usarlo el día uno, sino para que
 * el día que haga falta no signifique reescribir el sistema.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ HACEN FALTA DOS PERSONAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Fusionar mal es peor que no fusionar. Dos pacientes distintos unidos
 * comparten alergias, medicación y antecedentes: alguien puede recibir
 * penicilina porque el sistema dice que la tolera, cuando lo que tolera
 * es el otro. Un duplicado sin resolver, en cambio, es incómodo y se
 * arregla después.
 *
 * Por eso quien propone no puede aprobar, y esa regla NO vive solo acá:
 * está como CHECK en la base. Un control de cuatro ojos que solo existe
 * en el servicio deja de existir en cuanto alguien escribe un seeder.
 *
 * Mientras la fusión está en `propuesta` no ha pasado nada: los dos
 * pacientes siguen separados y atendiéndose por su cuenta.
 */
final class FusionadorDePersonas
{
    public function __construct(
        private readonly ActualizadorDePersona $actualizador,
    ) {}

    /**
     * Pide fusionar dos personas. NO las une todavía.
     *
     * @throws FusionInvalidaException
     */
    public function proponer(Persona $duplicada, Persona $sobreviviente, string $motivo): FusionDePersona
    {
        $usuario = $this->usuarioActual();

        /*
         * Se relee el estado desde la base antes de decidir.
         *
         * El modelo que llega puede estar viejo: la fusion pudo aprobarse
         * en otra pestana hace un segundo, o el llamador puede tener una
         * instancia cargada desde antes. Confiar en el significa proponer
         * una fusion sobre alguien que ya esta fusionado, y eso termina en
         * dos punteros encadenados que nadie pidio.
         *
         * Una validacion que mira datos viejos no es una validacion.
         */
        $duplicada->refresh();
        $sobreviviente->refresh();

        if ($duplicada->getKey() === $sobreviviente->getKey()) {
            throw FusionInvalidaException::esLaMismaPersona();
        }

        if ($duplicada->estaFusionada()) {
            throw FusionInvalidaException::laDuplicadaYaEstaFusionada(
                $duplicada->raiz()->nombreParaListado()
            );
        }

        /*
         * Si la sobreviviente ya fue fusionada, apuntarle dejaría una
         * cadena que hay que recorrer para saber quién queda vigente. Se
         * rechaza y se dice cuál es la raíz, en vez de redirigir en
         * silencio: quien fusiona tiene que ver contra quién lo hace.
         */
        if ($sobreviviente->estaFusionada()) {
            throw FusionInvalidaException::laSobrevivienteEstaFusionada(
                $sobreviviente->raiz()->nombreParaListado()
            );
        }

        if ($this->hayPropuestaAbierta($duplicada)) {
            throw FusionInvalidaException::yaHayUnaPropuestaAbierta();
        }

        /** @var FusionDePersona $fusion */
        $fusion = FusionDePersona::query()->create([
            'persona_duplicada_id'     => $duplicada->getKey(),
            'persona_sobreviviente_id' => $sobreviviente->getKey(),
            'estado'                   => EstadoFusion::Propuesta,
            'motivo'                   => $motivo,
            'propuesta_por'            => $usuario->getKey(),
            'propuesta_en'             => now(),
        ]);

        return $fusion;
    }

    /**
     * Aprueba y APLICA la fusión. Acá sí se unen.
     *
     * @throws FusionInvalidaException
     */
    public function aprobar(FusionDePersona $fusion, ?string $nota = null): FusionDePersona
    {
        $usuario = $this->usuarioActual();

        // Mismo motivo que en proponer(): el estado se lee de la base.
        $fusion->refresh();

        if (! $fusion->estado->esperaDecision()) {
            throw FusionInvalidaException::noEstaEsperandoDecision($fusion->estado->etiqueta());
        }

        if ($fusion->propuesta_por === $usuario->getKey()) {
            throw FusionInvalidaException::quienProponeNoPuedeAprobar();
        }

        return DB::transaction(function () use ($fusion, $usuario, $nota): FusionDePersona {
            $duplicada = $fusion->duplicada;
            $sobreviviente = $fusion->sobreviviente;

            $duplicada->merged_into = $sobreviviente->getKey();
            $duplicada->merged_at = now();
            $duplicada->merged_by = $usuario->getKey();
            $duplicada->merged_motivo = $fusion->motivo;
            $duplicada->save();

            /*
             * La versión se escribe DESPUÉS de unir, y es lo que hace
             * reversible la operación: guarda el estado con el puntero
             * puesto, así que comparándola con la anterior se sabe
             * exactamente qué cambió la fusión.
             */
            $this->actualizador->registrarVersion(
                $duplicada,
                'Fusionada en '.$sobreviviente->nombreParaListado()
            );

            $fusion->estado = EstadoFusion::Aplicada;
            $fusion->resuelta_por = $usuario->getKey();
            $fusion->resuelta_en = now();
            $fusion->resolucion_nota = $nota;
            $fusion->save();

            return $fusion;
        });
    }

    /**
     * Descarta la propuesta sin unir nada.
     *
     * @throws FusionInvalidaException
     */
    public function rechazar(FusionDePersona $fusion, string $nota): FusionDePersona
    {
        $usuario = $this->usuarioActual();

        $fusion->refresh();

        if (! $fusion->estado->esperaDecision()) {
            throw FusionInvalidaException::noEstaEsperandoDecision($fusion->estado->etiqueta());
        }

        if ($fusion->propuesta_por === $usuario->getKey()) {
            throw FusionInvalidaException::quienProponeNoPuedeAprobar();
        }

        $fusion->estado = EstadoFusion::Rechazada;
        $fusion->resuelta_por = $usuario->getKey();
        $fusion->resuelta_en = now();
        $fusion->resolucion_nota = $nota;
        $fusion->save();

        return $fusion;
    }

    /**
     * Separa dos personas que se habían unido.
     *
     * A diferencia de aprobar, esto SÍ lo puede hacer quien propuso: es
     * una corrección, no una autorización, y trabarla obligaría a
     * conseguir a otra persona para arreglar un error que ya está
     * haciendo daño.
     *
     * @throws FusionInvalidaException
     */
    public function deshacer(FusionDePersona $fusion, string $motivo): FusionDePersona
    {
        $usuario = $this->usuarioActual();

        $fusion->refresh();

        if ($fusion->estado !== EstadoFusion::Aplicada) {
            throw FusionInvalidaException::noEstaAplicada();
        }

        return DB::transaction(function () use ($fusion, $usuario, $motivo): FusionDePersona {
            $duplicada = $fusion->duplicada;

            $duplicada->merged_into = null;
            $duplicada->merged_at = null;
            $duplicada->merged_by = null;
            $duplicada->merged_motivo = null;
            $duplicada->save();

            $this->actualizador->registrarVersion($duplicada, 'Fusión deshecha: '.$motivo);

            $fusion->estado = EstadoFusion::Deshecha;
            $fusion->deshecha_por = $usuario->getKey();
            $fusion->deshecha_en = now();
            $fusion->deshecha_motivo = $motivo;
            $fusion->save();

            return $fusion;
        });
    }

    private function hayPropuestaAbierta(Persona $duplicada): bool
    {
        return FusionDePersona::query()
            ->pendientes()
            ->where('persona_duplicada_id', $duplicada->getKey())
            ->exists();
    }

    /**
     * Una fusión sin responsable no se puede auditar, y auditar es la
     * mitad del punto. Por eso esto no corre desde consola.
     */
    private function usuarioActual(): User
    {
        $usuario = Auth::user();

        if (! $usuario instanceof User) {
            throw FusionInvalidaException::haceFaltaUnUsuario();
        }

        return $usuario;
    }
}
