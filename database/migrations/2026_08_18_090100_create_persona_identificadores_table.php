<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identificadores de una persona — 1..N, nunca una columna en `personas`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA DECISIÓN DIFÍCIL DE ESTA TABLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * ¿El DNI debe ser único?
 *
 * Las dos respuestas obvias están mal:
 *
 *  ✗ UNIQUE duro. Son las 3 de la mañana, entra un accidentado, admisión
 *    digita el DNI y la base lo rechaza porque ya existe (alguien lo
 *    digitó mal ayer, o es el DNI de un familiar). El paciente NO SE
 *    PUEDE REGISTRAR. Lo que pasa en la vida real es que la persona de
 *    admisión inventa un número para que el sistema la deje seguir, y
 *    ahora hay basura permanente en el expediente. Un sistema hospitalario
 *    no puede tener un camino en el que la respuesta correcta sea mentir.
 *
 *  ✗ Sin unicidad. El mismo paciente se registra dos veces la semana
 *    entrante y termina con dos expedientes, dos listas de alergias y dos
 *    historias de medicación. Ese es el error que mata gente.
 *
 * La solución es un ÍNDICE ÚNICO PARCIAL con una salida explícita:
 *
 *    UNIQUE (tipo, país, valor) WHERE NOT en_conflicto AND NOT borrado
 *
 * El camino normal está protegido: un DNI, una persona. Cuando hay
 * colisión, admisión marca el nuevo registro como `en_conflicto = true`,
 * escribe qué pasó, y sigue atendiendo. El registro en conflicto sale en
 * una bandeja de revisión hasta que alguien lo resuelve.
 *
 * La diferencia con inventar un número es que acá el conflicto queda
 * REGISTRADO como conflicto, con nombre y hora, en vez de disfrazado de
 * dato bueno.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL PAÍS FORMA PARTE DE LA LLAVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un número de pasaporte solo es único dentro del país que lo emitió. Sin
 * el país en la llave, el segundo turista con el mismo número choca contra
 * el primero. Como `pais_emision` es nulo para los documentos nacionales,
 * la llave usa COALESCE — en PostgreSQL NULL ≠ NULL, así que sin eso el
 * índice único no aplicaría a ninguna fila con país nulo, que son casi
 * todas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_identificadores', function (Blueprint $table): void {
            $table->id();

            /*
             * `restrictOnDelete`, no cascade: los identificadores son
             * evidencia de identidad. Que borrar una persona se lleve
             * silenciosamente la prueba de quién era es exactamente lo
             * contrario de lo que pide el ADR-0004.
             */
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            $table->string('tipo', 30);

            /*
             * Valor NORMALIZADO: DNI y RTN solo dígitos, el resto en
             * mayúsculas sin espacios. Es lo que se compara y lo que se
             * indexa. Lo hace TipoIdentificador::normalizar().
             */
            $table->string('valor', 40);

            /*
             * Tal como lo digitaron o como viene impreso. Sirve para que
             * quien revise un conflicto vea qué escribió la otra persona,
             * y para reimprimir un documento exactamente igual.
             */
            $table->string('valor_original', 60)->nullable();

            $table->char('pais_emision', 2)->nullable();

            /*
             * El documento con el que se factura y con el que se busca
             * primero. Uno por persona, garantizado por índice.
             */
            $table->boolean('es_principal')->default(false);

            $table->date('emitido_el')->nullable();
            $table->date('vence_el')->nullable();

            /*
             * ¿Alguien tuvo el documento FÍSICO en la mano?
             *
             * No es lo mismo un DNI que el paciente dictó por teléfono
             * que uno que admisión vio y fotocopió. Para facturar con RTN
             * y para reclamar a una aseguradora, la diferencia importa.
             */
            $table->timestampTz('verificado_en')->nullable();
            $table->foreignId('verificado_por')->nullable()
                ->constrained('users')->nullOnDelete();

            // ── La salida de emergencia ───────────────────────────────
            $table->boolean('en_conflicto')->default(false);
            $table->string('conflicto_nota')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * La consulta más caliente del sistema: "¿existe alguien con
             * este número?", que corre ANTES de crear cualquier paciente.
             * Va por (tipo, valor) porque el mismo número puede ser un
             * DNI de alguien y una póliza de otro.
             */
            $table->index(['tipo', 'valor'], 'persona_identificadores_busqueda_index');
            $table->index('persona_id', 'persona_identificadores_persona_index');
        });

        // Ver el bloque grande de arriba: acá está el porqué de cada pieza.
        DB::statement(
            "CREATE UNIQUE INDEX persona_identificadores_unico
             ON persona_identificadores (tipo, COALESCE(pais_emision, '--'), valor)
             WHERE deleted_at IS NULL AND en_conflicto = false"
        );

        /*
         * Un solo documento principal por persona. Sin esto, dos
         * documentos marcados como principales hacen que la factura salga
         * con uno u otro según cuál devuelva primero el motor — y una
         * factura con el RTN equivocado es un problema con el SAR, no un
         * detalle cosmético.
         */
        DB::statement(
            'CREATE UNIQUE INDEX persona_identificadores_un_principal
             ON persona_identificadores (persona_id)
             WHERE es_principal = true AND deleted_at IS NULL'
        );

        DB::statement(
            "ALTER TABLE persona_identificadores
             ADD CONSTRAINT persona_identificadores_valor_no_vacio
             CHECK (btrim(valor) <> '')"
        );

        /*
         * Un documento que vence antes de emitirse es un error de captura.
         * Es barato atraparlo acá y caro descubrirlo cuando la aseguradora
         * rechaza el reclamo.
         */
        DB::statement(
            'ALTER TABLE persona_identificadores
             ADD CONSTRAINT persona_identificadores_vigencia_coherente
             CHECK (vence_el IS NULL OR emitido_el IS NULL OR vence_el >= emitido_el)'
        );

        /*
         * Marcar algo como conflicto sin decir por qué deja a quien
         * revisa la bandeja sin nada con qué trabajar.
         */
        DB::statement(
            'ALTER TABLE persona_identificadores
             ADD CONSTRAINT persona_identificadores_conflicto_explicado
             CHECK (en_conflicto = false
                 OR (conflicto_nota IS NOT NULL AND length(btrim(conflicto_nota)) >= 10))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_identificadores');
    }
};
