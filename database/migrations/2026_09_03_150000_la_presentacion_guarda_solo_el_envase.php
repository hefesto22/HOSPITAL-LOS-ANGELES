<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UNA SOLA CONVENCIÓN EN `item_presentaciones.nombre`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio (3-sep-2026): «que sea la base el nombre y otro
 * campo para agregar la presentación como caja 100 tabletas, y así el
 * nombre base no se cambie».
 *
 * Hasta hoy la misma columna guardaba dos cosas distintas según por
 * dónde hubiera entrado la fila:
 *
 *   · el formulario:  ACETAMINOFEN 500 MG TABLETA / CAJA X 100 TABLETAS
 *   · el seeder:      CAJA X 100 TABLETAS
 *
 * Dos convenciones para una columna no es un problema estético. El
 * nombre del producto quedaba escrito DOS veces —en la ficha y adentro
 * de cada presentación— y las dos copias se separan el día que alguien
 * corrige la ficha: se renombra el producto y las presentaciones siguen
 * diciendo el nombre viejo, en el desplegable de la compra y en la
 * etiqueta impresa. Y cada pantalla que la mostraba traía su propio
 * recorte con `Str::after(' / ')`, tres en total, ninguno igual al otro.
 *
 * Acá se corta el prefijo y queda SOLO el envase. La base se lee del
 * producto al mostrarla (`ItemPresentacion::nombreCompleto()`), así que
 * hay una sola copia y no se puede desincronizar.
 *
 * ⚠️ Se corta el prefijo QUE COINCIDE con el nombre del producto, y no
 * «todo lo que esté antes de la primera pleca». Hay nombres de
 * medicamento que llevan pleca adentro —«DICLOFENACO 75 MG / 3 ML
 * AMPOLLA»— y cortar por la primera dejaría «3 ML AMPOLLA / CAJA X 5»,
 * que es peor que lo que había.
 *
 * ⚠️ La fila cuyo prefijo YA no coincide con la ficha —porque el
 * producto se renombró después— se deja intacta a propósito. Cortarle
 * un prefijo que no reconocemos es adivinar sobre un dato que después
 * se imprime en una etiqueta. `envase()` sigue leyéndolas bien, y se
 * corrigen abriendo la presentación.
 *
 * ⚠️ Sin `down()` que reponga la pleca. Volver a escribir el nombre del
 * producto adentro de cada presentación es exactamente el defecto que
 * esto arregla; el `down()` que lo repusiera sería un bug con permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `position()` sobre el nombre del ítem, y no un LIKE con
         * comodines: el nombre del producto puede traer `%` o `_`
         * —«LIDOCAINA 2 % FRASCO 50 ML» lo trae— y en un LIKE esos dos
         * caracteres son comodines, no letras.
         */
        $tocadas = DB::update(<<<'SQL'
            UPDATE item_presentaciones AS p
               SET nombre = btrim(substr(p.nombre, length(i.nombre) + 4))
              FROM items AS i
             WHERE i.id = p.item_id
               AND position(i.nombre || ' / ' IN p.nombre) = 1
               AND btrim(substr(p.nombre, length(i.nombre) + 4)) <> ''
        SQL);

        /*
         * Lo que queda con pleca después de esto es lo del segundo aviso
         * de arriba: presentaciones cuyo producto se renombró. Se cuentan
         * para que quede en el log de la migración y no se descubran en
         * una etiqueta.
         */
        $huerfanas = DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM item_presentaciones AS p
              JOIN items AS i ON i.id = p.item_id
             WHERE p.nombre LIKE '%' || chr(32) || '/' || chr(32) || '%'
               AND position(i.nombre || ' / ' IN p.nombre) <> 1
        SQL);

        if (is_numeric($huerfanas) && (int) $huerfanas > 0) {
            echo PHP_EOL.'  ⚠️  '.$huerfanas.' presentación(es) con pleca que no coincide con la ficha: '
                .'el producto se renombró después. Se dejaron como estaban.'.PHP_EOL;
        }

        echo '  ✓ '.$tocadas.' presentación(es) guardan ahora solo el envase.'.PHP_EOL;
    }

    public function down(): void
    {
        // A propósito, vacío. Ver el encabezado.
    }
};
