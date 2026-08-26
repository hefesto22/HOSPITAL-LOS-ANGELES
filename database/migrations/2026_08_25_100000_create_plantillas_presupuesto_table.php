<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de presupuesto — la lista típica de una cirugía (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTA TABLA ES LA REPLICABILIDAD DEL MÓDULO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una apendicectomía se cotiza con las mismas ~22 líneas siempre: sala
 * de operaciones, honorarios, tres días de habitación, kit de sutura,
 * antibiótico, alimentación. Lo que cambia entre un caso y otro son las
 * CANTIDADES y algún medicamento, no la lista.
 *
 * Sin plantillas, la cajera escribe veintidós líneas a mano cada vez —y
 * en la práctica escribe cinco, cotiza de menos, y el excedente lo paga
 * el hospital en la discusión del egreso.
 *
 * Con plantillas, la clínica siguiente carga las suyas y cotiza sus
 * cirugías **sin que nadie escriba una migración** (§1.1).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN `sede_id`, IGUAL QUE `items` Y `categorias_item`
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lista de lo que lleva una apendicectomía es de la organización: la
 * misma cirugía se hace igual en las dos sedes. Lo que cambia por sede
 * es el PRECIO, y eso ya vive en `tarifarios` (ADR-0003).
 *
 * ─────────────────────────────────────────────────────────────────────
 * VIGENCIA, NO UN BOOLEANO `activa`
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.4: una plantilla retirada hoy tiene que seguir explicando por qué
 * el presupuesto de hace ocho meses tenía las líneas que tenía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_presupuesto', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('codigo', 30);
            $tabla->string('nombre', 150);
            $tabla->string('descripcion', 300)->nullable();

            /*
             * Cuántos días vale un presupuesto emitido desde esta
             * plantilla. Es dato y no constante porque una cirugía
             * electiva se cotiza a 30 días y una urgencia a 3 (§1.1).
             */
            $tabla->smallInteger('dias_vigencia')->default(15);

            /*
             * El colchón sugerido. NO se esconde inflando los precios de
             * las líneas: al cotizar se convierte en una línea visible
             * de tipo `holgura`. Mismo criterio con el que se descartó
             * el precio de lista inflado del adulto mayor.
             */
            $tabla->decimal('holgura_fraccion', 6, 4)->default(0);

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX plantillas_presupuesto_codigo_unico ON plantillas_presupuesto (codigo)'
        );

        DB::statement(
            'CREATE INDEX plantillas_presupuesto_vigentes
             ON plantillas_presupuesto (vigencia_desde, vigencia_hasta)'
        );

        DB::statement(
            'ALTER TABLE plantillas_presupuesto ADD CONSTRAINT plantillas_presupuesto_codigo_no_vacio
             CHECK (length(btrim(codigo)) >= 2)'
        );

        DB::statement(
            'ALTER TABLE plantillas_presupuesto ADD CONSTRAINT plantillas_presupuesto_dias_positivos
             CHECK (dias_vigencia > 0)'
        );

        /*
         * La holgura es una fracción, no un porcentaje ni un monto. El
         * tope de 0.5 no es purismo: una holgura del 60 % ya no es un
         * colchón, es una cotización inventada.
         */
        DB::statement(
            'ALTER TABLE plantillas_presupuesto ADD CONSTRAINT plantillas_presupuesto_holgura_razonable
             CHECK (holgura_fraccion >= 0 AND holgura_fraccion <= 0.5)'
        );

        DB::statement(
            'ALTER TABLE plantillas_presupuesto ADD CONSTRAINT plantillas_presupuesto_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_presupuesto');
    }
};
