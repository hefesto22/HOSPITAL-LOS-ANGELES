<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ─────────────────────────────────────────────────────────────────────
 * STOCK INFINITO MIENTRAS SE CUADRA EL INVENTARIO (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «debe haber una opción para colocarle que el stock es
 * infinito, ya que en producción también se usará al crear los
 * medicamentos, insumos y material. No sabemos cuánto tiempo tardarán en
 * cuadrar el inventario; a veces irán agregando stock de uno o dos
 * productos y ahí desactivarán el infinito y pasarán al real».
 *
 * Un producto con `stock_infinito` se puede cargar a una cuenta aunque no
 * haya existencia: si hay en el estante sale de ahí primero (con su lote
 * y su kardex), y lo que no alcance se carga igual, sin movimiento de
 * inventario. Es una bandera de TRANSICIÓN por producto, no un modo del
 * sistema: se apaga producto por producto a medida que se cuadra.
 *
 * Dos reglas que la base hace cumplir, además del modelo:
 *
 *   · Solo lo que se almacena. Un servicio no tiene existencia que le
 *     falte.
 *   · 🔴 Nunca un estupefaciente o psicotrópico (§9.F11). Su libro exige
 *     saldo corrido real ante ARSA; venderlo «sin existencia» es lo que
 *     ese libro existe para impedir.
 *
 * `ADD COLUMN ... DEFAULT false` con constante no reescribe la tabla en
 * PostgreSQL: es instantáneo aunque el catálogo sea grande.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE items ADD COLUMN stock_infinito boolean NOT NULL DEFAULT false');

        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT items_stock_infinito_solo_si_se_almacena '
            .'CHECK (NOT stock_infinito OR se_almacena)'
        );

        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT items_stock_infinito_nunca_controlado '
            .'CHECK (NOT (stock_infinito AND es_controlado))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_stock_infinito_nunca_controlado');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_stock_infinito_solo_si_se_almacena');
        DB::statement('ALTER TABLE items DROP COLUMN IF EXISTS stock_infinito');
    }
};
