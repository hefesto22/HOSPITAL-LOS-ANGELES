<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;

/**
 * Mantiene `items.principio_activo` al día con el catálogo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESA COLUMNA SIGUE EXISTIENDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Alimenta `items.nombre_busqueda`, que es una columna GENERADA por
 * Postgres con un índice de trigramas encima: es lo que hace que buscar
 * «paracetamol» en el mostrador encuentre el acetaminofén. Quitarla
 * obligaría a recrear la generada y su índice, y a perder esa búsqueda
 * mientras tanto.
 *
 * Así que se queda — pero deja de escribirse a mano. Acá se llena con los
 * nombres de los principios vinculados **y sus sinónimos**, así que el
 * catálogo termina mejorando la búsqueda del producto sin que haya que
 * tocar una línea del buscador: un antigripal empieza a aparecer buscando
 * «acetaminofén», «clorfenamina» o «paracetamol», y hoy no aparece con
 * ninguno de los tres.
 *
 * ⚠️ Vive en un servicio y no en el formulario porque una regla que solo
 * vive en el formulario no es una regla (§11). El día que un import o un
 * comando vinculen principios, llaman acá.
 */
final class SincronizadorDePrincipiosActivos
{
    /**
     * ⚠️ `saveQuietly`: `nombre_busqueda` la recalcula Postgres sola con
     * cada UPDATE, así que no hace falta ningún evento — y dispararlos
     * llamaría de vuelta a quien nos llamó.
     */
    public function actualizarElTextoDe(Item $item): void
    {
        $item->loadMissing('principiosActivos');

        $partes = [];

        foreach ($item->principiosActivos as $principio) {
            $partes[] = $principio->nombre;

            if (filled($principio->tambien_llamado)) {
                $partes[] = str_replace(',', ' ', (string) $principio->tambien_llamado);
            }
        }

        $texto = trim(preg_replace('/\s+/', ' ', implode(' ', $partes)) ?? '');

        $item->forceFill([
            'principio_activo' => $texto === '' ? null : mb_substr($texto, 0, 255),
        ])->saveQuietly();
    }
}
