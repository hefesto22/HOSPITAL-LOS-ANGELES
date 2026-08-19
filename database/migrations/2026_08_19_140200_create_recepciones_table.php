<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recepciones — lo que ENTRÓ al estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EXISTIR ES YA HABER MOVIDO EL KARDEX
 * ─────────────────────────────────────────────────────────────────────
 *
 * No hay estado «borrador». Una fila acá significa que los movimientos
 * ya están asentados y el costo promedio ya se recalculó, todo en la
 * misma transacción: si algo falla, no queda ni la recepción ni el
 * movimiento.
 *
 * Es a propósito. Quien recibe está parado frente al camión con el
 * teléfono en la mano; obligarlo a un segundo paso significa que la
 * mercadería está en el estante y el sistema todavía no la ve — y
 * cuando farmacia pide, el saldo miente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CONTROL NO SE PIERDE, SE CORRE PARA DESPUÉS
 * ─────────────────────────────────────────────────────────────────────
 *
 * `revisada_en` / `revisada_por` no bloquean nada: son la constancia de
 * que otra persona miró los números. Lo que da el control es el reporte
 * de «recepciones sin revisar», que es una pregunta que se puede hacer
 * todos los días.
 *
 * El candado de cuatro ojos que sí manda —quien revisa no puede ser
 * quien recibió— está abajo, en un CHECK.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ NO HAY IMPUESTOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El costo que se captura **ya lleva el impuesto adentro**: es lo que
 * costó la caja, punto. Los servicios de salud son exentos de ISV, así
 * que el impuesto pagado en las compras no se acredita y por lo tanto ES
 * costo. El desglose fiscal vive en `compras`, que es otra tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * A QUÉ ALMACÉN entró. El saldo y el costo promedio son por
             * almacén, así que esto no es un detalle administrativo:
             * equivocarlo descuadra dos estantes de una vez.
             */
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            /*
             * Nulo cuando no se sabe o no aplica: una donación anónima,
             * un traslado de otra sede. Saber de quién vino importa
             * cuando el laboratorio manda a retirar un lote del mercado.
             */
            $tabla->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();

            /*
             * Texto libre y a propósito: «Factura 000-001-01-00000657»,
             * «Remisión 4471», «Donación Cruz Roja». NO es una FK a
             * `compras`: la mercadería llega el lunes y la factura se
             * captura el viernes, y atarlas obligaría a esperar.
             */
            $tabla->string('referencia', 120)->nullable();

            $tabla->date('fecha_recepcion');

            $tabla->timestampTz('revisada_en')->nullable();
            $tabla->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'CREATE INDEX recepciones_por_almacen
             ON recepciones (almacen_id, fecha_recepcion DESC)'
        );

        /*
         * El índice que hace barata la pregunta de todos los días:
         * ¿cuáles faltan revisar? Es PARCIAL, así que ocupa lo que ocupan
         * las pendientes y no lo que ocupa el historial entero.
         */
        DB::statement(
            'CREATE INDEX recepciones_sin_revisar
             ON recepciones (fecha_recepcion DESC)
             WHERE revisada_en IS NULL AND deleted_at IS NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE recepciones
             ADD CONSTRAINT recepciones_revision_completa
             CHECK (
                 (revisada_en IS NULL AND revisada_por IS NULL)
                 OR (revisada_en IS NOT NULL AND revisada_por IS NOT NULL)
             )'
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * CUATRO OJOS: NO REVISA EL QUE RECIBIÓ
         * ─────────────────────────────────────────────────────────────
         *
         * Misma forma exacta que `fusiones_de_persona` usa para la doble
         * aprobación (§9.D4). Acá la revisión no bloquea la entrada
         * —para eso es rápida—, pero marcarla como revisada uno mismo
         * sería firmarse el propio trabajo, y entonces el reporte de
         * pendientes dejaría de significar algo.
         */
        DB::statement(
            'ALTER TABLE recepciones
             ADD CONSTRAINT recepciones_cuatro_ojos
             CHECK (revisada_por IS NULL OR revisada_por <> created_by)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recepciones');
    }
};
