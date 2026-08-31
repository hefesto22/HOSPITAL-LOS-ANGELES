<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que cobra el médico también depende de quién paga.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL MISMO DOCTOR, LA MISMA CONSULTA, TRES PRECIOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un médico no le cobra lo mismo al paciente que llega de la calle que
 * al del Hospital Militar o al de PALIG: con la aseguradora hay una
 * tarifa negociada, y con el particular hay otra. La tabla nació con un
 * solo precio por médico y honorario, y eso obligaba a corregirlo a mano
 * en cada cobro de paciente asegurado —o sea, a que tarde o temprano se
 * cobrara mal—.
 *
 * `convenio_id` nulo = el precio GENERAL de ese médico: vale para todo
 * pagador que no tenga fila propia. Es la misma escalera de
 * `tarifarios`, y por la misma razón: lo específico gana, lo general
 * siempre responde.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE CAMBIA EL ÚNICO POR UNA EXCLUSIÓN DE RANGOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El índice único original era por fecha de INICIO, y eso deja pasar dos
 * precios traslapados: uno abierto desde enero y otro abierto desde
 * junio son dos filas legales y las dos vigentes hoy. El resolutor lo
 * tapaba con un `ORDER BY`, que es decidir el precio por el orden de los
 * datos.
 *
 * Acá se impide en la base, con el mismo `EXCLUDE USING gist` que ya
 * protege al tarifario: dos vigencias del mismo médico, mismo honorario
 * y mismo pagador no se pueden tocar.
 *
 * ⚠️ En SQL `NULL = NULL` no es verdadero, así que la exclusión va sobre
 * `COALESCE(convenio_id, 0)`. Sin eso podrían convivir dos precios
 * generales vigentes del mismo médico, que es justo lo que esto evita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('honorarios_medicos', function (Blueprint $tabla): void {
            $tabla->foreignId('convenio_id')
                ->nullable()
                ->after('item_id')
                ->constrained('convenios')
                ->restrictOnDelete();
        });

        /*
         * El único por fecha de inicio se va: lo reemplaza algo más
         * fuerte, y dejar los dos obligaría a cargar dos filas idénticas
         * con fechas distintas para expresar lo mismo.
         */
        DB::statement('DROP INDEX IF EXISTS honorarios_medicos_vigencia_unique');

        DB::statement(
            "ALTER TABLE honorarios_medicos
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        DB::statement(
            'ALTER TABLE honorarios_medicos
             ADD CONSTRAINT honorarios_medicos_sin_traslape
             EXCLUDE USING gist (
                 medico_id WITH =,
                 item_id WITH =,
                 (COALESCE(convenio_id, 0)) WITH =,
                 vigencia WITH &&
             )
             WHERE (deleted_at IS NULL)'
        );

        /*
         * El resolutor entra por médico + ítem y desempata por pagador.
         * Sin este índice, cada honorario de cada cuenta es un recorrido
         * completo de la tabla.
         */
        DB::statement(
            'CREATE INDEX honorarios_medicos_busqueda
             ON honorarios_medicos (medico_id, item_id, convenio_id, vigencia_desde)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS honorarios_medicos_busqueda');
        DB::statement('ALTER TABLE honorarios_medicos DROP CONSTRAINT IF EXISTS honorarios_medicos_sin_traslape');
        DB::statement('ALTER TABLE honorarios_medicos DROP COLUMN IF EXISTS vigencia');

        Schema::table('honorarios_medicos', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('convenio_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX honorarios_medicos_vigencia_unique
             ON honorarios_medicos (medico_id, item_id, vigencia_desde)
             WHERE deleted_at IS NULL'
        );
    }
};
