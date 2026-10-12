<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LA CATEGORÍA LEGAL DEL RENGLÓN CABE ENTERA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `presupuesto_lineas.categoria_legal` nació en varchar(30) y la
 * categoría de medicamentos — `medicamento_material_quirurgico` — mide
 * 31. Agregar cualquier medicamento o material a un presupuesto
 * reventaba con «value too long for type character varying(30)».
 *
 * `items.categoria_legal_descuento` y `descuentos_legales.categoria_legal`
 * ya miden 40: el renglón se alinea con ellos. Ensanchar un varchar en
 * PostgreSQL no reescribe la tabla.
 *
 * (`cargos.categoria_legal` se queda en 30: ahí se guarda el rango de
 * edad — tercera, cuarta —, no la categoría.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE presupuesto_lineas ALTER COLUMN categoria_legal TYPE varchar(40)');
    }

    public function down(): void
    {
        /*
         * Si ya hay renglones de medicamentos, PostgreSQL se niega a
         * achicar la columna en vez de truncar — que es lo correcto.
         */
        DB::statement('ALTER TABLE presupuesto_lineas ALTER COLUMN categoria_legal TYPE varchar(30)');
    }
};
