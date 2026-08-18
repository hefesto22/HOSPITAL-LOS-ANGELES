<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fusiones de duplicados — el expediente de la decisión (§9.D4).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO ES UNA TABLA Y NO UN BOTÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * `personas.merged_into` ya alcanza para UNIR. Lo que no alcanza es para
 * responder las preguntas que se hacen después: quién lo pidió, quién lo
 * autorizó, con qué motivo, y si alguien lo deshizo.
 *
 * El §9.D4 exige doble aprobación, y una aprobación sin registro no es
 * una aprobación. Por eso la fusión tiene ciclo de vida propio:
 *
 *     propuesta ──aprobar──▶ aplicada ──deshacer──▶ deshecha
 *          └────rechazar───▶ rechazada
 *
 * Mientras está en `propuesta` NO HA PASADO NADA: las dos personas siguen
 * separadas. Recién al aprobar se escribe el puntero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CONTROL DE CUATRO OJOS VIVE EN LA BASE
 * ─────────────────────────────────────────────────────────────────────
 *
 * `CHECK (resuelta_por <> propuesta_por)`. No está solo en el servicio, y
 * es deliberado: un control de cuatro ojos que vive únicamente en el
 * código deja de existir en cuanto alguien escribe un seeder, un comando
 * de migración de datos o un `tinker` apurado. Si la regla importa lo
 * suficiente como para exigir dos personas, importa lo suficiente como
 * para que la base la haga cumplir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fusiones_de_persona', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * `restrictOnDelete` en las dos: el expediente de la decisión
             * no puede quedar huérfano. Es exactamente lo que se consulta
             * cuando alguien pregunta por qué dos historias clínicas
             * terminaron siendo una.
             */
            $tabla->foreignId('persona_duplicada_id')->constrained('personas')->restrictOnDelete();
            $tabla->foreignId('persona_sobreviviente_id')->constrained('personas')->restrictOnDelete();

            $tabla->string('estado', 20)->default('propuesta');

            /*
             * Obligatorio. "Duplicado" no es un motivo: lo que sirve
             * después es "mismo DNI, misma fecha de nacimiento, el de
             * 2024 se creó por error de admisión".
             */
            $tabla->string('motivo');

            $tabla->foreignId('propuesta_por')->constrained('users')->restrictOnDelete();
            $tabla->timestampTz('propuesta_en');

            $tabla->foreignId('resuelta_por')->nullable()->constrained('users')->restrictOnDelete();
            $tabla->timestampTz('resuelta_en')->nullable();
            $tabla->string('resolucion_nota')->nullable();

            $tabla->foreignId('deshecha_por')->nullable()->constrained('users')->restrictOnDelete();
            $tabla->timestampTz('deshecha_en')->nullable();
            $tabla->string('deshecha_motivo')->nullable();

            $tabla->timestamps();

            $tabla->index(['estado', 'propuesta_en'], 'fusiones_estado_index');
            $tabla->index('persona_sobreviviente_id', 'fusiones_sobreviviente_index');
        });

        /*
         * Una persona no se fusiona consigo misma. Sin esto se podría
         * proponer, y aprobarlo dejaría `merged_into = id`: un ciclo que
         * cuelga cualquier recorrido de la cadena.
         */
        DB::statement(
            'ALTER TABLE fusiones_de_persona ADD CONSTRAINT fusiones_personas_distintas
             CHECK (persona_duplicada_id <> persona_sobreviviente_id)'
        );

        /*
         * ⚠️ El control de cuatro ojos. Ver el encabezado.
         */
        DB::statement(
            'ALTER TABLE fusiones_de_persona ADD CONSTRAINT fusiones_doble_aprobacion
             CHECK (resuelta_por IS NULL OR resuelta_por <> propuesta_por)'
        );

        /*
         * El estado y sus campos van juntos. Una fusión "aplicada" sin
         * quién la aprobó ni cuándo es una fusión que nadie puede
         * auditar, que es lo mismo que no tener el registro.
         */
        DB::statement(
            "ALTER TABLE fusiones_de_persona ADD CONSTRAINT fusiones_resolucion_completa
             CHECK (
                 (estado = 'propuesta' AND resuelta_por IS NULL AND resuelta_en IS NULL)
                 OR (estado IN ('aplicada', 'rechazada', 'deshecha')
                     AND resuelta_por IS NOT NULL AND resuelta_en IS NOT NULL)
             )"
        );

        DB::statement(
            "ALTER TABLE fusiones_de_persona ADD CONSTRAINT fusiones_deshacer_completo
             CHECK (
                 estado <> 'deshecha'
                 OR (deshecha_por IS NOT NULL AND deshecha_en IS NOT NULL
                     AND deshecha_motivo IS NOT NULL AND length(btrim(deshecha_motivo)) >= 10)
             )"
        );

        DB::statement(
            'ALTER TABLE fusiones_de_persona ADD CONSTRAINT fusiones_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );

        /*
         * Una sola propuesta abierta por persona duplicada.
         *
         * Dos propuestas simultáneas sobre el mismo paciente hacia
         * sobrevivientes distintos es una contradicción que alguien
         * tendría que resolver a mano, y mientras tanto quien apruebe la
         * segunda no sabe que existía la primera.
         */
        DB::statement(
            "CREATE UNIQUE INDEX fusiones_una_propuesta_abierta
             ON fusiones_de_persona (persona_duplicada_id)
             WHERE estado = 'propuesta'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fusiones_de_persona');
    }
};
