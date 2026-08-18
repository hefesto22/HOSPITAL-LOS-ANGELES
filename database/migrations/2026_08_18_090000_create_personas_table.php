<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personas — el MPI (Master Patient Index) del §8.2.
 *
 * Una persona, un registro, para toda la organización y para siempre.
 * El EXPEDIENTE es por sede; la PERSONA no. Si Mauricio abre la segunda
 * sede y el mismo paciente llega allá, es la misma persona con otro
 * expediente, no otra persona.
 *
 * ⚠️ POR ESO ESTA TABLA NO TIENE `sede_id`, Y ES DELIBERADO.
 *
 * Ponerle `sede_id` obligaría a duplicar la persona por sede, que es
 * exactamente el problema que un MPI existe para resolver: el día que hay
 * que fusionar, cada copia arrastra su historia clínica y ya no se puede
 * saber cuál alergia es la vigente. El alcance por sede (ADR-0002) se
 * aplica sobre `expedientes` y sobre `encuentros`, no sobre la identidad.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ `merged_into` ESTÁ DESDE LA PRIMERA MIGRACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El §9.D4 exige que la fusión de duplicados NUNCA borre ni mueva filas y
 * que SIEMPRE sea reversible. Eso no es una funcionalidad que se agrega
 * después: es una propiedad del esquema.
 *
 * Con `merged_into`, fusionar es escribir un puntero. Deshacer es borrar
 * ese puntero. Las filas de las dos personas siguen intactas, y cualquier
 * documento emitido antes de la fusión sigue apuntando a la fila que lo
 * generó — que es lo que hace que la factura de hace dos años se pueda
 * reimprimir igual.
 *
 * Si `merged_into` no existiera desde el día uno, la alternativa sería
 * mover las filas hijas de una persona a la otra (destructivo, no
 * reversible), y agregarlo más tarde significaría reescribir TODAS las
 * consultas del sistema para que filtren duplicados fusionados.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────
         * LA COLUMNA DE BÚSQUEDA LA CALCULA POSTGRES, NO PHP
         * ─────────────────────────────────────────────────────────────
         *
         * `nombre_busqueda` es GENERATED ALWAYS AS (...) STORED. La base
         * la recalcula en cada INSERT y cada UPDATE, venga de donde venga
         * la escritura: un formulario, un seeder, un import de padrón,
         * una corrección hecha con `DB::table()->update()` o un psql a
         * mano.
         *
         * La alternativa era un mutator o un observer en el modelo. Se
         * descartó por la misma razón que el mutator de `Sede::codigo`
         * NO reemplaza al índice: el formulario no es la única puerta.
         * Un observer que se salta un import deja nombres que existen
         * pero no se encuentran, y ese bug es invisible hasta que alguien
         * crea el expediente duplicado.
         *
         * Requisitos de PostgreSQL para una columna generada: la
         * expresión tiene que ser IMMUTABLE y usar solo columnas de la
         * misma fila. `coalesce`, `||`, `btrim`, `translate`,
         * `regexp_replace/4` y `lower` cumplen.
         *
         * ⚠️ Las dos cadenas de `translate` deben tener EXACTAMENTE la
         * misma cantidad de caracteres, y deben ser idénticas a las de
         * App\Support\NormalizadorDeTexto. Hay una prueba que compara los
         * dos resultados para que no se separen.
         */
        $expresionNombreBusqueda = <<<'SQL'
            lower(
                regexp_replace(
                    translate(
                        btrim(
                            coalesce(primer_nombre, '')    || ' ' ||
                            coalesce(segundo_nombre, '')   || ' ' ||
                            coalesce(primer_apellido, '')  || ' ' ||
                            coalesce(segundo_apellido, '') || ' ' ||
                            coalesce(apellido_casada, '')
                        ),
                        'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç',
                        'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc'
                    ),
                    '\s+', ' ', 'g'
                )
            )
            SQL;

        Schema::create('personas', function (Blueprint $table) use ($expresionNombreBusqueda): void {
            $table->id();

            /*
             * Identificador opaco para todo lo que salga del sistema:
             * URLs, códigos de barra de brazalete, QR, integraciones.
             *
             * El `id` secuencial no se expone. Además de filtrar cuántos
             * pacientes tiene el hospital, permite adivinar el siguiente
             * y el anterior — y en un expediente clínico eso es un
             * problema de privacidad, no una molestia estética.
             */
            $table->uuid('uuid')->unique();

            // ── Nombre ────────────────────────────────────────────────
            /*
             * Campos separados, no un `nombre_completo`. En Honduras el
             * orden es nombres + apellido paterno + apellido materno, y
             * el sistema tiene que poder ordenar por apellido, buscar por
             * apellido materno y armar la ficha del INE. Con un solo
             * campo eso se resuelve partiendo por espacios, que falla con
             * "DE LEÓN", "DEL CID" y "VAN DER HORST".
             */
            $table->string('primer_nombre', 60);
            $table->string('segundo_nombre', 60)->nullable();

            /*
             * El apellido es nullable SOLO para el NN. Un CHECK más abajo
             * lo obliga en cualquier otro caso.
             */
            $table->string('primer_apellido', 60)->nullable();
            $table->string('segundo_apellido', 60)->nullable();

            /*
             * Apellido de casada. Se guarda aparte porque no reemplaza a
             * los de nacimiento: el DNI sigue diciendo los originales y
             * la factura tiene que coincidir con el DNI, pero la señora
             * se presenta en admisión con el de casada.
             */
            $table->string('apellido_casada', 60)->nullable();

            // La calcula Postgres. Ver el bloque de arriba.
            $table->text('nombre_busqueda')->storedAs($expresionNombreBusqueda);

            // ── Sexo y género: DOS campos, no uno ─────────────────────
            /*
             * `sexo_biologico` es clínico (rangos de laboratorio, dosis);
             * `genero` es administrativo (trato, documentos). La razón
             * completa está en los docblocks de los dos enums.
             *
             * El default es `desconocido`, no `masculino`: el NN entra
             * sin evaluar y un default arbitrario mete un dato falso en
             * el expediente que después nadie corrige.
             */
            $table->string('sexo_biologico', 20)->default('desconocido');
            $table->string('genero', 20)->nullable();

            // ── Fechas ────────────────────────────────────────────────
            $table->date('fecha_nacimiento')->nullable();
            $table->string('precision_fecha_nacimiento', 20)->default('exacta');

            /*
             * Defunción. No es un booleano `fallecido`: la FECHA importa
             * para el certificado, para cerrar la cuenta y para que el
             * sistema deje de agendar citas a un fallecido — que es un
             * error que las familias no perdonan.
             */
            $table->date('fecha_defuncion')->nullable();

            // ── NN ────────────────────────────────────────────────────
            /*
             * Bandera explícita en vez de deducirla de `primer_nombre =
             * 'NN'`. Deducirla falla el día que llega alguien que de
             * verdad se apellida NN, y sobre todo no sirve como filtro de
             * la bandeja "identificar antes del alta", que es para lo que
             * existe.
             */
            $table->boolean('es_nn')->default(false);
            $table->string('nota_identificacion')->nullable();

            // ── Demografía y contacto ─────────────────────────────────
            $table->char('nacionalidad', 2)->nullable();
            $table->string('departamento', 60)->nullable();
            $table->string('municipio', 60)->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('telefono_alterno', 30)->nullable();
            $table->string('email')->nullable();

            // ── Fusión de duplicados (§9.D4) ──────────────────────────
            /*
             * Apunta a la persona SOBREVIVIENTE. Nula = esta persona es
             * la buena.
             *
             * `nullOnDelete` y no `cascade`: si por lo que sea la
             * sobreviviente desaparece, la fusionada vuelve a ser una
             * persona válida. Con cascade se llevaría por delante a la
             * que sí tiene historia clínica.
             */
            $table->foreignId('merged_into')->nullable()
                ->constrained('personas')->nullOnDelete();
            $table->timestampTz('merged_at')->nullable();
            $table->foreignId('merged_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('merged_motivo')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Buscar por fecha de nacimiento es el segundo criterio de
             * desempate después del nombre: "Juan Pérez" hay veinte, pero
             * "Juan Pérez nacido el 3 de marzo de 1978" hay uno.
             */
            $table->index('fecha_nacimiento', 'personas_fecha_nacimiento_index');
            $table->index('merged_into', 'personas_merged_into_index');
        });

        /*
         * ─────────────────────────────────────────────────────────────
         * ÍNDICE TRIGRAMA PARA LA BÚSQUEDA TOLERANTE
         * ─────────────────────────────────────────────────────────────
         *
         * Admisión escribe "jose antonyo pena" y el paciente está como
         * "José Antonio Peña". Un LIKE no lo encuentra; pg_trgm sí,
         * porque compara los tríos de letras en común.
         *
         * El índice es PARCIAL a propósito: excluye las personas
         * fusionadas y las borradas. Buscar debe llevar a la persona
         * SOBREVIVIENTE, no ofrecer de nuevo el duplicado que alguien ya
         * se tomó el trabajo de fusionar.
         *
         * `gin_trgm_ops` soporta `%` (similitud global), `%>` (similitud
         * contra la mejor palabra del texto) y `LIKE`, que es todo lo que
         * usa la búsqueda del MPI.
         */
        DB::statement(
            'CREATE INDEX personas_nombre_busqueda_trgm
             ON personas USING gin (nombre_busqueda gin_trgm_ops)
             WHERE merged_into IS NULL AND deleted_at IS NULL'
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * CHECKS — defensa en profundidad (§12)
         * ─────────────────────────────────────────────────────────────
         *
         * Ninguno reemplaza la validación del formulario: la complementan
         * para lo que NO pasa por el formulario (seeders, imports,
         * comandos, correcciones a mano).
         *
         * ⚠️ Ninguno usa CURRENT_DATE ni now(). Un CHECK tiene que ser
         * inmutable: si dependiera de la fecha, al restaurar un respaldo
         * dentro de tres años filas que hoy son válidas podrían dejar de
         * serlo y el restore fallaría a mitad de camino. "No nacer en el
         * futuro" se valida en la aplicación; acá solo van cotas fijas.
         */
        DB::statement(
            "ALTER TABLE personas ADD CONSTRAINT personas_nombre_no_vacio
             CHECK (btrim(primer_nombre) <> '')"
        );

        DB::statement(
            "ALTER TABLE personas ADD CONSTRAINT personas_apellido_obligatorio_salvo_nn
             CHECK (es_nn = true OR (primer_apellido IS NOT NULL AND btrim(primer_apellido) <> ''))"
        );

        DB::statement(
            "ALTER TABLE personas ADD CONSTRAINT personas_fecha_nacimiento_plausible
             CHECK (fecha_nacimiento IS NULL OR fecha_nacimiento >= DATE '1890-01-01')"
        );

        DB::statement(
            'ALTER TABLE personas ADD CONSTRAINT personas_defuncion_posterior_a_nacimiento
             CHECK (fecha_defuncion IS NULL OR fecha_nacimiento IS NULL
                    OR fecha_defuncion >= fecha_nacimiento)'
        );

        /*
         * Una persona no puede ser su propio duplicado. Sin esto,
         * `merged_into = id` produce un ciclo de longitud 1 y cualquier
         * recorrido de la cadena de fusiones se cuelga.
         */
        DB::statement(
            'ALTER TABLE personas ADD CONSTRAINT personas_no_se_fusiona_consigo_misma
             CHECK (merged_into IS NULL OR merged_into <> id)'
        );

        /*
         * Una fusión sin fecha ni responsable no se puede auditar ni
         * revertir con criterio. Los tres campos van juntos o no van.
         */
        DB::statement(
            'ALTER TABLE personas ADD CONSTRAINT personas_fusion_completa
             CHECK ((merged_into IS NULL AND merged_at IS NULL)
                 OR (merged_into IS NOT NULL AND merged_at IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
