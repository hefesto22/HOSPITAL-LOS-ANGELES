<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de los datos demográficos de una persona (ADR-0004).
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ SIRVE ESTO EN LA VIDA REAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * Tres casos que pasan seguido y que sin esta tabla no tienen solución:
 *
 *  1. Una señora se casa y cambia de apellido. La factura del año pasado
 *     salió con el apellido de soltera. Cuando la reimprima —o cuando el
 *     SAR la audite— tiene que salir EXACTAMENTE igual que se emitió.
 *     Sin historial, se reimprime con el nombre de hoy y ya no coincide
 *     con el documento fiscal declarado.
 *
 *  2. Se corrige una fecha de nacimiento mal digitada. Los cargos que ya
 *     se facturaron con el rango de edad anterior tienen que seguir
 *     explicándose: por qué esa factura llevó descuento de tercera edad
 *     si hoy el paciente aparece con 45 años.
 *
 *  3. Se deshace una fusión (§9.D4). Para devolver a cada persona sus
 *     datos hay que saber cuáles eran ANTES de fusionarlas. La fusión
 *     escribe una versión de cada lado; deshacerla la lee.
 *
 * ─────────────────────────────────────────────────────────────────────
 * APPEND-ONLY DE VERDAD, NO POR CONVENCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El ADR-0004 dice que el expediente no se modifica ni se borra. Un
 * comentario que lo pida no lo hace cumplir: un `->update()` distraído,
 * un `php artisan tinker` a las 11 de la noche o un seeder mal escrito lo
 * rompen sin dejar rastro.
 *
 * Por eso hay un TRIGGER que rechaza UPDATE y DELETE sobre esta tabla. No
 * es paranoia: es la única forma de que "append-only" sea una propiedad
 * del sistema y no una promesa. Corregir una versión histórica se hace
 * como se hace en contabilidad — insertando otra versión que la corrige,
 * no borrando la anterior.
 *
 * ⚠️ El trigger NO se dispara con TRUNCATE (es a nivel de fila), así que
 * `migrate:fresh` y el rollback de las pruebas siguen funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_versiones', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            /*
             * Número consecutivo por persona, empezando en 1. Es lo que
             * permite decir "la versión 3 del expediente de fulano" en un
             * informe de auditoría sin depender de la hora exacta.
             */
            $table->unsignedInteger('version');

            /*
             * FOTO COMPLETA de los datos demográficos, no solo el cambio.
             *
             * Guardar únicamente el diff obliga a reconstruir el estado
             * sumando todas las versiones anteriores, y basta con que una
             * se haya perdido o guardado mal para que la reconstrucción
             * dé un resultado falso sin avisar. La foto se lee sola.
             *
             * jsonb y no json: se consulta por adentro (buscar en qué
             * versión cambió el apellido) y se indexa. `json` guarda el
             * texto tal cual y no soporta operadores de contención.
             */
            $table->jsonb('datos');

            /*
             * El diff, precalculado. Es redundante con `datos` a
             * propósito: la pantalla de auditoría necesita "qué cambió"
             * mil veces más seguido que "cómo estaba todo", y calcularlo
             * al vuelo contra la versión anterior obliga a leer dos filas
             * y compararlas en PHP en cada renglón de la bitácora.
             */
            $table->jsonb('cambios')->nullable();

            /*
             * Por qué cambió. "Corrección de digitación", "cambio de
             * apellido por matrimonio", "fusión de duplicados".
             */
            $table->string('motivo')->nullable();

            $table->foreignId('registrado_por')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * Sin `timestamps()`: `updated_at` en una tabla append-only es
             * una contradicción, y una columna que nunca cambia es una
             * invitación a que alguien intente cambiarla.
             */
            $table->timestampTz('registrado_en');

            $table->unique(['persona_id', 'version'], 'persona_versiones_unica');
        });

        /*
         * BRIN y no B-tree sobre la fecha: esta tabla solo crece y siempre
         * por el final, así que las filas ya están ordenadas en disco por
         * `registrado_en`. Un BRIN cuesta una fracción del espacio de un
         * B-tree y sirve igual para "qué cambió entre el 1 y el 31 de
         * marzo", que es como se consulta.
         *
         * Se crea con SQL porque el Blueprint de Laravel no expone el
         * método de acceso `USING brin`.
         */
        DB::statement(
            'CREATE INDEX persona_versiones_registrado_en_brin
             ON persona_versiones USING brin (registrado_en)'
        );

        /*
         * El candado. Ver el bloque de la cabecera.
         *
         * Se declara con nombre propio y no anónimo para que el DBA que
         * abra la base entienda qué es sin leer el código de la app.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sihla_rechazar_modificacion()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'La tabla % es append-only (ADR-0004): no se permite %.',
                    TG_TABLE_NAME, lower(TG_OP)
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);

        DB::unprepared(
            'CREATE TRIGGER persona_versiones_append_only
             BEFORE UPDATE OR DELETE ON persona_versiones
             FOR EACH ROW EXECUTE FUNCTION sihla_rechazar_modificacion()'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS persona_versiones_append_only ON persona_versiones');
        Schema::dropIfExists('persona_versiones');
        DB::unprepared('DROP FUNCTION IF EXISTS sihla_rechazar_modificacion()');
    }
};
