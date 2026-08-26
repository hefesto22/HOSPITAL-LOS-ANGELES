<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las líneas de una plantilla de presupuesto (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PLANTILLA NO GUARDA PRECIOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Guarda ÍTEM y CANTIDAD. El precio se resuelve al cotizar, con el mismo
 * `ResolutorDePrecio(item, convenio, fecha, sede)` que cobra — y por eso
 * una plantilla cotizada para PALIG sale con los precios de PALIG sin
 * que exista una plantilla por aseguradora.
 *
 * Un precio acá sería la columna `precio` del catálogo entrando por la
 * puerta de atrás (ADR-0003).
 *
 * ─────────────────────────────────────────────────────────────────────
 * `opcional` NO ES DECORACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Separa «esto va siempre» de «esto puede que sí». La cajera desmarca lo
 * opcional en el caso concreto, y el reporte de varianza sabe que una
 * línea opcional que no se consumió no es un error de cotización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('plantilla_id')
                ->constrained('plantillas_presupuesto')
                ->cascadeOnDelete();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();

            $tabla->decimal('cantidad', 14, 4);

            $tabla->integer('orden')->default(0);

            $tabla->boolean('opcional')->default(false);

            /*
             * Para el que arma la plantilla, no para el paciente: «3 días
             * es lo típico, se ajusta si el cirujano dice otra cosa».
             */
            $tabla->string('nota', 200)->nullable();

            $tabla->timestamps();
        });

        /*
         * Un ítem una vez por plantilla. Repetirlo es siempre un error de
         * captura: lo que se quería era subir la cantidad, y dos líneas
         * del mismo ítem cotizan doble sin que nadie lo note en un papel
         * de veintidós renglones.
         */
        DB::statement(
            'CREATE UNIQUE INDEX plantilla_lineas_item_unico ON plantilla_lineas (plantilla_id, item_id)'
        );

        DB::statement(
            'CREATE INDEX plantilla_lineas_orden ON plantilla_lineas (plantilla_id, orden)'
        );

        DB::statement(
            'ALTER TABLE plantilla_lineas ADD CONSTRAINT plantilla_lineas_cantidad_positiva
             CHECK (cantidad > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_lineas');
    }
};
