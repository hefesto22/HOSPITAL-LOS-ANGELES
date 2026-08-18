<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades de medida — el vocabulario del catálogo (§8.4).
 *
 * Tabla y no enum, porque el hospital va a inventar unidades que hoy no
 * existen: "vial", "sobre", "kit de curación", "bolsa colectora". Un enum
 * obligaría a desplegar para agregar una (§1.1).
 *
 * No lleva `sede_id`: un mililitro mide lo mismo en las dos sedes.
 *
 * ⚠️ `permite_fraccion` no es cosmético. Es lo que impide que el kardex
 * quede con 2.5 ampollas — media ampolla que se abrió no es media
 * existencia, es una ampolla consumida y una merma. El kardex se lleva
 * SIEMPRE en la unidad de dispensación, así que si esa unidad no admite
 * fracción, ninguna salida puede tenerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('codigo', 15);
            $tabla->string('nombre', 60);
            $tabla->string('simbolo', 10)->nullable();
            $tabla->string('magnitud', 20);

            $tabla->boolean('permite_fraccion')->default(false);

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index('magnitud', 'unidades_magnitud_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX unidades_codigo_unique
             ON unidades (codigo)
             WHERE deleted_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE unidades
             ADD CONSTRAINT unidades_codigo_no_vacio
             CHECK (length(btrim(codigo)) > 0)'
        );

        DB::statement(
            "ALTER TABLE unidades
             ADD CONSTRAINT unidades_magnitud_conocida
             CHECK (magnitud IN ('conteo', 'volumen', 'masa', 'longitud', 'tiempo'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};
