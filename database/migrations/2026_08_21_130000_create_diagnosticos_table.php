<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CON QUÉ ENTRÓ Y CON QUÉ SALIÓ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES EL DATO QUE HOY FALTA PARA TODO LO DEMÁS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El sistema ya sabe QUÉ se le cobró al paciente y QUIÉN se lo dio. No
 * sabe POR QUÉ. Sin eso:
 *
 *   · la aseguradora no procesa el reclamo — el diagnóstico es contra lo
 *     que evalúa si lo cobrado tiene sentido;
 *   · el Art. 180 del Código de Salud queda incumplido: la notificación
 *     epidemiológica es obligación legal directa, no reportería opcional;
 *   · y el hospital no puede contestar de qué atiende, que es la mitad
 *     de cualquier indicador.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CUELGA DEL ENCUENTRO, NO DE LA CUENTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La cuenta es el documento de cobro; el encuentro es el hecho clínico.
 * Hoy van uno a uno (ADR-0007), pero el diagnóstico sobrevive a la
 * factura: se consulta veinte años después, cuando la cuenta ya se pagó
 * y se archivó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 APPEND-ONLY (ADR-0004)
 * ─────────────────────────────────────────────────────────────────────
 *
 * No se edita. Se corrige escribiendo otro que apunta al anterior, y el
 * anterior queda legible y tachado. Lo que un perito busca no es el
 * diagnóstico final: es qué se pensó, cuándo se cambió de idea y quién
 * lo cambió. Un UPDATE borra exactamente eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('encuentro_id')->constrained('encuentros')->restrictOnDelete();
            $tabla->foreignId('cie10_id')->constrained('cie10')->restrictOnDelete();

            $tabla->string('tipo', 20);
            $tabla->string('momento', 20);
            $tabla->string('estado', 20)->default('vigente');

            /*
             * Al ingreso casi nunca hay certeza. Guardar un presuntivo
             * como si fuera confirmado es lo que después hace que las
             * estadísticas del hospital cuenten apendicitis que nunca
             * existieron.
             */
            $tabla->boolean('confirmado')->default(false);

            /* Lo que el médico quiera agregar en sus palabras. */
            $tabla->text('observacion')->nullable();

            /*
             * 🔴 Quién y cuándo, obligatorios. §8.8-5: cada anotación
             * lleva fecha, hora y responsable. Un diagnóstico sin autor
             * no sirve de prueba ni de nada.
             */
            $tabla->foreignId('diagnosticado_por')->constrained('users')->restrictOnDelete();
            $tabla->timestampTz('diagnosticado_en');

            /* La enmienda: a cuál reemplaza y por qué. */
            $tabla->foreignId('corrige_a_id')->nullable()
                ->constrained('diagnosticos')->restrictOnDelete();
            $tabla->string('motivo_correccion', 200)->nullable();

            $tabla->timestamps();
        });

        /*
         * 🔴 UN SOLO PRINCIPAL VIGENTE POR MOMENTO.
         *
         * Dos principales al egreso es una cuenta que la aseguradora no
         * sabe contra cuál evaluar, y una notificación epidemiológica que
         * cuenta el caso dos veces. El índice es parcial porque los
         * corregidos y los retractados SÍ conviven — son la historia.
         */
        DB::statement(
            "CREATE UNIQUE INDEX diagnosticos_un_principal_por_momento
             ON diagnosticos (encuentro_id, momento)
             WHERE tipo = 'principal' AND estado = 'vigente'"
        );

        /* El mismo código no se repite vigente en el mismo momento. */
        DB::statement(
            "CREATE UNIQUE INDEX diagnosticos_sin_repetir_codigo
             ON diagnosticos (encuentro_id, momento, cie10_id)
             WHERE estado = 'vigente'"
        );

        DB::statement(
            "ALTER TABLE diagnosticos
             ADD CONSTRAINT diagnosticos_tipo_conocido
             CHECK (tipo IN ('principal', 'secundario'))"
        );

        DB::statement(
            "ALTER TABLE diagnosticos
             ADD CONSTRAINT diagnosticos_momento_conocido
             CHECK (momento IN ('ingreso', 'egreso'))"
        );

        DB::statement(
            "ALTER TABLE diagnosticos
             ADD CONSTRAINT diagnosticos_estado_conocido
             CHECK (estado IN ('vigente', 'corregido', 'retractado'))"
        );

        /*
         * Nadie se corrige a sí mismo, y lo que dejó de estar vigente
         * tiene que decir por qué. Un diagnóstico tachado sin motivo es
         * peor que no tacharlo: deja la duda sin la explicación.
         */
        DB::statement(
            'ALTER TABLE diagnosticos
             ADD CONSTRAINT diagnosticos_no_se_corrige_a_si_mismo
             CHECK (corrige_a_id IS NULL OR corrige_a_id <> id)'
        );

        DB::statement(
            "ALTER TABLE diagnosticos
             ADD CONSTRAINT diagnosticos_enmienda_explicada
             CHECK (estado = 'vigente' OR length(btrim(coalesce(motivo_correccion, ''))) >= 10)"
        );

        DB::statement(
            'CREATE INDEX diagnosticos_del_encuentro
             ON diagnosticos (encuentro_id, momento)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos');
    }
};
