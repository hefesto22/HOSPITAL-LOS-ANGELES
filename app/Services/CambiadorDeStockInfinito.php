<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Prende o apaga el stock infinito de varios productos a la vez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN SERVICIO Y NO UN UPDATE EN LA ACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El día del arranque se prende para todo el catálogo de farmacia, y
 * después se va apagando de a uno o dos a medida que se cuadra el
 * inventario (13-oct-2026). Un `UPDATE items SET ...` en la acción de la
 * tabla se saltaría el modelo —la regla del controlado, la bitácora de
 * cambios— y dejaría la decisión sin rastro de quién la tomó.
 *
 * 🔴 Los estupefacientes y psicotrópicos se SALTAN al prender, y se
 * cuentan aparte para decirlo en pantalla: su libro exige existencia
 * real ante ARSA (§9.F11). Apagar sí se les deja: nunca pueden tenerlo
 * prendido, así que no hay nada que apagar.
 */
final class CambiadorDeStockInfinito
{
    /**
     * @param iterable<Item> $items
     *
     * @return array{cambiados: int, controlados: int}
     */
    public function cambiar(iterable $items, bool $infinito): array
    {
        /** @var array{cambiados: int, controlados: int} $resultado */
        $resultado = DB::transaction(function () use ($items, $infinito): array {
            $cambiados = 0;
            $controlados = 0;

            foreach ($items as $item) {
                if (! $item->mueveInventario()) {
                    continue;
                }

                if ($infinito && $item->es_controlado) {
                    $controlados++;

                    continue;
                }

                if ((bool) $item->stock_infinito === $infinito) {
                    continue;
                }

                $item->stock_infinito = $infinito;
                $item->save();

                $cambiados++;
            }

            return ['cambiados' => $cambiados, 'controlados' => $controlados];
        });

        return $resultado;
    }
}
