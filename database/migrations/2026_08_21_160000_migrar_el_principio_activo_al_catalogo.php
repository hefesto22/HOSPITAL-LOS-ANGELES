<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LO QUE YA ESTABA ESCRITO A MANO PASA AL CATÁLOGO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * Y `items.principio_activo` NO SE BORRA: PASA A SER DERIVADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Esa columna alimenta `items.nombre_busqueda`, que es una columna
 * GENERADA con su índice de trigramas encima — es lo que hace que
 * buscar «paracetamol» en el mostrador encuentre el acetaminofén.
 * Quitarla obligaría a recrear la generada y su índice, y a perder esa
 * búsqueda mientras tanto.
 *
 * Así que se queda, pero deja de escribirse a mano: de ahora en más la
 * llena el formulario con los nombres de los principios elegidos **y sus
 * sinónimos**. O sea que el catálogo mejora la búsqueda del ítem sin
 * tocar una sola línea del buscador.
 *
 * ⚠️ Idempotente. Solo crea lo que falta y solo vincula lo que no está
 * vinculado, así que volver a correrla no duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Se normaliza al comparar —mayúsculas y sin espacios de más—
         * para que «Acetaminofén», «ACETAMINOFEN » y «acetaminofen» se
         * conviertan en UNA fila y no en tres. Es justamente el desorden
         * que el catálogo viene a terminar.
         */
        $nombres = DB::table('items')
            ->whereNotNull('principio_activo')
            ->whereRaw('length(btrim(principio_activo)) >= 3')
            ->selectRaw('DISTINCT upper(btrim(principio_activo)) AS nombre')
            ->pluck('nombre')
            ->all();

        if ($nombres === []) {
            return;
        }

        $siguiente = 1;

        foreach ($nombres as $nombre) {
            $yaEsta = DB::table('principios_activos')->where('nombre', $nombre)->value('id');

            if ($yaEsta === null) {
                /*
                 * El código se busca libre en vez de contarse: si la
                 * migración corre dos veces, o alguien ya creó un PA a
                 * mano, el contador ciego chocaría contra el índice único.
                 */
                while (DB::table('principios_activos')
                    ->where('codigo', sprintf('PA-%04d', $siguiente))
                    ->exists()) {
                    $siguiente++;
                }

                DB::table('principios_activos')->insert([
                    'codigo'         => sprintf('PA-%04d', $siguiente),
                    'nombre'         => $nombre,
                    'vigencia_desde' => '2026-01-01',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                $siguiente++;
            }
        }

        /*
         * Y se vincula cada producto con el suyo. `NOT EXISTS` en vez de
         * `ON CONFLICT`: dice lo mismo y se lee sin saber qué índice hay
         * debajo.
         */
        DB::statement(
            'INSERT INTO item_principio_activo (item_id, principio_activo_id, created_at, updated_at)
             SELECT i.id, p.id, now(), now()
             FROM items i
             JOIN principios_activos p ON p.nombre = upper(btrim(i.principio_activo))
             WHERE i.principio_activo IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM item_principio_activo x
                   WHERE x.item_id = i.id AND x.principio_activo_id = p.id
               )'
        );
    }

    /**
     * No se deshace: el texto original sigue en `items.principio_activo`,
     * intacto. Lo que esta migración creó son filas de catálogo y
     * vínculos, y borrarlos automáticamente al revertir se llevaría por
     * delante los que alguien haya agregado a mano después.
     */
    public function down(): void {}
};
