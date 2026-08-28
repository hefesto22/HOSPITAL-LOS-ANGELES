<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\CarbonInterface;

/**
 * Cambiar un precio desde la pantalla de bases, sin romper el historial.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CORREGIR NO ES LO MISMO QUE CAMBIAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el precio vigente se cargó HOY, escribir otro número es corregir un
 * error de dedo: se sobrescribe la misma fila y no queda nada. Si el
 * precio vigente es de otro día, escribir otro número es un CAMBIO DE
 * PRECIO: se cierra la vigencia de la fila vieja y se abre una nueva
 * desde hoy, que es lo que exige el ADR-0003.
 *
 * La diferencia importa cuando una aseguradora pregunta a cuánto estaba
 * un ítem en marzo. Sin vigencias, la respuesta no existe. Con una fila
 * nueva por cada tecla, la ficha del ítem se vuelve ilegible en un mes.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ NO SE DELEGA TODO EN `FijadorDePrecio`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque `fijar()` cierra la fila vigente poniéndole `vigencia_hasta` =
 * el día ANTERIOR al nuevo precio. Es correcto para un cambio de verdad
 * —los rangos quedan pegados y sin traslape— pero si la fila vigente
 * arrancó hoy, la deja con `desde` hoy y `hasta` ayer, y el CHECK
 * `tarifarios_vigencia_coherente` la rechaza.
 *
 * O sea: fijar dos precios el mismo día para el mismo ítem revienta con
 * un error de PostgreSQL. Es exactamente lo que pasa al corregir un cero
 * de más recién tecleado, así que ese caso se resuelve acá y el resto se
 * delega.
 */
final class AjustadorDeBaseDePrecios
{
    public function __construct(
        private readonly FijadorDePrecio $fijador,
    ) {}

    /**
     * @param Convenio|null $convenio nulo = el precio de lista
     */
    public function ajustar(
        Item $item,
        ?Convenio $convenio,
        Monto $precio,
        string $motivo,
        ?Sede $sede = null,
        ?CarbonInterface $desde = null,
    ): Tarifario {
        $dia = ($desde ?? now())->copy()->startOfDay();

        $vigente = $this->vigente($item, $convenio, $sede, $dia);

        /*
         * Misma fecha = corrección. Se sobrescribe y no queda rastro,
         * porque no hay nada que rastrear: ese precio no estuvo vigente
         * ni un día completo y nadie cobró contra él.
         */
        if ($vigente instanceof Tarifario
            && $vigente->vigencia_desde->isSameDay($dia)) {
            $vigente->update([
                'precio' => $precio->cantidad()->paraBase(4),
                'motivo' => $motivo,
            ]);

            return $vigente->refresh();
        }

        return $this->fijador->fijar(
            item: $item,
            convenio: $convenio,
            sede: $sede,
            precio: $precio,
            motivo: $motivo,
            desde: $dia,
        );
    }

    /**
     * Saca un ítem de la base de un pagador: desde hoy vuelve a cobrarse
     * al precio de lista.
     *
     * ─────────────────────────────────────────────────────────────────
     * DOS CAMINOS, Y LA FECHA DECIDE CUÁL
     * ─────────────────────────────────────────────────────────────────
     *
     *   · SI EMPEZÓ HOY, se borra. Es la misma regla que `ajustar()`:
     *     ese precio no estuvo vigente ni un día completo y nadie cobró
     *     contra él, así que no hay historia que conservar. Es el caso
     *     real: alguien tecleó un número donde no iba y lo quiere sacar.
     *
     *   · SI VENÍA DE ANTES, se CIERRA la vigencia ayer y la fila queda.
     *     Con ella se explican las facturas que se emitieron mientras
     *     estuvo vigente, y esas no se pueden quedar sin respaldo.
     *
     * ⚠️ Ayer y no hoy: `vigentesEn` incluye los dos extremos, así que
     * cerrarla hoy la dejaría viva un día más.
     *
     * ⚠️ NO toca el precio de lista. Un ítem sin precio de lista no se
     * puede cobrar en ninguna parte, así que quitarlo de ahí no es una
     * corrección: es dejarlo mudo. Se retira el ítem, no su precio.
     */
    public function quitar(
        Item $item,
        Convenio $convenio,
        string $motivo,
        ?Sede $sede = null,
        ?CarbonInterface $desde = null,
    ): bool {
        $dia = ($desde ?? now())->copy()->startOfDay();

        $vigente = $this->vigente($item, $convenio, $sede, $dia);

        if (! $vigente instanceof Tarifario) {
            return false;
        }

        if ($vigente->vigencia_desde->isSameDay($dia)) {
            $vigente->delete();

            return true;
        }

        $vigente->update([
            'vigencia_hasta' => $dia->copy()->subDay()->toDateString(),
            'motivo'         => $motivo,
        ]);

        return true;
    }

    private function vigente(
        Item $item,
        ?Convenio $convenio,
        ?Sede $sede,
        CarbonInterface $dia,
    ): ?Tarifario {
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            /*
             * ⚠️ `whereNull` y no `where(..., null)`: en SQL una
             * comparación con NULL nunca es verdadera, así que el precio
             * de lista —que tiene `convenio_id` nulo— jamás aparecería.
             */
            ->when(
                $convenio instanceof Convenio,
                fn ($consulta) => $consulta->where('convenio_id', $convenio?->id),
                fn ($consulta) => $consulta->whereNull('convenio_id'),
            )
            ->when(
                $sede instanceof Sede,
                fn ($consulta) => $consulta->where('sede_id', $sede?->id),
                fn ($consulta) => $consulta->whereNull('sede_id'),
            )
            ->vigentesEn($dia)
            ->first();

        return $fila instanceof Tarifario ? $fila : null;
    }
}
