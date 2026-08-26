<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El encuentro — el eje del que cuelga todo (§8.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE CARGA DIRECTO AL PACIENTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Sin encuentro, un resultado de laboratorio no sabe a qué cuenta
 * cobrarse y un cargo no sabe qué convenio aplicarle. El mismo señor
 * puede venir a consulta externa el martes, internarse el jueves y
 * volver a emergencia en septiembre: son tres historias distintas de la
 * misma persona, con tres pagadores posibles y tres cuentas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LOS TRES TIEMPOS DEL EGRESO (§9.K8)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `alta_medica_en`   — el médico decidió que se puede ir.
 * `alta_administrativa_en` — la cuenta quedó liquidada.
 * `salida_fisica_en` — la cama quedó libre.
 *
 * Colapsarlos en uno hace imposible medir la demora del egreso —el mayor
 * devorador de capacidad de un hospital— y produce el caso clásico del
 * paciente que «ya salió» según el sistema y sigue en la cama sin pagar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO LLEVA `softDeletes`
 * ─────────────────────────────────────────────────────────────────────
 *
 * §12: borrado suave solo en catálogos y personas. Un encuentro no se
 * borra ni se esconde: se anula con motivo, y queda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuentros', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $tabla->foreignId('expediente_id')->constrained('expedientes')->restrictOnDelete();

            /*
             * La persona va desnormalizada a propósito y no se deduce del
             * expediente. Dos razones: la pantalla de cuentas abiertas
             * necesita el nombre sin un salto más, y el día que un merge
             * mueva un expediente de dueño (§9.D4) esta columna dice a
             * quién se atendió ESE día, que es lo que peritan.
             */
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            $tabla->string('numero', 40);

            $tabla->string('tipo', 30);
            $tabla->string('estado', 30)->default('abierto');

            $tabla->foreignId('servicio_id')->nullable()->constrained('servicios')->restrictOnDelete();

            /*
             * El médico tratante cambia con el traslado y con el turno.
             * Acá vive el actual; el histórico es del bloque 11.
             */
            $tabla->foreignId('medico_tratante_id')->nullable()->constrained('users')->nullOnDelete();

            $tabla->string('motivo', 200)->nullable();

            $tabla->timestampTz('abierto_en');

            // ── Los tres tiempos del egreso ───────────────────────────
            $tabla->timestampTz('alta_medica_en')->nullable();
            $tabla->timestampTz('alta_administrativa_en')->nullable();
            $tabla->timestampTz('salida_fisica_en')->nullable();

            $tabla->string('tipo_egreso', 30)->nullable();
            $tabla->timestampTz('cerrado_en')->nullable();

            $tabla->timestampTz('anulado_en')->nullable();
            $tabla->string('motivo_anulacion', 200)->nullable();

            /*
             * Reingreso a menos de 30 días (§9.K14). Es indicador de
             * calidad y moneda de negociación con aseguradoras, y
             * reconstruirlo después es imposible si cada ingreso es una
             * isla.
             */
            $tabla->foreignId('encuentro_anterior_id')->nullable()
                ->constrained('encuentros')->nullOnDelete();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX encuentros_numero_unico ON encuentros (sede_id, numero)'
        );

        /*
         * 🔴 Una sola hospitalización viva por persona.
         *
         * Sin esto, admisión abre un segundo ingreso al paciente que ya
         * está internado —pasa con el traslado entre servicios, y pasa
         * cuando el turno siguiente no encuentra el ingreso porque buscó
         * mal el nombre— y a partir de ahí hay dos cuentas, dos censos y
         * dos verdades. Ambulatorio y emergencia SÍ pueden convivir con
         * una hospitalización: el internado que baja a consulta externa
         * es un hecho normal.
         */
        DB::statement(
            "CREATE UNIQUE INDEX encuentros_una_hospitalizacion_abierta
             ON encuentros (persona_id)
             WHERE tipo = 'hospitalizacion' AND estado IN ('abierto', 'en_atencion', 'alta_medica')"
        );

        /*
         * Índice parcial para la bandeja: consulta el 1 % de la tabla.
         * Uno completo sobre `abierto_en` la haría lenta y enorme (§12).
         */
        DB::statement(
            "CREATE INDEX encuentros_bandeja
             ON encuentros (sede_id, abierto_en DESC)
             WHERE estado IN ('abierto', 'en_atencion', 'alta_medica')"
        );

        DB::statement(
            'CREATE INDEX encuentros_por_persona ON encuentros (persona_id, abierto_en DESC)'
        );

        DB::statement(
            'CREATE INDEX encuentros_por_expediente ON encuentros (expediente_id, abierto_en DESC)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE encuentros ADD CONSTRAINT encuentros_tipo_conocido
             CHECK (tipo IN ('ambulatorio', 'emergencia', 'hospitalizacion', 'cirugia', 'externo'))"
        );

        DB::statement(
            "ALTER TABLE encuentros ADD CONSTRAINT encuentros_estado_conocido
             CHECK (estado IN ('abierto', 'en_atencion', 'alta_medica', 'alta_administrativa', 'cerrado', 'anulado'))"
        );

        DB::statement(
            "ALTER TABLE encuentros ADD CONSTRAINT encuentros_egreso_conocido
             CHECK (tipo_egreso IS NULL OR tipo_egreso IN ('domicilio', 'traslado', 'alta_voluntaria', 'fuga', 'defuncion'))"
        );

        /*
         * El orden de los tres tiempos no es opinable: no se liquida una
         * cuenta de un paciente al que nadie dio de alta, ni se libera
         * una cama antes de la decisión clínica.
         */
        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_alta_administrativa_despues
             CHECK (alta_administrativa_en IS NULL OR alta_medica_en IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_salida_despues
             CHECK (salida_fisica_en IS NULL OR alta_medica_en IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_cierre_completo
             CHECK (estado <> \'cerrado\' OR (cerrado_en IS NOT NULL AND tipo_egreso IS NOT NULL))'
        );

        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_anulacion_completa
             CHECK (estado <> \'anulado\' OR (anulado_en IS NOT NULL AND length(btrim(motivo_anulacion)) >= 10))'
        );

        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_numero_no_vacio
             CHECK (length(btrim(numero)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE encuentros ADD CONSTRAINT encuentros_no_es_su_propio_anterior
             CHECK (encuentro_anterior_id IS NULL OR encuentro_anterior_id <> id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('encuentros');
    }
};
