<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El documento fiscal: lo que el paciente se lleva y el SAR audita.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 TODO SE CONGELA ACÁ. TODO.
 * ─────────────────────────────────────────────────────────────────────
 *
 * El CAI, la fecha límite, el nombre y el RTN del cliente, los totales y
 * cada renglón. NADA se lee por relación al reimprimir: una factura de
 * hace ocho meses tiene que salir idéntica aunque después se haya
 * cambiado el nombre del paciente, el precio del ítem o el CAI del
 * hospital.
 *
 * Es la misma regla del cargo (§8.5-5) llevada al extremo, porque acá el
 * papel ya salió por la impresora y está en la mano de alguien.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ANULAR NO BORRA NI LIBERA EL NÚMERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una factura anulada se queda con su número consumido, su estado y su
 * motivo. El SAR audita la SECUENCIA: un número que falta es una factura
 * que alguien escondió, y eso se explica peor que un error.
 *
 * ⚠️ Para el cliente, deshacer una factura ya entregada no es anularla:
 * es una NOTA DE CRÉDITO, que es otro documento con su propio rango.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN FK A `cargos`, OTRA VEZ
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cargos` está particionada por año con PK `(id, fecha_operacion)`, así
 * que cualquier FK hacia ella exige las dos columnas. El renglón guarda
 * `cargo_id` suelto, sin FK, igual que hizo el presupuesto (ADR-0009):
 * sirve para rastrear, y el documento no depende de él para reimprimirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            $tabla->string('tipo', 20);
            $tabla->string('estado', 20)->default('emitida');

            /* El número completo, ya armado: 000-001-01-00000001 */
            $tabla->string('numero', 21);
            $tabla->unsignedBigInteger('correlativo');

            $tabla->foreignId('rango_cai_id')->constrained('rangos_cai')->restrictOnDelete();

            /* Congelados del rango: la reimpresión no los vuelve a leer. */
            $tabla->string('cai', 40);
            $tabla->date('fecha_limite_emision');

            $tabla->foreignId('cuenta_id')->constrained('cuentas')->restrictOnDelete();
            $tabla->foreignId('encuentro_id')->nullable()->constrained('encuentros')->restrictOnDelete();
            $tabla->foreignId('persona_id')->nullable()->constrained('personas')->restrictOnDelete();
            $tabla->foreignId('convenio_id')->nullable()->constrained('convenios')->restrictOnDelete();

            /*
             * El cliente, congelado. No siempre es el paciente: la
             * factura puede ir a nombre de la empresa que lo manda o del
             * familiar que paga, y ese nombre es el que el SAR mira.
             */
            $tabla->string('cliente_nombre', 200);
            $tabla->string('cliente_rtn', 20)->nullable();
            $tabla->string('cliente_direccion', 250)->nullable();

            $tabla->timestampTz('emitida_en');
            $tabla->date('fecha_operacion');

            // ── Totales congelados ────────────────────────────────────
            $tabla->decimal('bruto', 14, 2)->default(0);
            $tabla->decimal('descuento_legal', 14, 2)->default(0);
            $tabla->decimal('descuento_comercial', 14, 2)->default(0);
            $tabla->decimal('exento', 14, 2)->default(0);
            $tabla->decimal('gravado', 14, 2)->default(0);
            $tabla->decimal('isv', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2)->default(0);

            $tabla->integer('lineas')->default(0);

            $tabla->string('nota', 300)->nullable();

            $tabla->timestampTz('anulada_en')->nullable();
            $tabla->foreignId('anulada_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->string('motivo_anulacion', 200)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * 🔴 El número es único en TODA la instalación, no por sede: el
         * establecimiento ya es el primer segmento, así que dos sedes no
         * pueden producir el mismo número salvo por un error de carga —y
         * ese error tiene que reventar acá, no en la auditoría.
         */
        DB::statement('CREATE UNIQUE INDEX facturas_numero_unico ON facturas (numero)');

        DB::statement('CREATE UNIQUE INDEX facturas_correlativo_unico ON facturas (rango_cai_id, correlativo)');

        DB::statement('CREATE INDEX facturas_de_la_cuenta ON facturas (cuenta_id, id)');
        DB::statement('CREATE INDEX facturas_del_dia ON facturas (sede_id, fecha_operacion DESC)');
        DB::statement('CREATE INDEX facturas_por_rtn ON facturas (cliente_rtn) WHERE cliente_rtn IS NOT NULL');

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_tipo_conocido
             CHECK (tipo IN ('factura', 'nota_de_credito', 'nota_de_debito'))"
        );

        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_estado_conocido
             CHECK (estado IN ('emitida', 'anulada'))"
        );

        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_numero_con_formato
             CHECK (numero ~ '^[0-9]{3}-[0-9]{3}-[0-9]{2}-[0-9]{8}$')"
        );

        /*
         * El mismo cruce que verifica la cuenta y el presupuesto. Si no
         * cuadra, no llega a existir: una factura que no suma es una
         * factura que alguien va a tener que explicar con el papel en la
         * mano.
         */
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_totales_cuadran
             CHECK (total = exento + gravado + isv)'
        );

        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_bruto_cuadra
             CHECK (exento + gravado = bruto - descuento_legal - descuento_comercial)'
        );

        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_anulacion_completa
             CHECK (estado <> 'anulada' OR (
                 anulada_en IS NOT NULL
                 AND anulada_por IS NOT NULL
                 AND length(btrim(motivo_anulacion)) >= 10
             ))"
        );

        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_cliente_con_nombre
             CHECK (length(btrim(cliente_nombre)) >= 3)'
        );

        /*
         * 🔴 EL DOCUMENTO FISCAL NO SE EDITA.
         *
         * Ni el número, ni el CAI, ni el cliente, ni un centavo de los
         * totales. Lo único que puede cambiar es el estado, y en una sola
         * dirección.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sihla_factura_inmutable() RETURNS trigger AS $$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.tipo, NEW.numero, NEW.correlativo, NEW.rango_cai_id,
                    NEW.cai, NEW.fecha_limite_emision, NEW.cuenta_id, NEW.cliente_nombre,
                    NEW.cliente_rtn, NEW.emitida_en, NEW.fecha_operacion, NEW.bruto,
                    NEW.descuento_legal, NEW.descuento_comercial, NEW.exento, NEW.gravado,
                    NEW.isv, NEW.total, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.tipo, OLD.numero, OLD.correlativo, OLD.rango_cai_id,
                    OLD.cai, OLD.fecha_limite_emision, OLD.cuenta_id, OLD.cliente_nombre,
                    OLD.cliente_rtn, OLD.emitida_en, OLD.fecha_operacion, OLD.bruto,
                    OLD.descuento_legal, OLD.descuento_comercial, OLD.exento, OLD.gravado,
                    OLD.isv, OLD.total, OLD.created_by)
                THEN
                    RAISE EXCEPTION 'La factura % ya está emitida: su número, su CAI, su cliente y sus montos no se editan. Se corrige con una nota de crédito.', OLD.numero
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.estado <> NEW.estado AND NOT (OLD.estado = 'emitida' AND NEW.estado = 'anulada') THEN
                    RAISE EXCEPTION 'Transición no permitida en la factura %: de % a %.', OLD.numero, OLD.estado, NEW.estado
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(
            'CREATE TRIGGER facturas_inmutables
             BEFORE UPDATE ON facturas
             FOR EACH ROW EXECUTE FUNCTION sihla_factura_inmutable()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
