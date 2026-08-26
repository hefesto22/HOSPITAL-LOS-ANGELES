<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «No más de cuánto» debería costar esta cirugía (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES UN CONTROL DE CRITERIO, NO UN PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El precio de cada renglón sigue saliendo del tarifario. Esto es otra
 * cosa: lo que el hospital ESPERA que cueste una apendicectomía normal.
 *
 * Sirve para atrapar la cotización que se fue de rango —52,000 donde
 * siempre son 40,000— antes de que se imprima y la familia la firme. Sin
 * esto, el error se descubre al egreso, discutiendo en caja.
 *
 * ⚠️ AVISA, NO IMPIDE. Un caso puede costar de verdad más que el tope, y
 * frenar a la cajera cuando dirección no está sería peor que el problema
 * que resuelve.
 *
 * Nullable a propósito: una plantilla sin tope simplemente no compara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantillas_presupuesto', function (Blueprint $tabla): void {
            $tabla->decimal('tope_referencia', 14, 2)->nullable();
        });

        DB::statement(
            'ALTER TABLE plantillas_presupuesto ADD CONSTRAINT plantillas_presupuesto_tope_positivo
             CHECK (tope_referencia IS NULL OR tope_referencia > 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE plantillas_presupuesto DROP CONSTRAINT IF EXISTS plantillas_presupuesto_tope_positivo'
        );

        Schema::table('plantillas_presupuesto', function (Blueprint $tabla): void {
            $tabla->dropColumn('tope_referencia');
        });
    }
};
