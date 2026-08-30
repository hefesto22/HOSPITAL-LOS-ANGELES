<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le cobra a este hospital el laboratorio de afuera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES `CostoPromedio`
 * ─────────────────────────────────────────────────────────────────────
 *
 * El costo promedio que ya existe es de INVENTARIO: vive por (ítem,
 * almacén), se mueve con cada compra y sale del kardex. Un examen no
 * entra a ningún estante y no tiene kardex — el costo acá es un precio
 * de lista de un tercero, un dato del acuerdo con ese laboratorio, no
 * un promedio de nada.
 *
 * Meterlo en la tabla de costos habría obligado a inventarle un almacén
 * a algo que no se guarda, y a que cada consulta de valor de inventario
 * tuviera que aprender a excluirlo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ES LO ÚNICO QUE HACE LEGIBLE EL MARGEN
 * ─────────────────────────────────────────────────────────────────────
 *
 * En un examen que se hace adentro, todo lo que se cobra queda en el
 * hospital. En uno que se manda afuera, la mayor parte se va. Sin este
 * número, el reporte de ingresos por laboratorio suma peras con manzanas
 * y dirección lee una utilidad que no existe.
 *
 * `NUMERIC(14,4)` con la misma escala que el resto de los costos del
 * §8.6.2 —cuatro decimales, nunca float—, y nulo cuando el ítem se hace
 * adentro. Nulo significa «no aplica», que no es lo mismo que cero: cero
 * sería «me lo hacen gratis», y eso pasa de verdad con algunos convenios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->decimal('costo_referencia', 14, 4)
                ->nullable()
                ->after('regimen_isv')
                ->comment('Lo que cobra el tercero que presta el servicio. Nulo = se hace adentro.');
        });

        /*
         * No puede ser negativo. Un costo negativo no es un descuento:
         * es un signo invertido al teclear, y se lee como que el
         * laboratorio de afuera le paga al hospital por mandarle
         * pacientes — que invierte el margen del reporte entero sin dar
         * ningún error.
         */
        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_costo_referencia_no_negativo
             CHECK (costo_referencia IS NULL OR costo_referencia >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_costo_referencia_no_negativo');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn('costo_referencia');
        });
    }
};
