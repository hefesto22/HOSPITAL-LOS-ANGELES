<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\NivelDeCoincidencia;
use App\Domain\ValueObjects\Coincidencia;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Busca si el paciente que se está por registrar ya existe.
 *
 * Corre ANTES de crear nada. Es lo que le da sentido a la búsqueda
 * tolerante del MPI: sin esto, el índice trigrama solo sirve cuando a
 * alguien se le ocurre buscar primero — y a las 3 de la mañana no se le
 * ocurre a nadie.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS PASADAS, PORQUE SON DOS PREGUNTAS DISTINTAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * 1. POR DOCUMENTO — es una prueba. El número exacto ya existe: o es el
 *    mismo paciente, o alguien digitó mal. Ninguna de las dos se arregla
 *    creando una persona nueva, así que esto BLOQUEA.
 *
 * 2. POR NOMBRE — es un parecido. "Juan Pérez" hay veinte. Esto AVISA,
 *    muestra los candidatos y deja seguir. Bloquear acá haría que la
 *    advertencia salte siempre, y una advertencia que salta siempre no
 *    advierte de nada: admisión aprende a darle "continuar" sin leerla.
 *
 * La fecha de nacimiento es el desempate del segundo caso. No se usa para
 * descartar: el dedazo en la fecha es de los errores más comunes de
 * captura, así que dos fechas distintas con el mismo nombre siguen siendo
 * un candidato — solo que más débil.
 */
final class DetectorDeDuplicados
{
    /**
     * Candidatos ordenados por fuerza: primero lo que bloquea.
     *
     * @return Collection<int, Coincidencia>
     */
    public function buscar(DatosDePaciente $datos, int $limite = 10): Collection
    {
        /** @var Collection<int, Coincidencia> $coincidencias */
        $coincidencias = collect();

        /*
         * La misma persona puede aparecer por dos caminos: coincide su DNI
         * y además coincide su nombre. Se queda con la primera aparición,
         * que es la más fuerte porque el orden es documento y después
         * nombre. Mostrarla dos veces le hace creer a admisión que hay dos
         * pacientes cuando hay uno.
         */
        $vistas = [];

        $candidatas = $this->porDocumento($datos)->concat($this->porNombre($datos, $limite));

        foreach ($candidatas as $coincidencia) {
            $id = $coincidencia->persona->getKey();

            if (isset($vistas[$id])) {
                continue;
            }

            $vistas[$id] = true;
            $coincidencias->push($coincidencia);
        }

        return $coincidencias;
    }

    /**
     * ¿Alguna de las coincidencias impide crear la persona?
     *
     * @param Collection<int, Coincidencia> $coincidencias
     */
    public function bloquean(Collection $coincidencias): bool
    {
        return $coincidencias->contains(
            static fn (Coincidencia $c): bool => $c->bloquea()
        );
    }

    /**
     * @return Collection<int, Coincidencia>
     */
    private function porDocumento(DatosDePaciente $datos): Collection
    {
        /** @var Collection<int, Coincidencia> $encontradas */
        $encontradas = collect();

        foreach ($datos->documentos as $documento) {
            if (! $documento instanceof DocumentoDeIdentidad) {
                continue;
            }

            $existentes = PersonaIdentificador::query()
                ->deNumero($documento->tipo, $documento->valor)
                ->when(
                    $documento->paisEmision !== null,
                    fn (Builder $consulta): Builder => $consulta->where('pais_emision', $documento->paisEmision),
                )
                ->with('persona')
                ->get();

            foreach ($existentes as $existente) {
                $persona = $existente->persona;

                /*
                 * Si el documento estaba en una persona que después se
                 * fusionó, el candidato correcto es la SOBREVIVIENTE. Sin
                 * esto, admisión abriría el expediente de un duplicado que
                 * alguien ya se tomó el trabajo de resolver.
                 */
                if (! $persona instanceof Persona) {
                    continue;
                }

                $raiz = $persona->raiz();

                $encontradas->push(new Coincidencia(
                    persona: $raiz,
                    nivel: NivelDeCoincidencia::Documento,
                    razon: "Mismo {$documento->tipo->etiqueta()} {$documento->enmascarado()}",
                ));
            }
        }

        return $encontradas;
    }

    /**
     * @return Collection<int, Coincidencia>
     */
    private function porNombre(DatosDePaciente $datos, int $limite): Collection
    {
        $clave = $datos->claveDeNombre();

        if ($clave === '' || $datos->esNn) {
            /*
             * Un NN no tiene contra qué comparar: se llama NN igual que
             * todos los demás NN. Buscarle duplicados devolvería todos los
             * NN del hospital y frenaría una emergencia por nada.
             */
            return collect();
        }

        /** @var Collection<int, Coincidencia> $encontradas */
        $encontradas = collect();

        foreach (Persona::buscar($clave, $limite) as $candidata) {
            $encontradas->push(new Coincidencia(
                persona: $candidata,
                nivel: $this->nivelPorFecha($datos, $candidata),
                razon: $this->razonPorFecha($datos, $candidata),
            ));
        }

        return $encontradas;
    }

    private function nivelPorFecha(DatosDePaciente $datos, Persona $candidata): NivelDeCoincidencia
    {
        if ($datos->fechaNacimiento === null || $candidata->fecha_nacimiento === null) {
            return NivelDeCoincidencia::Media;
        }

        return $candidata->fecha_nacimiento->isSameDay($datos->fechaNacimiento)
            ? NivelDeCoincidencia::Alta
            : NivelDeCoincidencia::Media;
    }

    private function razonPorFecha(DatosDePaciente $datos, Persona $candidata): string
    {
        if ($datos->fechaNacimiento === null || $candidata->fecha_nacimiento === null) {
            return 'Nombre parecido, sin fecha de nacimiento para comparar';
        }

        return $candidata->fecha_nacimiento->isSameDay($datos->fechaNacimiento)
            ? 'Mismo nombre y misma fecha de nacimiento'
            : 'Nombre parecido, fecha de nacimiento distinta';
    }
}
