<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL PRESUPUESTO DICE CUÁNTO SE LE COBRA A LA FAMILIA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio, de lo que contó el doctor: los renglones suman L 645, a la
 * familia se le cobran L 1,000, y el resto se completa con holgura o
 * agregando más cosas hasta llegar a los L 1,000.
 *
 * Dos columnas:
 *
 *   · `presupuestos.monto_acordado` — lo que se le va a cobrar. Nulo es
 *     «no se acordó un monto»: el presupuesto vale lo que sumen sus
 *     renglones, como hasta hoy.
 *
 *   · `presupuesto_lineas.completa_el_monto` — marca LA holgura que se
 *     ajusta sola para llegar al monto: si se agrega un renglón, ella se
 *     achica; si se quita, crece. Solo una por presupuesto, y solo puede
 *     ser una holgura: un renglón del catálogo que cambiara de precio
 *     solo para cuadrar dejaría el papel mintiendo sobre lo que cuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presupuestos', function (Blueprint $tabla): void {
            $tabla->decimal('monto_acordado', 14, 2)->nullable();
        });

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_monto_acordado_positivo
             CHECK (monto_acordado IS NULL OR monto_acordado > 0)'
        );

        Schema::table('presupuesto_lineas', function (Blueprint $tabla): void {
            $tabla->boolean('completa_el_monto')->default(false);
        });

        DB::statement(
            "ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_solo_la_holgura_completa
             CHECK (NOT completa_el_monto OR origen = 'holgura')"
        );

        DB::statement(
            'CREATE UNIQUE INDEX presupuesto_lineas_un_complemento
             ON presupuesto_lineas (presupuesto_id)
             WHERE completa_el_monto'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS presupuesto_lineas_un_complemento');
        DB::statement('ALTER TABLE presupuesto_lineas DROP CONSTRAINT IF EXISTS presupuesto_lineas_solo_la_holgura_completa');

        Schema::table('presupuesto_lineas', function (Blueprint $tabla): void {
            $tabla->dropColumn('completa_el_monto');
        });

        DB::statement('ALTER TABLE presupuestos DROP CONSTRAINT IF EXISTS presupuestos_monto_acordado_positivo');

        Schema::table('presupuestos', function (Blueprint $tabla): void {
            $tabla->dropColumn('monto_acordado');
        });
    }
};
