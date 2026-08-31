<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La identidad del médico.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA CON LA COLEGIACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los tarifarios que el hospital presenta a las aseguradoras listan a
 * cada especialista con su NÚMERO DE IDENTIDAD, no con su colegiación
 * —el del Militar así viene—, y la aseguradora liquida contra ese
 * número. Sin la columna, esa lista no se puede cargar sin perder el
 * único dato con el que el pagador identifica al médico.
 *
 * Además hay quien cobra honorario y no está colegiado en Honduras: la
 * nutricionista y la psicóloga del tarifario son licenciadas, no
 * médicas. Para ellas la identidad es lo único que hay.
 *
 * ⚠️ Nulo se acepta, igual que la colegiación: el hospital no siempre
 * la tiene el día que registra al doctor, y bloquear el alta por eso
 * termina con el médico anotado en un papel al lado del teclado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicos', function (Blueprint $tabla): void {
            $tabla->string('identidad', 20)->nullable()->after('nombre');
        });

        /*
         * Única cuando existe, igual que la colegiación: dos fichas con
         * la misma identidad son el mismo médico cargado dos veces, y
         * eso parte en dos la liquidación de sus honorarios.
         */
        DB::statement(
            'CREATE UNIQUE INDEX medicos_identidad_unique
             ON medicos (identidad)
             WHERE identidad IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS medicos_identidad_unique');

        Schema::table('medicos', function (Blueprint $tabla): void {
            $tabla->dropColumn('identidad');
        });
    }
};
