<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Especialidades médicas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UNA TABLA Y NO UN TEXTO EN LA FICHA DEL MÉDICO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «CIRUGÍA GENERAL», «Cirugia general» y «CIRUJANO» tecleados a mano son
 * tres especialidades distintas para la base y una sola para el hospital.
 * La pregunta que se va a hacer —cuánto se le pagó este mes a los
 * anestesiólogos— no se puede contestar sobre texto libre.
 *
 * Es un catálogo GLOBAL, sin `sede_id`: un cirujano general lo es en
 * todas las sedes. Lo que sí es por sede son los encuentros que atiende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especialidades', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('codigo', 20);
            $tabla->string('nombre');

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * Único ignorando borrados, como en sedes y servicios: una
         * especialidad dada de baja no puede bloquear para siempre su
         * propio código (§12).
         */
        DB::statement(
            'CREATE UNIQUE INDEX especialidades_codigo_unique
             ON especialidades (codigo)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('especialidades');
    }
};
