<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El lote del fabricante — mismo producto, distinto vencimiento.
 *
 * ─────────────────────────────────────────────────────────────────────
 * VEINTE CAJAS, DOS VENCIMIENTOS, UN SOLO ÍTEM
 * ─────────────────────────────────────────────────────────────────────
 *
 * Diez cajas que vencen el 1 de septiembre y diez el 1 de octubre **no
 * son dos productos**: son un ítem con dos lotes. Duplicar el ítem
 * rompería cuatro cosas a la vez —la búsqueda devolvería dos resultados
 * idénticos, el precio habría que mantenerlo dos veces, todo reporte por
 * producto quedaría partido, y el detector de duplicados del MPI
 * empezaría a marcarlos— y encima el médico receta «acetaminofén 500 mg»,
 * no «acetaminofén del lote que vence en octubre».
 *
 * El vencimiento no es una propiedad del producto. Es un hecho de **esas
 * cajas concretas**, y por eso vive acá.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL LOTE ES DEL FABRICANTE, NO DE LA BODEGA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Esta tabla NO lleva `almacen_id`. Un mismo lote puede estar repartido
 * entre la bodega central y la farmacia, y su fecha de vencimiento es la
 * misma en las dos: la puso el laboratorio que lo fabricó.
 *
 * Cuánto hay de cada lote en cada almacén es otra tabla, `existencias`.
 * Mezclarlas dejaría que un traslado creara una segunda fila del mismo
 * lote con otra fecha de vencimiento tecleada a mano, y nada lo
 * impediría.
 *
 * ⚠️ `fecha_vencimiento` es NULLABLE a propósito. Casi todo lo que lleva
 * lote vence, pero hay excepciones reales —material que se rastrea por
 * número de serie y no caduca— y forzarla obligaría a inventar una fecha.
 * El FEFO los ordena al final con `NULLS LAST`; que un medicamento la
 * tenga lo exige el servicio, no la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();

            /*
             * El número tal como viene impreso en la caja. No se
             * normaliza a mayúsculas: los laboratorios usan mayúsculas,
             * minúsculas y guiones, y «AB-123» y «ab-123» pueden ser dos
             * lotes distintos del mismo proveedor.
             */
            $tabla->string('numero', 60);

            $tabla->date('fecha_vencimiento')->nullable();
            $tabla->date('fecha_fabricacion')->nullable();

            /*
             * Registro sanitario ARSA del lote. Va acá y no en el ítem
             * porque se renueva, y una inspección pregunta por el del
             * lote que estaba en el estante ese día.
             */
            $tabla->string('registro_sanitario', 60)->nullable();

            $tabla->string('proveedor', 120)->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * Único por ítem, no global: dos laboratorios distintos pueden
         * usar el mismo número de lote para productos distintos, y eso no
         * es un error de nadie.
         *
         * Parcial, como siempre: un lote dado de baja no quema el número.
         */
        DB::statement(
            'CREATE UNIQUE INDEX lotes_unico_por_item
             ON lotes (item_id, numero)
             WHERE deleted_at IS NULL'
        );

        /*
         * FEFO entra por acá: «de este ítem, el que vence primero». Sin
         * el índice, cada dispensación recorre todos los lotes históricos
         * del producto.
         */
        DB::statement(
            'CREATE INDEX lotes_por_vencimiento
             ON lotes (item_id, fecha_vencimiento)
             WHERE deleted_at IS NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE lotes
             ADD CONSTRAINT lotes_numero_no_vacio
             CHECK (length(btrim(numero)) >= 1)'
        );

        /*
         * Un lote no puede vencer antes de fabricarse. Suena obvio; es el
         * dedazo más común al teclear dos fechas seguidas.
         */
        DB::statement(
            'ALTER TABLE lotes
             ADD CONSTRAINT lotes_fechas_coherentes
             CHECK (
                 fecha_fabricacion IS NULL
                 OR fecha_vencimiento IS NULL
                 OR fecha_vencimiento >= fecha_fabricacion
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
