<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 UN LOTE ES UN NÚMERO **Y UN ENVASE**, NO SOLO UN NÚMERO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL AMONTONAMIENTO QUE QUEDABA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Con la llave en (ítem, número), tres entradas del mismo jarabe con el
 * mismo número de lote —diez frascos de 60, diez de 80 y diez de 120—
 * caían todas en UNA fila. La existencia decía 2600 ML, y como el lote se
 * había quedado con el primer envase que vio, la pantalla informaba «43
 * envases y 20 ML»: un número que no existe en ningún estante.
 *
 * Un laboratorio puede usar el mismo número de lote para el frasco de 60
 * y el de 120 —es el mismo jarabe embotellado distinto— pero en la bodega
 * son dos cosas separadas: se cuentan aparte, se abren aparte y cuestan
 * distinto. La llave tiene que decirlo.
 *
 * ⚠️ NO se pierde trazabilidad. Si ARSA retira «el lote L-10», sigue
 * encontrándose por número: ahora devuelve las dos filas en vez de una,
 * que son exactamente los dos envases que hay que sacar del estante.
 *
 * `COALESCE(item_presentacion_id, 0)` porque en Postgres NULL ≠ NULL: sin
 * eso, un ítem que se recibe sin envase declarado podría crear filas
 * duplicadas del mismo lote sin que el índice lo impida.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS lotes_unico_por_item');

        DB::statement(
            'CREATE UNIQUE INDEX lotes_unico_por_item
             ON lotes (item_id, numero, COALESCE(item_presentacion_id, 0))
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lotes_unico_por_item');

        DB::statement(
            'CREATE UNIQUE INDEX lotes_unico_por_item
             ON lotes (item_id, numero)
             WHERE deleted_at IS NULL'
        );
    }
};
