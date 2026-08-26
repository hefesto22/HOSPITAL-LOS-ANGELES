<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los descuentos que el hospital arma con sus propias palabras.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTA TABLA Y NO UNA COLUMNA MÁS EN `descuentos_legales`
 * ─────────────────────────────────────────────────────────────────────
 *
 * `descuentos_legales` guarda los porcentajes del Artículo 30 indexados
 * por NUMERAL de la ley: no tienen nombre porque su nombre es el
 * numeral. Sirve para cumplir la ley y para nada más.
 *
 * Esto es otra cosa: una lista con nombres del hospital —«Tercera
 * edad», «Cuarta edad», «Empleado del hospital»— que después se marca
 * ítem por ítem. Meterlas en la misma tabla haría que una fila sin
 * nombre y una con nombre significaran cosas distintas según quién la
 * mire, y que borrar la de la ley se llevara la del hospital por
 * delante.
 *
 * Las dos conviven y ninguna le gana a la otra por ser de su tabla:
 * `ResolutorDeDescuentoLegal` devuelve **el mayor de los dos**. Un
 * descuento del hospital nunca puede dejar a un adulto mayor por debajo
 * de lo que la ley le da.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 `aplica_a` ES LO QUE HACE QUE EL DESCUENTO LLEGUE A LA FACTURA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un descuento con nombre y porcentaje pero sin decir A QUIÉN se le
 * aplica es un número que nadie puede cobrar solo: alguien tendría que
 * acordarse en la caja, a las once de la noche, con el paciente
 * enfrente. Eso no es un descuento, es una nota.
 *
 * Con `aplica_a` el sistema sabe cuándo dispararlo:
 *
 *   · `tercera` / `cuarta` → por la edad del paciente EN LA FECHA DEL
 *     SERVICIO. Los tramos viven en `config('sihla.edad.rangos_por_defecto')`.
 *   · `manual` → existe en la lista pero no se aplica solo. Es para lo
 *     que depende de una condición que el sistema todavía no conoce
 *     («empleado del hospital»).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL NOMBRE ES LA IDENTIDAD, Y ESO NO ES DECORACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * La pregunta que esta tabla contesta no es «cuánto se descuenta» sino
 * «cuánto se descontaba el día del servicio». Una factura de marzo que
 * se reimprime en septiembre tiene que salir con el porcentaje de marzo:
 * ya se cobró y ya se declaró (ADR-0003).
 *
 * Por eso el nombre es único ENTRE LOS VIGENTES y no en toda la tabla:
 * «Tercera edad» al 25 % hasta junio y al 30 % desde julio son **dos
 * filas del mismo descuento**, no dos descuentos.
 *
 * De ahí sale la consecuencia que hay que tener presente al leer
 * `descuento_item`: el pivote guarda el `id` de la fila que estaba
 * vigente el día que alguien marcó la casilla, pero el resolutor **no
 * lee ese id**. Lee el NOMBRE, y con el nombre busca la fila vigente en
 * la fecha del servicio.
 *
 *     item 100 → descuento 7 («Tercera edad», enero, 25 %)
 *     el 15/09 el resolutor devuelve la fila 9 («Tercera edad», julio, 30 %)
 *
 * Resolver por `id` sería el bug silencioso de este módulo: al cambiar
 * el porcentaje, todos los ítems marcados se quedarían con el viejo y
 * la pantalla seguiría mostrando la casilla marcada. Nadie lo vería
 * hasta que un paciente reclamara.
 *
 * Y de ahí sale una regla: **el nombre no se edita**. Este módulo es
 * append-only, igual que Márgenes objetivo — no hay botón de editar ni
 * de borrar. Renombrar una fila cortaría el hilo con todos los ítems que
 * la tenían marcada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descuentos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('nombre', 80);

            /*
             * Fracción, no porcentaje: 0.2500 es 25 %. Cuatro decimales
             * porque una reforma puede fijar 12.5 % y redondearlo a 13 %
             * sería cobrarle de más a un adulto mayor.
             *
             * `numeric(5,4)` además es una red: si alguien escribiera 25
             * creyendo que se guarda en porcentaje, Postgres rechaza la
             * fila por desbordamiento antes de llegar al CHECK.
             */
            $tabla->decimal('porcentaje', 5, 4);

            $tabla->string('aplica_a', 20);

            /*
             * Art. 34: el descuento en medicamentos exige receta original
             * firmada y sellada. Es propiedad del DESCUENTO, no del ítem —
             * una reforma podría quitar el requisito sin tocar el catálogo.
             */
            $tabla->boolean('exige_receta')->default(false);

            /*
             * Sin columna de fundamento, y es deliberado.
             *
             * El numeral del Art. 30 que sustenta un porcentaje vive en
             * `descuentos_legales`, que es la tabla de la ley y lo exige
             * obligatorio. Repetirlo acá pedía escribir dos veces lo
             * mismo para cargar un descuento que el hospital ya sabe de
             * dónde sale. Si hace falta dejar dicho algo, está `nota`.
             */
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
            "ALTER TABLE descuentos
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        /*
         * Dos «Tercera edad» vigentes el mismo día harían que el
         * descuento dependa del `ORDER BY` — y romperían la resolución
         * por nombre, que da por hecho que hay una sola. Es el mismo
         * problema del §8.5 y se resuelve igual.
         *
         * El índice GiST que crea esta restricción es además el que sirve
         * la consulta del resolutor (`vigencia @> fecha` filtrando por
         * nombre); verificado con EXPLAIN contra PostgreSQL 16.
         */
        DB::statement(
            'ALTER TABLE descuentos
             ADD CONSTRAINT descuentos_nombre_sin_traslape
             EXCLUDE USING gist (nombre WITH =, vigencia WITH &&)
             WHERE (deleted_at IS NULL)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE descuentos
             ADD CONSTRAINT descuentos_aplica_a_valido
             CHECK (aplica_a IN ('tercera', 'cuarta', 'manual'))"
        );

        /*
         * Cero se permite y negativo no. Un descuento en cero es una
         * cortesía declarada que existe; uno negativo es el hospital
         * cobrándole de más al paciente con nombre de descuento.
         */
        DB::statement(
            'ALTER TABLE descuentos
             ADD CONSTRAINT descuentos_porcentaje_es_fraccion
             CHECK (porcentaje >= 0 AND porcentaje <= 1)'
        );

        DB::statement(
            'ALTER TABLE descuentos
             ADD CONSTRAINT descuentos_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        /*
         * El nombre es la identidad: «AB» o «  » no identifican nada, y
         * un nombre en blanco haría chocar la exclusión con cualquier
         * otro nombre en blanco por motivos que nadie entendería.
         */
        DB::statement(
            'ALTER TABLE descuentos
             ADD CONSTRAINT descuentos_nombre_con_contenido
             CHECK (length(btrim(nombre)) >= 3)'
        );

        /*
         * Qué descuentos se le marcaron a cada ítem.
         *
         * La clave compuesta impide marcarlo dos veces — que en pantalla
         * se vería igual, y en un reporte que cuente filas, no.
         *
         * `restrictOnDelete` sobre el descuento: borrar de verdad una
         * fila que ya se usó dejaría cuentas viejas sin poder explicar
         * de dónde salió su descuento. Acá no se borra, se cierra la
         * vigencia.
         */
        Schema::create('descuento_item', function (Blueprint $tabla): void {
            $tabla->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $tabla->foreignId('descuento_id')->constrained('descuentos')->restrictOnDelete();

            $tabla->primary(['item_id', 'descuento_id']);

            $tabla->timestamps();
        });

        /*
         * Para contestar «¿a cuántos ítems les toca este descuento?»,
         * que es lo que la pantalla muestra antes de cambiar un
         * porcentaje. La clave compuesta empieza por `item_id`, así que
         * no sirve para buscar por descuento.
         */
        DB::statement(
            'CREATE INDEX descuento_item_por_descuento ON descuento_item (descuento_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('descuento_item');
        Schema::dropIfExists('descuentos');
    }
};
