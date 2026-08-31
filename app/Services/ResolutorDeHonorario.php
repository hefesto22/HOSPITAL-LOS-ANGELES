<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Monto;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use Carbon\CarbonInterface;

/**
 * Cuánto cobra este médico por este honorario.
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

    public function para(Medico $medico, Item $item, ?CarbonInterface $momento = null): ?Monto
    {
        /*
         * ⚠️ Solo los honorarios. Un medicamento con precio «de médico»
         * no es una negociación: es una fila cargada en la pantalla
         * equivocada, y cobrarla saltearía el tarifario y el margen sobre
         * el costo promedio, que es de donde sale el precio de farmacia.
         */
        if ($item->tipo !== TipoItem::Honorario) {
            return null;
        }

        return $this->paraId((int) $medico->getKey(), (int) $item->getKey(), $momento);
    }

    public function paraId(int $medicoId, int $itemId, ?CarbonInterface $momento = null): ?Monto
    {
        $dia = ($momento ?? now())->toDateString();
        $clave = $medicoId.':'.$itemId.':'.$dia;

        if (array_key_exists($clave, $this->memoria)) {
            return $this->memoria[$clave];
        }

        $fila = HonorarioMedico::query()
            ->where('medico_id', $medicoId)
            ->where('item_id', $itemId)
            ->vigentes($momento)
            /*
             * La más reciente de las vigentes. El índice único impide dos
             * filas con la misma fecha de inicio, pero no impide una que
             * arrancó en enero y otra en junio, las dos abiertas: manda la
             * de junio, que es la última que alguien decidió.
             */
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        return $this->memoria[$clave] = $fila instanceof HonorarioMedico ? $fila->monto() : null;
    }
}
