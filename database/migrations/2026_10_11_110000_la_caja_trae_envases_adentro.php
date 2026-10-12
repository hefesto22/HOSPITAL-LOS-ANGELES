<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 DE UNA CAJA SALEN BLÍSTERES SIN ABRIR (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «el blíster es una presentación que se vende, pero casi
 * siempre llega en cajas: si una caja trae 100 pastillas y el blíster
 * son 10, de una caja se sacan 10 blísteres».
 *
 * Para saberlo hace falta un dato que la ficha no tenía: si el envase
 * trae OTROS envases adentro. Una CAJA X 100 trae diez blísteres; un
 * FRASCO X 100 trae las tabletas sueltas, y de ahí no sale ningún
 * blíster aunque 100 sea múltiplo de 10. El número solo no alcanza.
 *
 * Es un dato del TIPO de envase, no de cada producto: se marca una vez
 * en Unidades y vale para todo el catálogo. Arranca encendido solo en
 * CAJA; el hospital lo enciende en las que agregue —PAQUETE, DISPLAY—.
 *
 * La regla completa vive en `FormasDeCobro::envasesQueLoTraen()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unidades', function (Blueprint $tabla): void {
            $tabla->boolean('contiene_envases')->default(false);
        });

        DB::table('unidades')
            ->where('codigo', 'CAJA')
            ->update(['contiene_envases' => true]);
    }

    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $tabla): void {
            $tabla->dropColumn('contiene_envases');
        });
    }
};
