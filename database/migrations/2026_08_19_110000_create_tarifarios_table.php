<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El precio. Una sola fila puede ganar en cada combinación.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PREGUNTA RESPONDE
 * ─────────────────────────────────────────────────────────────────────
 *
 *     precio(ítem, convenio, fecha, sede)
 *
 * Y tiene que responderla con UNA fila, siempre. Si dos filas pudieran
 * ganar a la vez, el precio dependería del `ORDER BY` — o sea, dos
 * pacientes atendidos el mismo día por lo mismo pagarían distinto según
 * el orden en que se insertaron los datos. Eso no se arregla con un
 * `first()` prolijo: se impide en la base.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS DOS NULOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * `convenio_id` nulo = **precio de lista**: vale para todo convenio que
 * no tenga fila propia. `sede_id` nulo = vale para todas las sedes. Es la
 * misma escalera de `margenes_objetivo`, y por la misma razón: lo
 * específico gana, lo general siempre responde.
 *
 * ⚠️ Y con el mismo cuidado: en SQL `NULL = NULL` no es verdadero, así
 * que la exclusión va sobre `COALESCE(columna, 0)`. Sin eso podrían
 * convivir dos precios de lista vigentes del mismo ítem, que es
 * exactamente el problema que esta tabla existe para evitar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PRECIO ES ANTES DEL ISV
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se guarda el precio limpio; el impuesto lo calcula la factura según el
 * `regimen_isv` del ítem. Guardar el precio final obligaría a recalcular
 * todo el catálogo el día que cambie la tasa, y el desglose de la factura
 * habría que sacarlo hacia atrás — que es de donde salen los centavos que
 * después no cuadran (§8.6.2).
 *
 * NUMERIC(14,4) y nunca float: cuatro decimales porque un precio unitario
 * de fracción —media ampolla, un mililitro— necesita más precisión que
 * los dos centavos que se muestran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifarios', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();

            /*
             * Nulo = precio de lista. Ver el encabezado.
             */
            $tabla->foreignId('convenio_id')->nullable()->constrained('convenios')->restrictOnDelete();
            $tabla->foreignId('sede_id')->nullable()->constrained('sedes')->restrictOnDelete();

            $tabla->decimal('precio', 14, 4);

            /*
             * Por qué ese precio. Cuando en 2028 alguien pregunte por qué
             * este producto se vendía así en marzo, la respuesta tiene que
             * estar acá y no en la memoria de nadie.
             */
            $tabla->string('motivo');

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE tarifarios
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

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

        /*
         * El resolutor entra siempre por ítem y filtra por fecha. Sin
         * este índice, cada línea de una factura de veinte renglones es un
         * seq scan sobre toda la tabla de precios.
         */
        DB::statement(
            'CREATE INDEX tarifarios_busqueda
             ON tarifarios (item_id, convenio_id, vigencia_desde)
             WHERE deleted_at IS NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        /*
         * Cero se permite y negativo no. Un ítem en cero es una cortesía
         * declarada —una muestra médica, un servicio que no se cobra— y
         * eso existe. Un precio negativo es el hospital pagándole al
         * paciente por atenderse.
         */
        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_precio_no_negativo
             CHECK (precio >= 0)'
        );

        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE tarifarios
             ADD CONSTRAINT tarifarios_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifarios');
    }
};
