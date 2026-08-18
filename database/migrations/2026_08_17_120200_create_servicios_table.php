<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios / áreas de la sede (§8.1).
 *
 *     sedes ──< servicios/áreas ──< almacenes
 *                              └── camas
 *
 * ⚠️ PRIMERA TABLA TRANSACCIONAL DEL SISTEMA. El patrón de `sede_id` que
 * queda acá es el que se copia en las ~200 restantes:
 *
 *   - FK con índice en la MISMA migración (§12)
 *   - NOT NULL: un servicio sin sede no se puede atribuir a nada
 *   - restrictOnDelete: borrar una sede con servicios adentro debe fallar
 *     ruidosamente, no arrastrar el histórico
 *   - único por (sede, código): dos sedes pueden tener cada una su
 *     "EMERG"; dentro de una sede, no
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')
                ->constrained('sedes')
                ->restrictOnDelete();

            $tabla->string('codigo', 20);
            $tabla->string('nombre');
            $tabla->string('tipo', 30);

            /*
             * Centro de costo al que se imputa lo que este servicio
             * consume y no se le cobra al paciente (PoliticaCargo).
             */
            $tabla->string('centro_costo', 20)->nullable();

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['sede_id', 'tipo'], 'servicios_sede_tipo_index');
        });

        /*
         * Único por sede + código, ignorando borrados. Igual que en sedes,
         * un UNIQUE normal impediría reutilizar el código de un servicio
         * dado de baja (§12).
         */
        DB::statement(
            'CREATE UNIQUE INDEX servicios_sede_codigo_unique
             ON servicios (sede_id, codigo)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
