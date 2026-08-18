<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expedientes — la carpeta del paciente EN UNA SEDE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA DISTINCIÓN QUE HACE FUNCIONAR TODO ESTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * PERSONA es quién es. Es única para toda la organización, no tiene
 * `sede_id`, y de ella cuelga lo que es cierto en cualquier lugar:
 * alergias, grupo sanguíneo, enfermedades crónicas, medicación habitual.
 *
 * EXPEDIENTE es la carpeta que una sede abre para atenderla. Tiene número
 * propio, ubicación física, plazo de conservación, y de él cuelga lo que
 * ocurrió AHÍ: encuentros, notas de evolución, órdenes, cargos.
 *
 * Con eso, el paciente que llega a la segunda sede ve toda su historia —
 * porque la historia se arma por persona— y cada sede conserva su archivo
 * legal, que es lo que SESAL habilita y audita POR ESTABLECIMIENTO.
 *
 * La alternativa (un solo expediente para toda la organización) suena más
 * simple y no lo es: obliga a un contador global —una sede esperando a la
 * otra para abrir una carpeta en emergencia— y deja sin representar la
 * carpeta física, que existe, tiene un estante y un plazo de destrucción.
 *
 * ⚠️ CONSECUENCIA DE PRIVACIDAD, a la vista para que no se olvide:
 *
 * Si la historia se ve entre sedes, entonces alguien de la sede 2 puede
 * leer lo que escribió la sede 1. Eso es correcto clínicamente y es
 * exactamente lo que el §9 obliga a registrar: ese acceso va a la bitácora
 * de LECTURA como cualquier otro, y es de los que más vale la pena revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedientes', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            /*
             * `restrictOnDelete` y no cascade: un expediente con historia
             * clínica es lo que impide borrar a la persona. Que borrar una
             * persona se lleve por delante su expediente es exactamente lo
             * contrario del ADR-0004.
             */
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            /*
             * El número que sale del AsignadorDeCorrelativo:
             * EXP-HLA-00000001. Lleva el código de la sede adentro, así que
             * es único en toda la organización aunque cada sede numere por
             * su cuenta.
             */
            $tabla->string('numero', 40);

            $tabla->date('abierto_el');
            $tabla->string('estado', 20)->default('activo');

            /*
             * Lo que decide si la carpeta va al archivo pasivo. Se actualiza
             * en cada atención; sin esto, el estado habría que calcularlo
             * recorriendo todos los encuentros del paciente cada vez que
             * alguien abre el listado de archivo.
             */
            $tabla->timestampTz('ultima_atencion_el')->nullable();

            /*
             * Dónde está la carpeta de papel. Parece un detalle hasta que
             * alguien tiene que ir a buscarla a un archivo con veinte mil
             * expedientes.
             */
            $tabla->string('ubicacion_fisica', 60)->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['sede_id', 'estado'], 'expedientes_sede_estado_index');
        });

        /*
         * Un solo expediente por persona y por sede.
         *
         * Sin esto, dos personas de admisión atendiendo al mismo paciente
         * el mismo día abren dos carpetas con dos números, y a partir de
         * ahí la mitad de las notas van a una y la mitad a la otra.
         */
        DB::statement(
            'CREATE UNIQUE INDEX expedientes_persona_sede_unico
             ON expedientes (persona_id, sede_id)
             WHERE deleted_at IS NULL'
        );

        /*
         * El número es único en TODA la organización, no por sede: ya lleva
         * el código de la sede adentro. Que dos sedes puedan emitir el mismo
         * texto haría inútil buscar por número.
         */
        DB::statement(
            'CREATE UNIQUE INDEX expedientes_numero_unico
             ON expedientes (numero)
             WHERE deleted_at IS NULL'
        );

        DB::statement(
            "ALTER TABLE expedientes ADD CONSTRAINT expedientes_numero_no_vacio
             CHECK (btrim(numero) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('expedientes');
    }
};
