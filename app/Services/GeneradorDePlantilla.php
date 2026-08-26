<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\PlantillaGenerada;
use App\Models\PlantillaLinea;
use App\Models\PlantillaPresupuesto;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Convierte un presupuesto ya cotizado en plantilla reusable (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTE CAMINO EXISTE, Y NO SOLO EL INVERSO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque **nadie se sienta a escribir una plantilla de veintidós
 * renglones en abstracto.** Eso queda para después y el después no
 * llega. Cotizar un caso real, en cambio, hay que hacerlo igual —y
 * guardarlo sale gratis—. El conocimiento del quirófano entra al sistema
 * por el trabajo que ya se estaba haciendo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UNA CIRUGÍA, UNA PLANTILLA (decisión de Mauricio, 26-ago-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Guardar de nuevo con el mismo código **REEMPLAZA** los renglones de la
 * plantilla existente. No se versiona, no se acumula.
 *
 * Es a propósito: si cada presupuesto pudiera dejar su propia plantilla,
 * en seis meses habría ochenta APENDICECTOMIA casi iguales, la cajera
 * dejaría de usarlas y volvería a escribir a mano — que es exactamente
 * el problema que las plantillas venían a resolver.
 *
 * ⚠️ Reemplazar el molde NO toca ningún presupuesto viejo: cada uno
 * copió sus renglones con el precio congelado el día que se cotizó.
 */
final class GeneradorDePlantilla
{
    public function desdePresupuesto(
        Presupuesto $presupuesto,
        string $codigo,
        string $nombre,
        ?string $descripcion = null,
        ?int $diasVigencia = null,
    ): PlantillaGenerada {
        return DB::transaction(function () use (
            $presupuesto,
            $codigo,
            $nombre,
            $descripcion,
            $diasVigencia
        ): PlantillaGenerada {
            $existente = PlantillaPresupuesto::query()->where('codigo', $codigo)->first();
            $reemplazo = $existente instanceof PlantillaPresupuesto;

            $renglones = $presupuesto->detalle()->with('item')->get();

            $plantilla = $existente ?? new PlantillaPresupuesto([
                'codigo'         => $codigo,
                'vigencia_desde' => now()->toDateString(),
            ]);

            $plantilla->fill([
                'nombre'           => $nombre,
                'descripcion'      => $descripcion,
                'dias_vigencia'    => $diasVigencia ?? $plantilla->dias_vigencia ?? 15,
                'holgura_fraccion' => $this->holguraDe($renglones),
            ]);

            $plantilla->save();

            /*
             * Reemplazo total, no merge. Un merge dejaría renglones
             * viejos que ya nadie quiere y que reaparecerían en la
             * próxima cotización sin que nadie los haya pedido.
             */
            $plantilla->lineas()->delete();

            $omitidos = [];
            $porItem = [];

            foreach ($renglones as $renglon) {
                if ($renglon->item_id === null) {
                    $omitidos[] = $renglon->texto;

                    continue;
                }

                /*
                 * ⚠️ `plantilla_lineas` tiene un único (plantilla, ítem):
                 * dos renglones del mismo ítem se SUMAN en cantidad. Sin
                 * esto, guardar un presupuesto con el mismo ítem dos
                 * veces reventaría contra el índice.
                 */
                $id = $renglon->item_id;

                $porItem[$id] = [
                    'cantidad' => isset($porItem[$id])
                        ? Decimal::de($porItem[$id]['cantidad'])->sumar($renglon->cantidad)->paraBase(4)
                        : $renglon->cantidad,
                    'opcional' => ($porItem[$id]['opcional'] ?? true) && $renglon->opcional,
                ];
            }

            $orden = 0;

            foreach ($porItem as $itemId => $datos) {
                $orden += 10;

                PlantillaLinea::create([
                    'plantilla_id' => $plantilla->id,
                    'item_id'      => $itemId,
                    'cantidad'     => $datos['cantidad'],
                    'orden'        => $orden,
                    'opcional'     => $datos['opcional'],
                ]);
            }

            return new PlantillaGenerada(
                plantilla: $plantilla->refresh(),
                copiados: count($porItem),
                omitidos: array_values(array_unique($omitidos)),
                reemplazo: $reemplazo,
            );
        });
    }

    /**
     * La holgura de la plantilla se DEDUCE de lo que se cotizó: si el
     * caso llevó 4,000 de colchón sobre 40,000 de renglones reales, la
     * plantilla guarda 10 %.
     *
     * Así el criterio de quien cotizó se conserva sin que tenga que
     * escribirlo otra vez.
     *
     * @param Collection<int, PresupuestoLinea> $renglones
     *
     * @return numeric-string
     */
    private function holguraDe($renglones): string
    {
        $holgura = Decimal::cero();
        $resto = Decimal::cero();

        foreach ($renglones as $renglon) {
            if ($renglon->origen === OrigenLineaPresupuesto::Holgura) {
                $holgura = $holgura->sumar($renglon->total);

                continue;
            }

            $resto = $resto->sumar($renglon->total);
        }

        if ($holgura->esCero() || $resto->esCero()) {
            return '0.0000';
        }

        $fraccion = $holgura->entre($resto);

        /*
         * El CHECK de la base la topa en 0.5. Una holgura mayor no es un
         * colchón: es una cotización inventada, y guardarla como molde
         * repetiría el error en cada caso siguiente.
         */
        return $fraccion->mayorQue(Decimal::de('0.5'))
            ? '0.5000'
            : $fraccion->paraBase(4);
    }
}
