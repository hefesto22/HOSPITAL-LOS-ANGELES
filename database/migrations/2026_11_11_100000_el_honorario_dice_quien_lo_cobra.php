<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quién va cada honorario: a un médico o a un turno o área del personal.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los honorarios de enfermería, el circulante y el instrumentista no son
 * de una persona registrada: son del turno o del área que dice el nombre
 * del ítem. Hasta hoy se cargaban con el selector de médico a la vista
 * —sin sentido— y, como no llevaban médico, no salían en ningún reporte.
 * Ver `QuienCobraElHonorario`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `NOT NULL DEFAULT 'medico'`: en PostgreSQL 11+ agregar una columna con
 * un valor por omisión constante no reescribe la tabla, y todo lo que ya
 * existe queda como estaba —un honorario de médico—. Marcar los del
 * personal es un dato del hospital y lo hace `HonorariosDelPersonalSeeder`,
 * que se corre aparte.
 *
 * El CHECK deja «personal» solo para los honorarios: un servicio o un
 * medicamento «del personal» no significa nada, y el día que alguien lo
 * escriba desde un import el reporte lo pondría en un turno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->string('cobra_el_honorario', 20)->default('medico');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE items
            ADD CONSTRAINT items_cobra_el_honorario_check
            CHECK (
                cobra_el_honorario IN ('medico', 'personal')
                AND (tipo = 'honorario' OR cobra_el_honorario = 'medico')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_cobra_el_honorario_check');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn('cobra_el_honorario');
        });
    }
};
