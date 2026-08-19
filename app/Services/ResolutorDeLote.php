<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\RecepcionException;
use App\Models\Item;
use App\Models\Lote;
use Carbon\CarbonInterface;

/**
 * El lote que corresponde a lo que llegó: el que ya existe, o uno nuevo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN LOTE NO PUEDE VENCER DOS VECES
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el lote AB-123 de amoxicilina ya está registrado venciendo el 1 de
 * octubre y llega una entrada que dice 1 de noviembre, **algo está mal**
 * y no es cosa del sistema decidir cuál de los dos gana:
 *
 *   · o el número se tecleó mal y en realidad es otro lote,
 *   · o la caja que llegó no es del lote que dice el papel.
 *
 * Las dos posibilidades se resuelven mirando el envase, y las dos tienen
 * consecuencias: con la fecha equivocada, FEFO despacha en el orden
 * equivocado y algo se vence en el estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CONTRADECIR ES ERROR; OMITIR, NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si la línea **no trae** vencimiento y el lote ya tiene uno registrado,
 * se reusa el registrado sin decir nada: nadie contradijo nada, solo no
 * se volvió a teclear un dato que el sistema ya sabe. Lo que se rechaza
 * es la contradicción, no el silencio.
 */
final class ResolutorDeLote
{
    /**
     * @throws RecepcionException si el lote existe con otro vencimiento
     */
    public function resolver(
        Item $item,
        string $numero,
        ?CarbonInterface $vencimiento = null,
        ?string $proveedor = null,
    ): Lote {
        /*
         * El número se canoniza acá y no en el modelo: viene tecleado
         * desde bodega y "ab-123 " tiene que encontrar al "AB-123" que ya
         * está guardado. Sin esto, el índice único no sirve de nada
         * porque cada variante de mayúsculas crea su propio lote.
         */
        $numero = mb_strtoupper(trim($numero));

        $existente = Lote::query()
            ->where('item_id', $item->id)
            ->where('numero', $numero)
            ->first();

        if ($existente instanceof Lote) {
            $this->exigirQueElVencimientoCoincida($existente, $item, $vencimiento);

            return $existente;
        }

        return Lote::query()->create([
            'item_id'           => $item->id,
            'numero'            => $numero,
            'fecha_vencimiento' => $vencimiento?->toDateString(),
            'proveedor'         => $proveedor,
        ]);
    }

    /**
     * @throws RecepcionException
     */
    private function exigirQueElVencimientoCoincida(
        Lote $lote,
        Item $item,
        ?CarbonInterface $recibido,
    ): void {
        if (! $recibido instanceof CarbonInterface) {
            return;
        }

        $registrado = $lote->fecha_vencimiento?->toDateString();

        if ($registrado === $recibido->toDateString()) {
            return;
        }

        throw RecepcionException::loteConOtroVencimiento(
            item: $item->etiqueta(),
            lote: $lote->numero,
            registrado: $registrado ?? 'sin fecha de vencimiento',
            recibido: $recibido->toDateString(),
        );
    }
}
