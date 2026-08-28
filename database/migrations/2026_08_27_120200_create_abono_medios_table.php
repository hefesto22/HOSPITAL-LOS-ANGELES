<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué se pagó cada parte de un abono.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PAGO MIXTO ES ESTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Una parte en tarjeta y otra en efectivo»: UN recibo de L 5,000 con
 * DOS medios, 3,000 y 2,000. No es una forma de pago llamada «mixto» —
 * con un enum así habría que guardar en otro lado cuánto fue de cada
 * cosa, y en el arqueo nadie sabría cuánto efectivo debe haber en la
 * gaveta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO LO QUE EL MOSTRADOR VA A TECLEAR DE VERDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Efectivo      → nada más que el monto.
 *   · Tarjeta       → nada más que el monto. Decisión del hospital: el
 *                     voucher se queda en el papel del POS, que se
 *                     archiva. **No se guarda ningún dato de la tarjeta.**
 *   · Transferencia → el BANCO al que se depositó, elegido de la lista
 *                     de `sihla.caja.bancos`. Es lo que hace falta para
 *                     ir a buscar el depósito al estado de cuenta que
 *                     corresponde.
 *
 * ⚠️ Sin número de referencia, cuadrar un depósito contra el estado de
 * cuenta se hace por banco, monto y fecha. Alcanza mientras la boleta
 * física se archive; el día que se quiera conciliación automática va a
 * hacer falta esa columna.
 *
 * ⚠️ Que la suma de los medios sea igual al total del abono NO puede ser
 * un CHECK: es un cruce entre dos tablas. Lo verifica el servicio dentro
 * de la misma transacción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abono_medios', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('abono_id')->constrained('abonos')->cascadeOnDelete();

            $tabla->string('forma', 20);

            $tabla->decimal('monto', 14, 2);

            /*
             * El nombre del banco queda ESCRITO acá, no como llave a una
             * tabla: el recibo tiene que seguir diciendo a qué banco se
             * depositó ese día aunque el hospital cierre esa cuenta el
             * año que viene.
             */
            $tabla->string('banco', 60)->nullable();

            $tabla->timestamps();
        });

        DB::statement('CREATE INDEX abono_medios_del_abono ON abono_medios (abono_id)');

        DB::statement('CREATE INDEX abono_medios_por_forma ON abono_medios (forma, abono_id)');

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE abono_medios ADD CONSTRAINT abono_medios_forma_conocida
             CHECK (forma IN ('efectivo', 'tarjeta', 'transferencia'))"
        );

        DB::statement('ALTER TABLE abono_medios ADD CONSTRAINT abono_medios_monto_positivo CHECK (monto > 0)');

        /*
         * Un depósito sin banco es un depósito que nadie va a encontrar:
         * hay que saber en qué estado de cuenta buscarlo.
         */
        DB::statement(
            "ALTER TABLE abono_medios ADD CONSTRAINT abono_medios_transferencia_con_banco
             CHECK (forma <> 'transferencia' OR length(btrim(coalesce(banco, ''))) >= 1)"
        );

        /*
         * Y el banco es SOLO de la transferencia. Un «BAC» colgando de
         * una fila en efectivo no significa nada, y a los seis meses
         * nadie sabe si quiso decir algo.
         */
        DB::statement(
            "ALTER TABLE abono_medios ADD CONSTRAINT abono_medios_banco_solo_en_transferencia
             CHECK (forma = 'transferencia' OR banco IS NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('abono_medios');
    }
};
