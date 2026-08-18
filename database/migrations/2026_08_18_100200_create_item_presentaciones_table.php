<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presentaciones de compra — cómo viene el producto del proveedor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ES UNA TABLA Y NO TRES COLUMNAS EN `items`
 * ─────────────────────────────────────────────────────────────────────
 *
 * El §3 del documento de dominio lo plantea como
 * `unidad_compra + factor + unidad_dispensacion` sobre el producto:
 * "caja de Nantium: se compra la caja de 100 ampollas, se dispensa la
 * ampolla". Eso alcanza mientras el producto venga de una sola forma.
 *
 * No viene de una sola forma. El mismo medicamento se compra en caja de
 * 100 a un proveedor y en caja de 50 a otro, o en blíster suelto cuando
 * hay desabasto. Con una única equivalencia en el ítem, la segunda compra
 * obliga a convertir a mano — y ahí está el error que el propio documento
 * describe: **promediar caja contra unidad da un costo mil veces mayor y
 * nadie lo nota hasta el cierre.**
 *
 * Con esta tabla, quien recibe la compra elige "CAJA X 50" de una lista y
 * el factor lo pone el sistema. El kardex sigue siendo uno solo y en la
 * unidad de dispensación del ítem (§3: "el kardex se lleva SIEMPRE en la
 * unidad mínima de dispensación").
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL FACTOR PUEDE CRUZAR MAGNITUDES, Y ESTÁ BIEN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un FRASCO (conteo) que contiene 100 ML (volumen) es factor 100 entre
 * dos magnitudes distintas. Es el caso normal de una farmacia. Por eso
 * `MagnitudDeMedida` agrupa y advierte, pero no restringe.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CÓDIGO DE BARRAS VA ACÁ, NO EN EL ÍTEM
 * ─────────────────────────────────────────────────────────────────────
 *
 * El código de barras del fabricante identifica la CAJA, no la ampolla.
 * Ponerlo en el ítem obliga a elegir cuál de las presentaciones se lleva
 * el código, y hace imposible escanear la caja en recepción para saber
 * cuántas unidades entraron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_presentaciones', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')
                ->constrained('items')
                ->cascadeOnDelete();

            $tabla->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $tabla->string('nombre', 80);

            /*
             * Cuántas unidades de DISPENSACIÓN del ítem contiene esta
             * presentación. NUMERIC(14,4), no entero: hay presentaciones
             * que contienen fracciones (un frasco de 2.5 ml).
             */
            $tabla->decimal('unidades_por_presentacion', 14, 4);

            $tabla->string('codigo_barras', 50)->nullable();

            /*
             * La que propone el formulario de compra. Una sola por ítem,
             * y lo impone un índice único parcial más abajo: dos "por
             * defecto" significan que la que gana depende del ORDER BY.
             */
            $tabla->boolean('es_predeterminada')->default(false);

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index('item_id', 'item_presentaciones_item_index');
        });

        /*
         * Dos presentaciones idénticas —misma unidad, mismo contenido—
         * son la misma presentación cargada dos veces. Distinguirlas
         * después es imposible, y quien recibe la compra elige a ciegas.
         */
        DB::statement(
            'CREATE UNIQUE INDEX item_presentaciones_sin_repetir
             ON item_presentaciones (item_id, unidad_id, unidades_por_presentacion)
             WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX item_presentaciones_una_predeterminada
             ON item_presentaciones (item_id)
             WHERE es_predeterminada = true AND deleted_at IS NULL'
        );

        /*
         * El código de barras es único en TODO el catálogo, no dentro del
         * ítem: el lector no sabe qué ítem está leyendo — eso es
         * precisamente lo que va a averiguar.
         */
        DB::statement(
            'CREATE UNIQUE INDEX item_presentaciones_codigo_barras_unique
             ON item_presentaciones (codigo_barras)
             WHERE codigo_barras IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE item_presentaciones
             ADD CONSTRAINT item_presentaciones_contenido_positivo
             CHECK (unidades_por_presentacion > 0)'
        );

        DB::statement(
            'ALTER TABLE item_presentaciones
             ADD CONSTRAINT item_presentaciones_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE item_presentaciones
             ADD CONSTRAINT item_presentaciones_nombre_no_vacio
             CHECK (length(btrim(nombre)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('item_presentaciones');
    }
};
