<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los porcentajes de descuento del adulto mayor, con vigencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO ES UNA TABLA Y NO UNA CONSTANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * De estos números sale el precio de lista de TODO el catálogo:
 *
 *     precio_lista = costo × (1 + margen) / (1 − descuento_máximo)
 *
 * Y son ley, no política comercial: Artículo 30 del Decreto Legislativo
 * 199-2006. Ya se reformó una vez el marco y va a volver a pasar.
 *
 * Con los porcentajes quemados en el código, cumplir con una reforma
 * exige desplegar. Peor: una factura de 2027 que se reimprime en 2029
 * saldría con el porcentaje de 2029, y esa factura ya se le cobró a
 * alguien. **La pregunta que esta tabla contesta no es "cuánto se
 * descuenta", es "cuánto se descontaba el día del servicio".**
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL NO-TRASLAPE LO IMPONE LA BASE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dos filas vigentes el mismo día para la misma categoría y el mismo
 * rango hacen que el descuento dependa del `ORDER BY`. Es el mismo
 * problema que el §8.5 describe para el tarifario, y se resuelve igual:
 * una restricción de exclusión sobre el rango de fechas.
 *
 * La columna `vigencia` es un `daterange` GENERADO a partir de
 * `vigencia_desde` y `vigencia_hasta`. Se hace así —y no guardando un
 * rango directamente— para que la tabla conserve las dos columnas de
 * fecha que usa el resto del proyecto y que Eloquent castea sin
 * ceremonia, sin renunciar a que Postgres verifique el traslape.
 *
 * Con `[]` inclusivo y `vigencia_hasta` nula el rango queda abierto
 * hacia adelante, que es el caso normal: la ley rige hasta que otra la
 * cambie.
 *
 * ⚠️ Se usa `EXCLUDE USING gist` y no el `UNIQUE ... WITHOUT OVERLAPS`
 * que menciona el §8.5. Hacen lo mismo; el segundo es azúcar de
 * PostgreSQL 18 y este funciona también en 16 y 17. Importa el día que
 * una clínica replique el sistema sobre una versión anterior.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO HAY FILAS PARA LA CUARTA EDAD, Y ES CORRECTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El Decreto 45-2025 reformó el Artículo 31 —servicios básicos—, no el
 * 30. En salud el único umbral es 60 años. Ver
 * `docs/dominio-inventario-y-precios.md` §4.4.
 *
 * Un paciente de 80 igual recibe descuento: el resolutor sube por la
 * escalera de rangos y toma el mejor que encuentre, así que la cuarta
 * edad hereda lo de la tercera. El día que el Congreso la extienda a
 * salud, es un INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descuentos_legales', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('categoria_legal', 40);
            $tabla->string('rango_edad', 20);

            /*
             * Fracción, no porcentaje: 0.2500 es 25 %. Cuatro decimales
             * porque una reforma puede fijar 12.5 % y redondearlo a 13 %
             * sería cobrarle de más a un adulto mayor.
             */
            $tabla->decimal('porcentaje', 5, 4);

            /*
             * De dónde sale el número. No es documentación: es lo que hay
             * que poder mostrar cuando llega una denuncia a la línea 115.
             */
            $tabla->string('fundamento');

            /*
             * Art. 34: el descuento en medicamentos exige receta original
             * firmada y sellada. Es propiedad del DESCUENTO, no del ítem —
             * una reforma podría quitar el requisito sin tocar el catálogo.
             */
            $tabla->boolean('exige_receta')->default(false);

            $tabla->text('nota')->nullable();

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * `daterange(desde, hasta, '[]')` es IMMUTABLE, que es el
         * requisito para una columna generada. Con `hasta` nula el rango
         * queda abierto hacia adelante.
         */
        DB::statement(
            "ALTER TABLE descuentos_legales
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        DB::statement(
            'ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_sin_traslape
             EXCLUDE USING gist (categoria_legal WITH =, rango_edad WITH =, vigencia WITH &&)
             WHERE (deleted_at IS NULL)'
        );

        /*
         * Lo que se consulta en cada cargo: categoría + rango, filtrado
         * por la fecha del servicio. El índice GiST del EXCLUDE ya cubre
         * el `@>` sobre el rango.
         */
        DB::statement(
            'CREATE INDEX descuentos_legales_busqueda
             ON descuentos_legales (categoria_legal, rango_edad)
             WHERE deleted_at IS NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_porcentaje_es_fraccion
             CHECK (porcentaje >= 0 AND porcentaje <= 1)'
        );

        /*
         * El rango `normal` no lleva fila: no tener descuento no es un
         * descuento de cero, es la ausencia de derecho. Una fila con 0 %
         * para `normal` haría creer que alguien la decidió.
         */
        DB::statement(
            "ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_rango_con_derecho
             CHECK (rango_edad IN ('tercera', 'cuarta'))"
        );

        DB::statement(
            "ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_categoria_conocida
             CHECK (categoria_legal IN (
                 'servicio_hospitalario', 'medicamento_material_quirurgico',
                 'consulta_general', 'consulta_especializada',
                 'intervencion_quirurgica', 'odontologia_oftalmologia',
                 'radiologia_laboratorio', 'medicina_computarizada'
             ))"
        );

        /*
         * `sin_descuento_legal` no aparece arriba a propósito: no es una
         * categoría que lleve fila, es la que declara que el ítem está
         * fuera del Artículo 30.
         */

        DB::statement(
            'ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        /*
         * Diez caracteres no alcanzan para "ley" pero sí para "Art. 30
         * num. 6". Un porcentaje sin fundamento es un porcentaje que
         * nadie puede defender.
         */
        DB::statement(
            'ALTER TABLE descuentos_legales
             ADD CONSTRAINT descuentos_legales_fundamento_citado
             CHECK (length(btrim(fundamento)) >= 10)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('descuentos_legales');
    }
};
