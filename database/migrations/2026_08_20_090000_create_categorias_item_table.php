<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías del catálogo — cómo se agrupa lo que el hospital ofrece.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UNA TABLA Y NO UN ENUM
 * ─────────────────────────────────────────────────────────────────────
 *
 * `tipo` (servicio, honorario, estancia, estudio…) es taxonomía técnica:
 * decide ISV, unidad, lote y política de cargo. NO es como lee su
 * tarifario el hospital, ni como lo manda la aseguradora: el papel de
 * PALIG viene partido en hojas —Hospitalización, Equipo médico, Rayos X,
 * Laboratorio, Consulta externa— y una hoja mezcla varios tipos.
 *
 * Esa agrupación es del NEGOCIO y cambia por cliente: la clínica
 * siguiente agrupa distinto y no puede necesitar un deploy para hacerlo
 * (§1.1). Por eso es dato con vigencia, no enum ni constante.
 *
 * ⚠️ Categoría ≠ servicio/área que ejecuta ≠ centro de costo. «Equipo
 * médico» es una categoría del tarifario y no un área del hospital: las
 * bombas de infusión se cobran en hospitalización. `servicios` sigue
 * siendo la tabla de las áreas y no se toca.
 *
 * Sin `sede_id` a propósito: el catálogo es de la organización —igual
 * que `items`—, y lo que sí cambia por sede es el PRECIO, que vive en
 * `tarifarios` (ADR-0003).
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ÁMBITO ES LO QUE PARTE LA PANTALLA EN DOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * `servicios` = lo que se ofrece y se cobra sin descontar existencia.
 * `productos` = lo que se guarda, se cuenta y se dispensa (farmacia).
 *
 * Es la misma pregunta que ya contesta `items.se_almacena`, y por eso
 * las dos tienen que decir lo mismo SIEMPRE. Un producto de farmacia
 * archivado bajo «Rayos X» no da error en ninguna pantalla: da un
 * reporte de ingresos por área que no cuadra y nadie sabe por qué.
 *
 * Se garantiza con FK COMPUESTA `(categoria_id, categoria_ambito)` más
 * un CHECK que ata el ámbito a `se_almacena`. La columna redundante
 * `categoria_ambito` existe solo para eso: es el precio de poder
 * declararlo en la base en vez de confiar en que toda pantalla, todo
 * import y todo script se acuerden (§12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_item', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('codigo', 20);
            $tabla->string('nombre');

            /*
             * servicios | productos. Ver el encabezado: es el eje que
             * decide en qué pantalla vive el ítem.
             */
            $tabla->string('ambito', 20);

            $tabla->text('descripcion')->nullable();

            /*
             * En qué orden se muestran. El tarifario impreso tiene un
             * orden que el personal ya conoce, y alfabético no es ese.
             */
            $tabla->unsignedSmallInteger('orden')->default(100);

            /*
             * §8.4: los catálogos tienen vigencia, no un booleano
             * `activo`. Una categoría retirada hoy sigue explicando la
             * factura del año pasado donde aparece.
             */
            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['ambito', 'orden'], 'categorias_item_ambito_orden_index');
        });

        DB::statement(
            "ALTER TABLE categorias_item
             ADD CONSTRAINT categorias_item_ambito_valido
             CHECK (ambito IN ('servicios', 'productos'))"
        );

        /*
         * Único por código, ignorando las borradas: NULL ≠ NULL en un
         * índice único de PostgreSQL, así que el índice parcial es la
         * única forma de permitir reusar el código de una categoría
         * retirada sin permitir dos vivas con el mismo (§12).
         */
        DB::statement(
            'CREATE UNIQUE INDEX categorias_item_codigo_unique
             ON categorias_item (codigo)
             WHERE deleted_at IS NULL'
        );

        /*
         * El destino de la FK compuesta de `items`. PostgreSQL exige que
         * las columnas referenciadas tengan un único propio.
         */
        DB::statement(
            'ALTER TABLE categorias_item
             ADD CONSTRAINT categorias_item_id_ambito_unique UNIQUE (id, ambito)'
        );

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('categoria_id')->nullable()->after('se_almacena');
            $tabla->string('categoria_ambito', 20)->nullable()->after('categoria_id');
        });

        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_categoria_fk
             FOREIGN KEY (categoria_id, categoria_ambito)
             REFERENCES categorias_item (id, ambito)
             ON DELETE RESTRICT'
        );

        /*
         * Las dos columnas viajan juntas o no viajan. Sin esto, un
         * `categoria_ambito` suelto haría que la FK compuesta no
         * verifique nada (MATCH SIMPLE ignora la fila si algún lado es
         * nulo) y el CHECK de abajo pasaría a hablar de la nada.
         */
        DB::statement(
            'ALTER TABLE items
             ADD CONSTRAINT items_categoria_completa
             CHECK ((categoria_id IS NULL) = (categoria_ambito IS NULL))'
        );

        /*
         * 🔴 La categoría y `se_almacena` tienen que decir lo mismo.
         *
         * Consecuencia buscada: cambiar `se_almacena` de un ítem que ya
         * tiene categoría FALLA. Mover algo entre el catálogo y la
         * farmacia es un acto explícito que cambia las dos columnas
         * juntas, no un interruptor que alguien toca de paso.
         */
        DB::statement(
            "ALTER TABLE items
             ADD CONSTRAINT items_categoria_coherente_con_almacenamiento
             CHECK (
                 categoria_ambito IS NULL
                 OR (categoria_ambito = 'productos') = se_almacena
             )"
        );

        DB::statement(
            'CREATE INDEX items_categoria_index
             ON items (categoria_id)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS items_categoria_index');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_categoria_coherente_con_almacenamiento');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_categoria_completa');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_categoria_fk');

        Schema::table('items', function (Blueprint $tabla): void {
            $tabla->dropColumn(['categoria_id', 'categoria_ambito']);
        });

        Schema::dropIfExists('categorias_item');
    }
};
