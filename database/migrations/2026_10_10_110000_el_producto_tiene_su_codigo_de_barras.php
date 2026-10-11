<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CADA PRODUCTO TIENE SU CÓDIGO DE BARRAS, Y LO HEREDAN SUS ENVASES
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio (10-oct-2026): «en identificación se debería pedir
 * el código de barras del producto, y ese heredarse a cada
 * presentación». El acetaminofén de la marca X y el de la marca Y son
 * dos productos, y la caja de cada uno trae un código de fábrica
 * distinto: ese código es el que dice cuál de los dos se tiene en la
 * mano.
 *
 * Hasta hoy el código vivía solo en la presentación, así que había que
 * cargarlo envase por envase —y el blíster, que casi nunca trae código
 * propio, quedaba con un triángulo amarillo de «no se puede escanear».
 *
 * «Heredar» NO es copiar el código a cada presentación. Copiado, el
 * mismo código estaría en la caja y en el blíster, y al escanearlo en
 * la recepción no habría forma de saber si entraron 100 tabletas o 10.
 * Heredar es que escanear el código del producto encuentra el producto
 * en cualquiera de sus envases; la presentación lleva un código propio
 * solo cuando ese envase trae uno distinto.
 *
 * ⚠️ El índice NO excluye los borrados, igual que el de presentaciones:
 * un código impreso en una caja es para siempre, y reasignarlo haría que
 * esa caja escaneara como otro producto.
 *
 * ⚠️ Que un código no esté a la vez en un producto y en un envase no lo
 * puede vigilar un índice —son dos tablas—. Lo vigilan los dos
 * formularios, con el mensaje puesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->string('codigo_barras', 50)->nullable()->after('codigo');
        });

        DB::statement(
            'CREATE UNIQUE INDEX items_codigo_barras_unico ON items (codigo_barras) '
            .'WHERE codigo_barras IS NOT NULL'
        );

        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT items_codigo_barras_no_vacio '
            .'CHECK (codigo_barras IS NULL OR length(btrim(codigo_barras)) > 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_codigo_barras_no_vacio');
        DB::statement('DROP INDEX IF EXISTS items_codigo_barras_unico');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn('codigo_barras');
        });
    }
};
