<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\NivelDeCoincidencia;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\Coincidencia;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Agrega y verifica documentos de una persona YA registrada (§11).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL ALTA NO ALCANZA
 * ─────────────────────────────────────────────────────────────────────
 *
 * En el alta se captura un documento, y muchas veces ninguno. Lo que pasa
 * después es rutina, no excepción:
 *
 *   · el paciente llegó sin cédula y la trae al día siguiente;
 *   · pide factura con RTN, que es otro documento;
 *   · el NN de anoche resultó ser alguien y hoy aparece su DNI;
 *   · admisión digitó el número por teléfono y hoy lo tiene en la mano.
 *
 * Ese último caso es el que justifica `verificar()`: un DNI dictado y uno
 * fotocopiado no valen lo mismo para facturar con RTN ni para reclamarle a
 * una aseguradora, y el sistema tiene que saber cuál es cuál.
 */
final class AgregadorDeDocumentos
{
    /**
     * Agrega un documento. Si el número ya es de OTRA persona, no lo crea.
     *
     * @throws PosibleDuplicadoException
     */
    public function agregar(
        Persona $persona,
        DocumentoDeIdentidad $documento,
        bool $verificado = false,
    ): PersonaIdentificador {
        $existente = $this->buscarDueno($documento);

        if ($existente instanceof PersonaIdentificador) {
            /*
             * Ya lo tiene ESTA persona: no es un error, es alguien
             * agregando dos veces lo mismo. Se devuelve el que ya está en
             * vez de reventar contra el índice único.
             */
            if ($existente->persona_id === $persona->getKey()) {
                return $existente;
            }

            throw new PosibleDuplicadoException(collect([
                new Coincidencia(
                    persona: $existente->persona instanceof Persona
                        ? $existente->persona->raiz()
                        : $persona,
                    nivel: NivelDeCoincidencia::Documento,
                    razon: "Mismo {$documento->tipo->etiqueta()} {$documento->enmascarado()}",
                ),
            ]));
        }

        return $this->crear($persona, $documento, $verificado, enConflicto: false, nota: null);
    }

    /**
     * Agrega aunque el número choque, dejando el conflicto declarado.
     *
     * Misma salida de emergencia que en el registro: el conflicto queda
     * REGISTRADO como conflicto, con explicación, en vez de disfrazado de
     * dato bueno o resuelto inventando un número.
     */
    public function agregarPeseAlConflicto(
        Persona $persona,
        DocumentoDeIdentidad $documento,
        string $justificacion,
        bool $verificado = false,
    ): PersonaIdentificador {
        return $this->crear($persona, $documento, $verificado, enConflicto: true, nota: $justificacion);
    }

    /**
     * Deja constancia de que alguien tuvo el documento FÍSICO en la mano.
     *
     * No se puede "desverificar": haberlo visto es un hecho, y borrarlo
     * seria reescribir lo que paso. Si se verifico por error, se corrige
     * como todo lo demas — dejando el rastro, no borrandolo.
     */
    public function verificar(PersonaIdentificador $identificador): PersonaIdentificador
    {
        if ($identificador->estaVerificado()) {
            return $identificador;
        }

        /*
         * Auth::id() esta tipado int|string|null porque la llave de un
         * usuario podria ser un UUID. En este sistema users.id es bigint,
         * pero se estrecha explicitamente en vez de forzar un cast: si
         * algun dia la llave dejara de ser entera, esto deja de guardar
         * basura en la columna en lugar de guardar un id truncado.
         */
        $usuarioId = Auth::id();

        $identificador->verificado_en = now();
        $identificador->verificado_por = is_int($usuarioId) ? $usuarioId : null;
        $identificador->save();

        return $identificador;
    }

    private function crear(
        Persona $persona,
        DocumentoDeIdentidad $documento,
        bool $verificado,
        bool $enConflicto,
        ?string $nota,
    ): PersonaIdentificador {
        return DB::transaction(function () use ($persona, $documento, $verificado, $enConflicto, $nota): PersonaIdentificador {
            /*
             * Un solo documento principal por persona, y la base lo impone
             * con un índice único parcial. Si el nuevo entra como
             * principal hay que bajar al anterior ANTES, dentro de la
             * misma transacción — si no, el insert choca contra el índice
             * y el usuario ve un error de base de datos sin sentido.
             */
            if ($documento->esPrincipal) {
                PersonaIdentificador::query()
                    ->where('persona_id', $persona->getKey())
                    ->where('es_principal', true)
                    ->update(['es_principal' => false]);
            }

            /** @var PersonaIdentificador $identificador */
            $identificador = PersonaIdentificador::query()->create(array_merge(
                $documento->atributos(),
                [
                    'persona_id'     => $persona->getKey(),
                    'en_conflicto'   => $enConflicto,
                    'conflicto_nota' => $enConflicto ? $nota : null,
                    'verificado_en'  => $verificado ? now() : null,
                    'verificado_por' => $verificado ? Auth::id() : null,
                ],
            ));

            return $identificador;
        });
    }

    private function buscarDueno(DocumentoDeIdentidad $documento): ?PersonaIdentificador
    {
        return PersonaIdentificador::query()
            ->deNumero($documento->tipo, $documento->valor)
            ->when(
                $documento->paisEmision !== null,
                fn (Builder $consulta): Builder => $consulta->where('pais_emision', $documento->paisEmision),
            )
            ->where('en_conflicto', false)
            ->with('persona')
            ->first();
    }
}
