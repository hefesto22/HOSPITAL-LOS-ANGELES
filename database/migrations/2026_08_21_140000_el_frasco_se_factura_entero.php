<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HAY MEDICAMENTOS QUE SE COBRAN POR FRASCO, NO POR MILILITRO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA (reunión del 21-ago-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «15 ml cada 6 horas por 2 días» son 120 ml, y esos 120 ml salen como
 * UN frasco de 120 o DOS de 60 — enteros. Si la receta pedía 100, el
 * paciente igual se lleva el frasco completo: **lo pagó, y él decide qué
 * hace con lo que sobre.**
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ LA MARCA ES DEL PRODUCTO Y NO DE CADA DESPACHO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es lo contrario de la regla que ya rige para el resto del inventario,
 * donde el frasco se comparte y el sobrante queda destapado para el
 * próximo paciente. Las dos son correctas — para productos distintos.
 *
 * Lo que NO puede pasar es que convivan sobre el mismo producto: si al
 * primero se le cobra el frasco entero y al siguiente se le cobran los
 * mililitros que sobraron, **la misma gota se cobró dos veces**. Con la
 * marca en el producto eso es imposible por construcción; con una
 * decisión por despacho, dependería de que nadie se equivoque una vez.
 *
 * ⚠️ Solo tiene sentido en lo que se almacena. Un honorario no tiene
 * envase, y el CHECK lo impide para que la marca no se convierta en una
 * casilla que alguien tilda «por si acaso».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->boolean('factura_envase_entero')
                ->default(false)
                ->after('se_almacena');
        });

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_envase_entero_solo_si_se_almacena
             CHECK (factura_envase_entero = false OR se_almacena = true)'
        );

        /*
         * Índice parcial: la pregunta que se hace es «¿cuáles se facturan
         * por envase?», nunca «¿cuáles no?». Indexar solo los verdaderos
         * mantiene el índice del tamaño de la respuesta y no del catálogo.
         */
        DB::statement(
            'CREATE INDEX items_factura_envase_entero
             ON items (id)
             WHERE factura_envase_entero = true AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS items_factura_envase_entero');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_envase_entero_solo_si_se_almacena');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn('factura_envase_entero');
        });
    }
};
