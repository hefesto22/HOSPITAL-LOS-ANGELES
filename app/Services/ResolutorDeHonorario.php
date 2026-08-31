<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuánto cobra este médico por este honorario, a este pagador.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO ESPECÍFICO GANA, LO GENERAL SIEMPRE RESPONDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un doctor no le cobra lo mismo al paciente que llega de la calle que
 * al del Hospital Militar o al de PALIG. La escalera es la misma del
 * tarifario:
 *
 *   1. La fila de ESE pagador, si el médico tiene una.
 *   2. Si no, su precio general —`convenio_id` nulo—.
 *   3. Si tampoco, nulo: se cobra lo que dice el tarifario del hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEVUELVE NULO CUANDO NO TIENE LISTA PROPIA, Y ESO ES LO NORMAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * Nulo NO es un error ni un precio cero: significa «este médico no tiene
 * un número propio para esto, cobrá lo que dice el tarifario». La
 * mayoría de los honorarios se cobran así, y solo unos pocos doctores
 * —los que negociaron su tarifa— tienen filas acá.
 *
 * Devolver `Monto::cero()` en lugar de nulo regalaría el honorario, que
 * es exactamente el error que este tipo de retorno existe para impedir.
 *
 * ⚠️ El precio que devuelve es ANTES de ISV, igual que el del tarifario,
 * porque entra al cargo por `LineaDeCargo::$precioAcordado` y el motor
 * aplica el impuesto después.
 */
final class ResolutorDeHonorario
{
    /**
     * Memoria por petición: la pantalla de cargos pregunta esto en cada
     * pintada del modal —o sea, con cada tecla del buscador—.
     *
     * @var array<string, Monto|null>
     */
    private array $memoria = [];

    public function para(
        Medico $medico,
        Item $item,
        ?Convenio $convenio = null,
        ?CarbonInterface $momento = null,
    ): ?Monto {
        /*
         * ⚠️ Solo los honorarios. Un medicamento con precio «de médico»
         * no es una negociación: es una fila cargada en la pantalla
         * equivocada, y cobrarla saltearía el tarifario y el margen sobre
         * el costo promedio, que es de donde sale el precio de farmacia.
         */
        if ($item->tipo !== TipoItem::Honorario) {
            return null;
        }

        return $this->paraId(
            (int) $medico->getKey(),
            (int) $item->getKey(),
            $convenio instanceof Convenio ? (int) $convenio->getKey() : null,
            $momento,
        );
    }

    public function paraId(
        int $medicoId,
        int $itemId,
        ?int $convenioId = null,
        ?CarbonInterface $momento = null,
    ): ?Monto {
        $dia = ($momento ?? now())->toDateString();
        $clave = $medicoId.':'.$itemId.':'.($convenioId ?? 'general').':'.$dia;

        if (array_key_exists($clave, $this->memoria)) {
            return $this->memoria[$clave];
        }

        $fila = HonorarioMedico::query()
            ->where('medico_id', $medicoId)
            ->where('item_id', $itemId)
            ->vigentes($momento)
            /*
             * La del pagador o la general, nunca la de OTRO pagador: el
             * precio negociado con PALIG no se le cobra al del Hospital
             * Militar solo porque exista.
             */
            ->where(fn (Builder $consulta): Builder => $consulta
                ->whereNull('convenio_id')
                ->when(
                    $convenioId !== null,
                    fn (Builder $conPagador): Builder => $conPagador->orWhere('convenio_id', $convenioId),
                ))
            /*
             * 🔴 Lo específico primero. En PostgreSQL `false` ordena antes
             * que `true`, así que «no es nulo» —la fila del pagador— sale
             * arriba y la general queda de respaldo.
             *
             * La base impide dos vigencias traslapadas del mismo médico,
             * honorario y pagador, así que dentro de cada escalón hay una
             * sola candidata: este orden desempata ENTRE escalones, no
             * entre filas equivalentes.
             */
            ->orderByRaw('convenio_id IS NULL')
            ->first();

        return $this->memoria[$clave] = $fila instanceof HonorarioMedico ? $fila->monto() : null;
    }
}
