<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRECIO SE ESCRIBE COMO EL MÍNIMO DEL ENVASE (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «la caja a 120 es lo mínimo que puede pagar la cuarta edad;
 * menos de ahí se considera pérdida». De ese número sale todo:
 *
 *   · la lista para todos: mínimo ÷ (1 − descuento máximo), redondeada
 *     hacia arriba —L 120 → L 200—;
 *   · el blíster y la tableta suelta, más caros por unidad que la caja:
 *     se recarga lo que cuesta fraccionar —por defecto +10 % y +25 %—.
 *
 * Dos cosas nuevas en la base:
 *
 *   1. Los recargos viven junto al margen objetivo, por tipo de ítem y
 *      con vigencia: son política de precio igual que el margen, y
 *      Mauricio pidió poder cambiarlos sin tocar código.
 *
 *   2. `tarifarios.precio_envase`: lo que cuesta el envase entero, exacto.
 *      El tarifario guarda el precio POR UNIDAD con cuatro decimales, y un
 *      blíster de 14 a L 25.00 reconstruido desde 1.7857 la tableta daría
 *      L 24.9998. El envase sin abrir se cobra con este número; el
 *      unitario queda para lo suelto y para leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('margenes_objetivo', function (Blueprint $tabla): void {
            /*
             * Fracciones, igual que el margen: 0.1000 = 10 %. El default
             * es la decisión de Mauricio para todo el hospital, y es lo
             * que reciben las filas que ya existen.
             */
            $tabla->decimal('recargo_envase', 6, 4)->default('0.1000');
            $tabla->decimal('recargo_suelta', 6, 4)->default('0.2500');
        });

        DB::statement(
            'ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_recargos_no_negativos
             CHECK (recargo_envase >= 0 AND recargo_suelta >= 0)'
        );

        Schema::table('tarifarios', function (Blueprint $tabla): void {
            $tabla->decimal('precio_envase', 14, 2)->nullable();
        });

        /*
         * Solo una fila de envase puede decir cuánto cuesta el envase: en
         * la fila de la unidad suelta no hay envase del que hablar.
         */
        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_precio_envase_de_un_envase
             CHECK (precio_envase IS NULL OR (item_presentacion_id IS NOT NULL AND precio_envase >= 0))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tarifarios DROP CONSTRAINT IF EXISTS tarifarios_precio_envase_de_un_envase');

        Schema::table('tarifarios', function (Blueprint $tabla): void {
            $tabla->dropColumn('precio_envase');
        });

        DB::statement('ALTER TABLE margenes_objetivo DROP CONSTRAINT IF EXISTS margenes_objetivo_recargos_no_negativos');

        Schema::table('margenes_objetivo', function (Blueprint $tabla): void {
            $tabla->dropColumn(['recargo_envase', 'recargo_suelta']);
        });
    }
};
