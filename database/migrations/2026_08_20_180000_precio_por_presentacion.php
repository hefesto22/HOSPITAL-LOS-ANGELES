<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 EL PRECIO TAMBIÉN SE SEPARA POR ENVASE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ, SI EL KARDEX SE LLEVA EN MILILITROS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El frasco de 60 ML costó L 16.67 el mililitro y el de 80 ML costó
 * L 18.75. Con un solo precio para los dos, el margen del hospital sale
 * distinto según de cuál frasco se sirvió la dosis — y nadie lo sabe,
 * porque la factura dice lo mismo en los dos casos.
 *
 * Separando el precio por envase, el costo y el precio siguen al MISMO
 * frasco: el margen se cumple envase por envase en vez de en promedio.
 * Es la otra mitad de lo que ya hace la existencia.
 *
 * ⚠️ El precio sigue siendo POR UNIDAD DE DISPENSACIÓN —por mililitro—,
 * no por frasco. Lo que cambia es de qué frasco salió ese mililitro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NULO SIGUE SIENDO VÁLIDO Y ES EL RESPALDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una fila sin envase es el precio del producto entero, y es el que se
 * usa cuando no hay uno específico. Nada de lo que ya estaba deja de
 * funcionar: el resolutor prefiere el del envase y cae al general.
 *
 * La restricción de traslape suma el envase a su llave. Sin eso, dos
 * precios del mismo producto para envases distintos y fechas que se
 * pisan serían rechazados como si fueran el mismo precio dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifarios', function (Blueprint $tabla): void {
            $tabla->foreignId('item_presentacion_id')
                ->nullable()
                ->after('item_id')
                ->constrained('item_presentaciones')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE tarifarios DROP CONSTRAINT IF EXISTS tarifarios_sin_traslape');

        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_sin_traslape
             EXCLUDE USING gist (
                 item_id WITH =,
                 (COALESCE(item_presentacion_id, 0)) WITH =,
                 (COALESCE(convenio_id, 0)) WITH =,
                 (COALESCE(sede_id, 0)) WITH =,
                 vigencia WITH &&
             )
             WHERE (deleted_at IS NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tarifarios DROP CONSTRAINT IF EXISTS tarifarios_sin_traslape');

        Schema::table('tarifarios', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('item_presentacion_id');
        });

        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_sin_traslape
             EXCLUDE USING gist (
                 item_id WITH =,
                 (COALESCE(convenio_id, 0)) WITH =,
                 (COALESCE(sede_id, 0)) WITH =,
                 vigencia WITH &&
             )
             WHERE (deleted_at IS NULL)'
        );
    }
};
