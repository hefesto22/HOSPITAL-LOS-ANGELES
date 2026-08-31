<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que cobra CADA médico por CADA honorario.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA EL TARIFARIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «CONSULTA EXTERNA MEDICINA INTERNA» es un solo ítem del catálogo y el
 * hospital le pone un precio en el tarifario. Pero el doctor Carlos
 * cobra L 500 por esa misma consulta y el doctor Juan cobra L 100: el
 * precio no es del servicio, es del médico que lo presta.
 *
 * Meter eso en `tarifarios` sería inventarle una fila por doctor a un
 * eje —el convenio, la sede— que no habla de doctores, y el resolutor de
 * precios tendría que aprender a desempatar por una dimensión que no
 * tiene. Acá vive aparte y entra al cargo por el único camino legítimo
 * para un precio fuera del tarifario: `precioAcordado` (ADR-0009).
 *
 * ⚠️ MISMA BASE QUE EL TARIFARIO: precio unitario ANTES de ISV, con
 * cuatro decimales. Si algún día alguien guarda acá el precio con
 * impuesto incluido, la factura va a cobrar el ISV dos veces.
 *
 * Sin fila para un médico, ese honorario se cobra al precio del
 * tarifario. No tener lista propia es lo normal, no un error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honorarios_medicos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('medico_id')
                ->constrained('medicos')
                ->restrictOnDelete();

            /*
             * El ítem del catálogo. Se espera que sea de tipo honorario,
             * pero la base no lo puede exigir —el tipo vive en `items`—.
             * Lo exige el formulario, que solo lista honorarios.
             */
            $tabla->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $tabla->decimal('precio', 14, 4);

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['medico_id', 'item_id'], 'honorarios_medicos_medico_item_index');
        });

        /*
         * Un honorario no se regala ni se cobra en negativo. El CHECK está
         * en la base y no solo en el formulario porque los seeders y las
         * migraciones de datos no pasan por el formulario.
         */
        DB::statement(
            'ALTER TABLE honorarios_medicos
             ADD CONSTRAINT honorarios_medicos_precio_no_negativo CHECK (precio >= 0)'
        );

        /*
         * Un solo precio por médico, ítem y fecha de inicio. Dos filas
         * idénticas vigentes el mismo día dejarían el precio a suerte del
         * orden en que las devuelva PostgreSQL.
         */
        DB::statement(
            'CREATE UNIQUE INDEX honorarios_medicos_vigencia_unique
             ON honorarios_medicos (medico_id, item_id, vigencia_desde)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('honorarios_medicos');
    }
};
