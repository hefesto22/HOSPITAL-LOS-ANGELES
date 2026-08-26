<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo único de ítems facturables (§8.4, ADR-0003).
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNO SOLO, PARA TODOS LOS MÓDULOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Farmacia, laboratorio, imágenes, quirófano y hospitalización cobran
 * contra esta tabla. Un catálogo por módulo obliga a resolver cuatro
 * veces —y de cuatro formas distintas— la unidad, el régimen de ISV, la
 * política de cobro y el mapeo contable; y hace imposible la única
 * pregunta que el hospital hace cada mes: qué se le cobró a este paciente
 * y cuánto costó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO HAY COLUMNA `precio`, Y NO ES UN OLVIDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.5, regla innegociable: el precio es una función
 * `precio(item, convenio, fecha_del_servicio, sede)` resuelta por
 * vigencia, no un atributo del ítem. Con precio-columna, renegociar con
 * una aseguradora obliga a duplicar el catálogo, y en seis meses hay
 * cuatro catálogos paralelos y ninguno correcto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO HAY `sede_id`, TAMPOCO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un paracetamol es el mismo producto en las dos sedes; lo que cambia por
 * sede es el PRECIO y el COSTO, y esos viven en `precios` y en el kardex.
 * Duplicar el catálogo por sede es la misma trampa que duplicar la
 * persona por sede (ver `create_personas_table`): el día que hay que
 * consolidar consumo, cada copia arrastra su historia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * VIGENCIA, NO `activo`
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.4: un servicio "desactivado" hoy tiene que seguir explicando una
 * factura de hace dos años. Un booleano borra esa historia; un rango de
 * fechas la conserva y además responde "¿esto se ofrecía en marzo?".
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Misma técnica que `personas.nombre_busqueda`: la calcula
         * Postgres, no PHP. Un catálogo de dos mil ítems se busca
         * escribiendo mal —"amoxilina", "paracetamol 500"— y quien está
         * en el mostrador no va a probar tres grafías.
         *
         * ⚠️ Las dos cadenas de `translate` tienen que tener exactamente
         * la misma cantidad de caracteres y ser idénticas a las de
         * App\Support\NormalizadorDeTexto. Hay una prueba que lo compara.
         */
        $expresionBusqueda = <<<'SQL'
            lower(
                regexp_replace(
                    translate(
                        btrim(
                            coalesce(codigo, '')           || ' ' ||
                            coalesce(nombre, '')           || ' ' ||
                            coalesce(principio_activo, '')
                        ),
                        'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç',
                        'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc'
                    ),
                    '\s+', ' ', 'g'
                )
            )
            SQL;

        Schema::create('items', function (Blueprint $tabla) use ($expresionBusqueda): void {
            $tabla->id();

            /*
             * Código interno del hospital. Es lo que se teclea en el
             * mostrador y lo que va impreso en la etiqueta, así que se
             * elige corto y estable. §8.10: el tarifario de un hospital
             * privado hondureño es propio; CPT es licenciado y CUPS es
             * colombiano.
             */
            $tabla->string('codigo', 30);
            $tabla->string('nombre');
            $tabla->text('descripcion')->nullable();

            $tabla->text('nombre_busqueda')->storedAs($expresionBusqueda);

            // ── Clasificación ─────────────────────────────────────────
            $tabla->string('tipo', 30);
            $tabla->string('regimen_isv', 20);
            $tabla->string('politica_cargo', 30)->default('cobrable');

            /*
             * Bajo qué numeral del Art. 30 cae, para el descuento de
             * adulto mayor.
             *
             * ⚠️ Sigue siendo un eje PROPIO, aunque desde el 20-ago-2026
             * el formulario del catálogo ya no lo pregunta y `Item` lo
             * deduce del tipo cuando nadie lo escribe.
             *
             * La deducción NO es exacta y hay que saberlo: consulta
             * general y consulta especializada son las dos `Honorario` y
             * llevan 25 % y 30 %, así que todo honorario deducido cae en
             * el 25 %. La diferencia se cubre marcándole al ítem un
             * descuento propio en «Descuentos» —el resolutor se queda
             * con el mayor de los dos—, o escribiendo la categoría a
             * mano desde un seeder, que este bloque respeta.
             */
            $tabla->string('categoria_legal_descuento', 40);

            // ── Unidades ──────────────────────────────────────────────
            /*
             * La unidad en la que se lleva el KARDEX y en la que se
             * cobra. Nula para lo que no es físico: un honorario o una
             * estancia se cobran pero no mueven existencia.
             *
             * Cuántas de estas caben en una caja lo dice cada
             * presentación (`item_presentaciones`), porque el mismo
             * producto se compra en caja de 100 y en caja de 50 según el
             * proveedor, y forzar una sola equivalencia es cómo un costo
             * termina cien veces más alto sin que nadie lo note.
             */
            $tabla->foreignId('unidad_dispensacion_id')
                ->nullable()
                ->constrained('unidades')
                ->restrictOnDelete();

            /*
             * Fraccionable: un frasco de nebulización sí, una ampolla no.
             * `fracciones_por_unidad` dice cuántas fracciones tiene una
             * unidad de dispensación — 1 ampolla de 2 ml = 2.
             */
            $tabla->boolean('fraccionable')->default(false);
            $tabla->foreignId('unidad_fraccion_id')
                ->nullable()
                ->constrained('unidades')
                ->restrictOnDelete();
            $tabla->decimal('fracciones_por_unidad', 14, 4)->nullable();

            /*
             * Un multidosis abierto suele vencer mucho antes que la fecha
             * impresa en el frasco. Nulo = usar el default de
             * config('sihla.inventario.horas_caducidad_post_apertura_por_defecto').
             */
            $tabla->unsignedInteger('horas_caducidad_post_apertura')->nullable();

            // ── Farmacia y control sanitario ──────────────────────────
            $tabla->boolean('requiere_lote')->default(false);
            $tabla->boolean('requiere_receta')->default(false);
            $tabla->boolean('es_controlado')->default(false);

            $tabla->string('principio_activo')->nullable();
            $tabla->string('registro_arsa', 50)->nullable();
            $tabla->string('presentacion_comercial')->nullable();

            // ── Códigos estándar (§8.10) ──────────────────────────────
            /*
             * OPCIONALES y nunca llave. El ítem se identifica por su
             * código interno; estos son para hablar con afuera: CIE-10
             * con SESAL y las aseguradoras, LOINC con los analizadores,
             * ATC para clasificar el medicamento.
             *
             * `version_codificacion` es lo que hace que migrar a CIE-11
             * sea cambio de datos y no de esquema — Honduras está en
             * preparación desde la misión OPS de nov-2024, sin fecha.
             */
            $tabla->string('codigo_cie10', 10)->nullable();
            $tabla->string('codigo_loinc', 20)->nullable();
            $tabla->string('codigo_atc', 10)->nullable();
            $tabla->string('version_codificacion', 20)->nullable();

            // ── Contabilidad (§9.H11) ─────────────────────────────────
            /*
             * Se mapea desde el día uno. Hacerlo dos años después es un
             * proyecto de meses sobre millones de filas de cargo.
             */
            $tabla->string('cuenta_contable', 30)->nullable();
            $tabla->string('centro_de_costo', 30)->nullable();

            // ── Vigencia ──────────────────────────────────────────────
            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['tipo', 'vigencia_desde'], 'items_tipo_vigencia_index');
            $tabla->index('categoria_legal_descuento', 'items_categoria_legal_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX items_codigo_unique
             ON items (codigo)
             WHERE deleted_at IS NULL'
        );

        /*
         * Índice de búsqueda tolerante, PARCIAL sobre lo no borrado.
         * Un GIN de trigramas sobre un catálogo completo es caro de
         * mantener; restringirlo a lo vivo lo mantiene chico y evita que
         * el mostrador encuentre ítems dados de baja.
         */
        DB::statement(
            'CREATE INDEX items_nombre_busqueda_trgm
             ON items USING gin (nombre_busqueda gin_trgm_ops)
             WHERE deleted_at IS NULL'
        );

        /*
         * Códigos estándar: índice donde existen. La mayoría de los ítems
         * no los va a llevar, así que un índice completo sería casi todo
         * NULL.
         */
        DB::statement(
            'CREATE INDEX items_codigo_loinc_index
             ON items (codigo_loinc)
             WHERE codigo_loinc IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'CREATE INDEX items_registro_arsa_index
             ON items (registro_arsa)
             WHERE registro_arsa IS NOT NULL AND deleted_at IS NULL'
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * DEFENSA EN PROFUNDIDAD
         * ─────────────────────────────────────────────────────────────
         *
         * Todo lo de acá abajo está también en el modelo y en el
         * formulario. Está repetido en la base porque el formulario no es
         * la única puerta: un seeder, un import de catálogo del sistema
         * viejo o un `DB::table()->insert()` en un comando de migración
         * de datos se saltan las tres capas de arriba.
         *
         * Cada expresión es IMMUTABLE — sin CURRENT_DATE, sin funciones
         * de otra fila.
         */

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_codigo_no_vacio
             CHECK (length(btrim(codigo)) > 0 AND length(btrim(nombre)) > 0)'
        );

        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_tipo_conocido
             CHECK (tipo IN (
                 'servicio', 'procedimiento', 'medicamento', 'insumo',
                 'estudio_laboratorio', 'estudio_imagen', 'honorario',
                 'estancia', 'paquete', 'otro'
             ))"
        );

        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_regimen_isv_conocido
             CHECK (regimen_isv IN ('exento', 'gravado_15', 'gravado_18', 'exonerado'))"
        );

        /*
         * Lo que mueve inventario NECESITA unidad de dispensación. Un
         * medicamento sin unidad no se puede costear ni descontar del
         * kardex, y el error aparece recién en la primera dispensación,
         * de noche, con el paciente esperando.
         */
        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_unidad_obligatoria_si_es_fisico
             CHECK (
                 tipo NOT IN ('medicamento', 'insumo')
                 OR unidad_dispensacion_id IS NOT NULL
             )"
        );

        /*
         * Un lote sobre algo que no es físico no significa nada, y sería
         * un campo obligatorio que nadie puede llenar.
         */
        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_lote_solo_si_es_fisico
             CHECK (
                 requiere_lote = false
                 OR tipo IN ('medicamento', 'insumo')
             )"
        );

        /*
         * Fraccionable a medias no existe: o se declara en qué unidad se
         * fracciona y cuántas fracciones entran, o no es fraccionable.
         * Sin esto, una dispensación por dosis divide por NULL.
         */
        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_fraccion_completa
             CHECK (
                 (fraccionable = false
                     AND unidad_fraccion_id IS NULL
                     AND fracciones_por_unidad IS NULL)
                 OR (fraccionable = true
                     AND unidad_fraccion_id IS NOT NULL
                     AND fracciones_por_unidad IS NOT NULL
                     AND fracciones_por_unidad > 0)
             )'
        );

        /*
         * Un controlado sin receta es una infracción ante ARSA, no una
         * preferencia de configuración.
         */
        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_controlado_exige_receta
             CHECK (es_controlado = false OR requiere_receta = true)'
        );

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_caducidad_post_apertura_positiva
             CHECK (horas_caducidad_post_apertura IS NULL OR horas_caducidad_post_apertura > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
