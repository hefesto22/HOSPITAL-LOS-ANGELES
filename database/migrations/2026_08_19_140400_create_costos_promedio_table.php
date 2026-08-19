<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto cuesta hoy una unidad de cada ítem, en cada almacén.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PROMEDIO PONDERADO, Y POR QUÉ NO EL ÚLTIMO PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada entrada mezcla lo que llega con lo que ya había, pesando por
 * cantidad:
 *
 *     nuevo = (existencia × costo_actual + entra × costo_de_lo_que_entra)
 *             ────────────────────────────────────────────────────────────
 *                            existencia + entra
 *
 * Usar el último precio de compra en su lugar es la trampa clásica: el
 * día que el proveedor sube 40 % una compra chica, TODO el inventario
 * viejo pasa a valer más, y de ahí sale un margen que nunca existió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR ALMACÉN, NO POR LOTE Y NO GLOBAL
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · **Por lote no**, porque el promedio ponderado es justamente lo que
 *     mezcla lotes: cuando se despacha no se sabe —ni importa— de qué
 *     compra salió cada tableta.
 *   · **Global tampoco**, porque dos sedes que le compran al mismo
 *     proveedor a precios distintos no comparten costo. El de cada
 *     estante es el suyo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO ES UN SALDO, NO LA VERDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * La verdad son las líneas de recepción, que guardan cada costo con su
 * fecha. Esta tabla es ese cálculo ya hecho para no recorrer dos años de
 * compras cada vez que alguien pregunta cuánto vale el inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costos_promedio', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            /*
             * Seis decimales, igual que en la línea de recepción: una
             * tableta que sale de dividir L 1.000 entre 3 no cabe en
             * cuatro sin perder plata al multiplicar de vuelta.
             */
            $tabla->decimal('costo_unitario', 14, 6)->default(0);

            /*
             * La existencia con la que se calculó el promedio vigente.
             * No es lo mismo que el saldo de `existencias` —ese cambia
             * con cada dispensación y este no— y guardarla es lo que
             * permite auditar el cálculo: con estas dos columnas más la
             * entrada nueva, el promedio siguiente se puede rehacer a
             * mano en una servilleta.
             */
            $tabla->decimal('cantidad_base', 14, 4)->default(0);

            $tabla->timestampTz('actualizado_en')->nullable();

            $tabla->timestamps();
        });

        /*
         * Una sola fila por ítem y almacén. Dos serían dos costos
         * distintos para el mismo estante, y cuál gana dependería del
         * ORDER BY.
         */
        DB::statement(
            'CREATE UNIQUE INDEX costos_promedio_unico
             ON costos_promedio (item_id, almacen_id)'
        );

        DB::statement(
            'ALTER TABLE costos_promedio
             ADD CONSTRAINT costos_promedio_no_negativo
             CHECK (costo_unitario >= 0 AND cantidad_base >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('costos_promedio');
    }
};
