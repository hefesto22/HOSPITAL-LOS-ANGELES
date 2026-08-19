<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El costo en cada línea del kardex.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL COSTO VA EN EL MOVIMIENTO Y NO SOLO EN LA TABLA DE COSTOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * `costos_promedio` dice cuánto vale HOY. Estas dos columnas dicen
 * cuánto valía en cada momento, y son lo que convierte al kardex en un
 * kardex valorizado de verdad:
 *
 *   · **cuánto valía el inventario al 31 de diciembre** — la pregunta
 *     del cierre contable, que no se puede contestar con el costo de
 *     hoy;
 *   · **el día exacto en que el costo saltó**, y contra qué documento;
 *   · **cuánto costó lo que se despachó**, que es la mitad del margen
 *     real de cada factura.
 *
 * Es exactamente el mismo argumento que `saldo_despues`: la foto en cada
 * línea es lo que hace que la historia no dependa del presente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NULABLES, Y NO POR COMODIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los movimientos anteriores a esta migración no tienen costo y no lo
 * van a tener: el kardex es append-only, así que rellenarlos sería
 * inventar un dato. Nulo significa «esto pasó antes de que el sistema
 * costeara», que es la verdad.
 *
 * Un ajuste o una merma tampoco traen costo propio: se valorizan al
 * promedio vigente, y ese queda en `costo_promedio_despues`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_kardex', function (Blueprint $tabla): void {
            /*
             * Lo que costó la unidad EN ESTE movimiento. En una entrada
             * de compra es el costo de la línea; en una salida es el
             * promedio con el que se descargó.
             */
            $tabla->decimal('costo_unitario', 14, 6)->nullable()->after('saldo_despues');

            /*
             * El promedio ponderado que quedó vigente después de este
             * movimiento. La contracara exacta de `saldo_despues`.
             */
            $tabla->decimal('costo_promedio_despues', 14, 6)->nullable()->after('costo_unitario');
        });

        DB::statement(
            'ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_costos_no_negativos
             CHECK (
                 (costo_unitario IS NULL OR costo_unitario >= 0)
                 AND (costo_promedio_despues IS NULL OR costo_promedio_despues >= 0)
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE movimientos_kardex DROP CONSTRAINT IF EXISTS kardex_costos_no_negativos');

        Schema::table('movimientos_kardex', function (Blueprint $tabla): void {
            $tabla->dropColumn(['costo_unitario', 'costo_promedio_despues']);
        });
    }
};
