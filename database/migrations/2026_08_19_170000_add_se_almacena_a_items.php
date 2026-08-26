<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «¿Se almacena?» pasa de deducirse a preguntarse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ ESTABA MAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta hoy, mover kardex era propiedad del TIPO: medicamento e insumo
 * sí, todo lo demás no. Eso alcanzaba para el noventa por ciento y
 * fallaba justo en los bordes que más ruido hacen:
 *
 *   · El insumo que se compra y se consume sin inventariar —el papel de
 *     la camilla, el gel del ecógrafo, el jabón—. Inventariado a la
 *     fuerza, aparece en cada conteo físico con diferencias que nadie
 *     puede explicar ni ajustar, y termina enseñándole a todo el mundo
 *     que las diferencias del conteo se ignoran. Ese hábito es el que
 *     después deja pasar un faltante de verdad.
 *
 *   · El ítem de tipo «otro» que sí se guarda en bodega y sí hay que
 *     contar, y que hoy no puede tener existencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UNA COLUMNA Y NO UN TIPO NUEVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Agregar «insumo no inventariado» al enum multiplica los tipos por dos
 * y obliga a decidir el ISV, el descuento de ley y la política de cargo
 * otra vez para cada mitad. Almacenarse o no es UNA pregunta ortogonal a
 * qué clase de cosa es: se contesta con un booleano.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL RESPALDO NO ES SOLO POR TIPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se marca `true` por tipo Y ADEMÁS todo lo que ya tiene kardex,
 * existencia o lote. Con el tipo solo alcanzaría hoy —solo medicamento e
 * insumo pudieron mover inventario— pero si alguna vez se cambió el tipo
 * de un ítem después de moverlo, la fila quedaría en `false` con stock
 * escrito debajo: existencia que ninguna pantalla vuelve a mostrar y que
 * ningún conteo vuelve a cuadrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->boolean('se_almacena')->default(false)->after('tipo');
        });

        DB::statement(
            "UPDATE items SET se_almacena = true WHERE tipo IN ('medicamento', 'insumo')"
        );

        DB::statement(
            'UPDATE items SET se_almacena = true
             WHERE id IN (SELECT DISTINCT item_id FROM movimientos_kardex)
                OR id IN (SELECT DISTINCT item_id FROM existencias)
                OR id IN (SELECT DISTINCT item_id FROM lotes)'
        );

        DB::statement(
            'CREATE INDEX items_se_almacena_index ON items (se_almacena) WHERE deleted_at IS NULL'
        );

        /*
         * Los dos CHECK que hablaban del tipo ahora hablan de la columna.
         * Se reemplazan y no se agregan al lado: dejar el viejo haría
         * imposible marcar como almacenado un ítem de tipo «otro», que es
         * justamente el caso que esto viene a habilitar.
         */
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_unidad_obligatoria_si_es_fisico');

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_unidad_obligatoria_si_se_almacena
             CHECK (se_almacena = false OR unidad_dispensacion_id IS NOT NULL)'
        );

        DB::statement('ALTER TABLE items DROP CONSTRAINT items_lote_solo_si_es_fisico');

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_lote_solo_si_se_almacena
             CHECK (requiere_lote = false OR se_almacena = true)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_lote_solo_si_se_almacena');
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_unidad_obligatoria_si_se_almacena');

        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_unidad_obligatoria_si_es_fisico
             CHECK (
                 tipo NOT IN ('medicamento', 'insumo')
                 OR unidad_dispensacion_id IS NOT NULL
             )"
        );

        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_lote_solo_si_es_fisico
             CHECK (
                 requiere_lote = false
                 OR tipo IN ('medicamento', 'insumo')
             )"
        );

        DB::statement('DROP INDEX IF EXISTS items_se_almacena_index');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn('se_almacena');
        });
    }
};
