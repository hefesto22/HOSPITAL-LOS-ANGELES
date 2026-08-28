<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El abono a cuenta: plata que entra antes de que exista la factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ RESUELVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * «El paciente está internado, la cuenta va en 20,000, alguien deposita
 * 5,000 y después 3,000. La factura no se emite hasta que todo esté
 * saldado.»
 *
 * El saldo es DERIVADO y nunca una columna:
 *
 *     saldo = cuentas.total − SUM(abonos aplicados)
 *
 * Guardarlo sería el `UPDATE productos SET existencia` del §9.G1: un
 * número editable que a los tres días deja de corresponder con los
 * hechos y nadie sabe cuándo se desvió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO HAY CHECK `SUM(abonos) <= total`, Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El CLAUDE.md lo lista entre las defensas, y para los pagos de una
 * FACTURA es correcto. Acá no: un anticipo entra el día del ingreso,
 * cuando la cuenta todavía no tiene un solo cargo. Ese CHECK haría
 * imposible recibir los L 5,000 con los que empieza toda cirugía
 * programada.
 *
 * Que sobre plata es un hecho normal y visible —saldo a favor— y se
 * devuelve al egreso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN TURNO ABIERTO NO SE RECIBE PLATA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `turno_id` es NOT NULL. Que el turno esté ABIERTO lo verifica el
 * servicio dentro de la transacción, con la fila bloqueada: es un cruce
 * entre dos tablas y un CHECK no lo puede ver.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE EDITA: SE ANULA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un trigger rechaza cualquier UPDATE que toque el monto, la cuenta, el
 * turno o la fecha de operación, y solo permite `aplicado → anulado`.
 * El recibo ya se imprimió y la familia lo tiene en la mano.
 *
 * ⚠️ Esta tabla NO está particionada, a diferencia de `cargos`. Un
 * hospital hace cientos de abonos al mes, no millones de cargos, y
 * particionar obligaría a que toda FK hacia acá lleve dos columnas
 * —que es exactamente lo que complicó al presupuesto (ADR-0009)—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            $tabla->string('numero', 40);

            $tabla->foreignId('cuenta_id')->constrained('cuentas')->restrictOnDelete();

            $tabla->foreignId('turno_id')->constrained('turnos_de_caja')->restrictOnDelete();

            $tabla->string('estado', 20)->default('aplicado');

            $tabla->decimal('total', 14, 2);

            $tabla->timestampTz('recibido_en');
            $tabla->date('fecha_operacion');

            /*
             * Quién recibió la plata. `created_by` dice quién tecleó, y
             * casi siempre son el mismo — pero el día que no lo sean, el
             * que responde por el billete es este.
             */
            $tabla->foreignId('recibido_por')->constrained('users')->restrictOnDelete();

            /*
             * Quién dejó la plata: no siempre es el paciente. «Lo dejó
             * la hija» es la primera pregunta cuando alguien reclama un
             * recibo perdido.
             */
            $tabla->string('entregado_por', 120)->nullable();

            $tabla->string('nota', 300)->nullable();

            $tabla->timestampTz('anulado_en')->nullable();
            $tabla->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->string('motivo_anulacion', 200)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX abonos_numero_unico ON abonos (sede_id, numero)');

        DB::statement('CREATE INDEX abonos_de_la_cuenta ON abonos (cuenta_id, id)');

        /*
         * El arqueo suma por acá: solo los aplicados del turno.
         */
        DB::statement(
            "CREATE INDEX abonos_del_turno ON abonos (turno_id) WHERE estado = 'aplicado'"
        );

        DB::statement('CREATE INDEX abonos_del_dia ON abonos (sede_id, fecha_operacion DESC)');

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE abonos ADD CONSTRAINT abonos_estado_conocido
             CHECK (estado IN ('aplicado', 'anulado'))"
        );

        /*
         * Un abono de cero es un recibo que no dice nada, y uno negativo
         * es una devolución disfrazada. La devolución es otro hecho y va
         * en el bloque 7.
         */
        DB::statement('ALTER TABLE abonos ADD CONSTRAINT abonos_total_positivo CHECK (total > 0)');

        DB::statement(
            "ALTER TABLE abonos ADD CONSTRAINT abonos_anulacion_completa
             CHECK (estado <> 'anulado' OR (
                 anulado_en IS NOT NULL
                 AND anulado_por IS NOT NULL
                 AND length(btrim(motivo_anulacion)) >= 10
             ))"
        );

        /*
         * 🔴 EL RECIBO NO SE EDITA.
         *
         * Mismo criterio que el cargo (§8.5-5, ADR-0004): el monto, la
         * cuenta, el turno y la fecha de operación quedan escritos. Lo
         * único que puede cambiar es el estado, y en una sola dirección.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sihla_abono_inmutable() RETURNS trigger AS $$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.numero, NEW.cuenta_id, NEW.turno_id,
                    NEW.total, NEW.recibido_en, NEW.fecha_operacion, NEW.recibido_por,
                    NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.numero, OLD.cuenta_id, OLD.turno_id,
                    OLD.total, OLD.recibido_en, OLD.fecha_operacion, OLD.recibido_por,
                    OLD.created_by)
                THEN
                    RAISE EXCEPTION 'El abono % ya está recibido: su monto, su cuenta y su turno no se editan. Se corrige anulándolo (SIHLA §8.5-5).', OLD.numero
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.estado <> NEW.estado AND NOT (OLD.estado = 'aplicado' AND NEW.estado = 'anulado') THEN
                    RAISE EXCEPTION 'Transición no permitida en el abono %: de % a %.', OLD.numero, OLD.estado, NEW.estado
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        /*
         * ⚠️ `CREATE OR REPLACE` y no `CREATE`: `migrate:fresh` borra
         * las tablas pero NO las funciones, así que un `CREATE FUNCTION`
         * pelado se cae en la segunda corrida (lección del kardex).
         */
        DB::statement(
            'CREATE TRIGGER abonos_inmutables
             BEFORE UPDATE ON abonos
             FOR EACH ROW EXECUTE FUNCTION sihla_abono_inmutable()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos');
    }
};
