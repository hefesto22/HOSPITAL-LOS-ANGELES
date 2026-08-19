<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que traía el camión, línea por línea.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CASO QUE ESTA TABLA TIENE QUE RESOLVER
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Llegaron 100 cajas de acetaminofén de 100 tabletas a L 1.000 la caja,
 * y 50 cajas del mismo acetaminofén de 50 tabletas a L 500 la caja.»
 *
 * Son DOS líneas del MISMO ítem con presentaciones distintas:
 *
 *     100 × 100 = 10.000 tabletas · L 100.000 → L 10,00 la tableta
 *      50 ×  50 =  2.500 tabletas · L  25.000 → L 10,00 la tableta
 *     ───────────────────────────────────────────────────────────
 *                 12.500 tabletas · L 125.000 → L 10,00 ponderado
 *
 * El kardex recibe 12.500 tabletas, no 150 cajas. La conversión ocurre
 * acá y una sola vez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL COSTO YA LLEVA EL IMPUESTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `costo_por_presentacion` es lo que costó la caja, impuesto incluido.
 * No hay casilla de ISV y no debe haberla: los servicios de salud son
 * exentos, así que el impuesto pagado en la compra no se acredita y por
 * lo tanto es costo. Separarlo acá obligaría a sumarlo de vuelta para
 * costear, con una vuelta de redondeo de regalo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS COLUMNAS CONGELADAS, Y ES LO MÁS IMPORTANTE DE ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * `unidades_por_presentacion` y `costo_unitario` son COPIAS del momento
 * de recibir. El día que el laboratorio cambie a caja de 120 y alguien
 * corrija el catálogo, esta línea tiene que seguir diciendo 100 — es lo
 * que llegó de verdad, y es lo que explica un movimiento del kardex que
 * ya no se puede editar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepcion_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('recepcion_id')->constrained('recepciones')->cascadeOnDelete();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();

            /*
             * Nula cuando se recibe directamente en unidad de
             * dispensación: hay insumos que llegan por unidad y no vale
             * la pena inventarles una presentación de una.
             */
            $tabla->foreignId('item_presentacion_id')
                ->nullable()
                ->constrained('item_presentaciones')
                ->restrictOnDelete();

            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();

            $tabla->decimal('cantidad_presentacion', 14, 4);
            $tabla->decimal('unidades_por_presentacion', 14, 4);

            /*
             * La calcula PostgreSQL. Así no existe la posibilidad de que
             * un import o un bug dejen una línea donde 100 cajas de 100
             * son 9.800 tabletas.
             */
            $tabla->decimal('cantidad_dispensacion', 14, 4)
                ->storedAs('cantidad_presentacion * unidades_por_presentacion');

            /*
             * Lo que costó la CAJA, con impuesto adentro.
             */
            $tabla->decimal('costo_por_presentacion', 14, 4);

            /*
             * Lo que costó la TABLETA. Se escribe desde el servicio con
             * bcmath y no como columna generada: la división por
             * `unidades_por_presentacion` sería una división por cero si
             * alguien esquivara el CHECK, y un error de PostgreSQL sin
             * contexto es peor que un error del dominio.
             *
             * Seis decimales y no cuatro: dividir L 1.000 entre 3 unidades
             * a cuatro decimales pierde plata que después no cuadra al
             * multiplicar de vuelta.
             */
            $tabla->decimal('costo_unitario', 14, 6);

            $tabla->string('numero_lote', 60)->nullable();
            $tabla->date('fecha_vencimiento')->nullable();

            $tabla->string('notas', 200)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'CREATE INDEX recepcion_lineas_por_recepcion
             ON recepcion_lineas (recepcion_id)'
        );

        DB::statement(
            'CREATE INDEX recepcion_lineas_por_item
             ON recepcion_lineas (item_id, created_at DESC)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE recepcion_lineas
             ADD CONSTRAINT recepcion_lineas_cantidad_positiva
             CHECK (cantidad_presentacion > 0)'
        );

        /*
         * Cero rompería la conversión y negativo convertiría una entrada
         * en una salida.
         */
        DB::statement(
            'ALTER TABLE recepcion_lineas
             ADD CONSTRAINT recepcion_lineas_contenido_positivo
             CHECK (unidades_por_presentacion > 0)'
        );

        /*
         * Cero SÍ se permite: una donación entra al estante sin costo, y
         * obligarla a un número inventado ensuciaría el promedio.
         */
        DB::statement(
            'ALTER TABLE recepcion_lineas
             ADD CONSTRAINT recepcion_lineas_costo_no_negativo
             CHECK (costo_por_presentacion >= 0 AND costo_unitario >= 0)'
        );

        DB::statement(
            'ALTER TABLE recepcion_lineas
             ADD CONSTRAINT recepcion_lineas_lote_no_vacio
             CHECK (numero_lote IS NULL OR length(btrim(numero_lote)) > 0)'
        );

        /*
         * Un vencimiento sin número de lote no sirve: no hay cómo saber
         * cuál es el frasco que vence cuando se va a buscar al estante.
         */
        DB::statement(
            'ALTER TABLE recepcion_lineas
             ADD CONSTRAINT recepcion_lineas_vencimiento_exige_lote
             CHECK (fecha_vencimiento IS NULL OR numero_lote IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_lineas');
    }
};
