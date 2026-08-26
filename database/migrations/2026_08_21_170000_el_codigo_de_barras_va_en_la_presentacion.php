<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL CÓDIGO DE BARRAS ES DE LA PRESENTACIÓN, NO DEL PRODUCTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «ACETAMINOFEN TABLETA 800 MG» es el nombre de un medicamento; no es
 * nada que se pueda tomar en la mano ni pegarle una etiqueta. Lo que
 * existe físicamente es la caja de 100 y el blíster de 12 — y son ellos
 * los que se escanean.
 *
 * La columna ya existía desde el día uno y se usaba para el EAN del
 * fabricante. Lo que cambia acá no es la columna, es que ahora también
 * puede llevar el código que imprime el hospital, y por eso pasa a ser
 * único: dos presentaciones con el mismo código hacen que el lector
 * devuelva la que el ORDER BY quiera, que es la peor forma de fallar
 * —silenciosa y distinta cada vez—.
 *
 * ⚠️ El índice NO excluye las borradas. Una presentación se borra del
 * sistema, pero la etiqueta ya impresa sigue pegada en una caja del
 * estante: reutilizar su código haría que esa caja escaneara como otra
 * cosa. Un código de barras, una vez impreso, es para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * El nombre de la presentación arranca con el del producto —el
         * formulario lo propone así— y en 80 caracteres no entra
         * «CLORHIDRATO DE AMBROXOL JARABE 15 MG/5 ML» más «CAJA X 12
         * FRASCOS». Se iguala al ancho del nombre del ítem para que
         * nunca sea el campo el que decida qué se puede escribir.
         */
        Schema::table('item_presentaciones', function (Blueprint $tabla): void {
            $tabla->string('nombre', 255)->change();
        });

        /*
         * `IF NOT EXISTS` porque el índice puede venir de antes con otro
         * nombre. Si ya está, esto no hace nada y no rompe la migración
         * de una base que ya se corrigió a mano.
         */
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS item_presentaciones_codigo_barras_unico '
            .'ON item_presentaciones (codigo_barras) '
            .'WHERE codigo_barras IS NOT NULL'
        );
    }

    /**
     * ⚠️ La vuelta atrás solo suelta el índice.
     *
     * Devolver el nombre a 80 caracteres truncaría los que ya se
     * cargaron largos, y una migración que borra datos al revertirse es
     * una trampa: se corre para deshacer un error y termina causando uno
     * peor. La columna se queda ancha, que no le hace mal a nadie.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS item_presentaciones_codigo_barras_unico');
    }
};
