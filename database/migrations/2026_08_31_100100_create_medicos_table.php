<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los médicos que cobran honorario en el hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO SON LOS USUARIOS DEL SISTEMA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El cirujano que opera un sábado y el anestesiólogo que lo acompaña
 * cobran honorario y no entran a SIHLA nunca. Si «médico» fuera una
 * marca sobre `users`, habría que crearle usuario y contraseña a cada
 * uno para poder cobrarle un honorario al paciente —cuentas que nadie
 * usa y que alguien tiene que administrar—.
 *
 * `user_id` queda para el que SÍ entra: así el médico tratante de un
 * encuentro y el que cobra el honorario terminan siendo la misma ficha.
 * Es nulo la mayoría de las veces, y eso está bien.
 *
 * ⚠️ Catálogo GLOBAL, sin `sede_id`. Un médico atiende donde lo llamen;
 * de qué sede fue la atención lo dice el encuentro, no el doctor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('nombre');

            $tabla->foreignId('especialidad_id')
                ->constrained('especialidades')
                ->restrictOnDelete();

            /*
             * El número del Colegio Médico de Honduras. Nulo se acepta:
             * el hospital no siempre lo tiene a mano el día que registra
             * al doctor, y bloquear el alta por eso termina en médicos
             * cargados como texto libre en otro lado.
             */
            $tabla->string('colegiacion', 30)->nullable();

            $tabla->string('telefono', 30)->nullable();

            /*
             * El usuario del sistema, cuando el médico además entra. Nulo
             * para el externo, que es la mayoría.
             */
            $tabla->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index('especialidad_id', 'medicos_especialidad_index');
        });

        /*
         * La colegiación es única cuando existe. Dos fichas con el mismo
         * número son el mismo doctor cargado dos veces, y eso parte en
         * dos la liquidación de sus honorarios.
         *
         * El índice parcial deja fuera los nulos —que son muchos— y los
         * borrados, igual que el resto de los catálogos.
         */
        DB::statement(
            'CREATE UNIQUE INDEX medicos_colegiacion_unique
             ON medicos (colegiacion)
             WHERE colegiacion IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos');
    }
};
