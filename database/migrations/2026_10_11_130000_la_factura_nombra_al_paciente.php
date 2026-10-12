<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL CLIENTE ES QUIEN PAGA; EL PACIENTE VA APARTE (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «debería haber uno para cliente y otro para paciente. Si
 * tiene seguro, el cliente es el seguro y el paciente siempre está; si
 * no tiene seguro, el cliente es consumidor final».
 *
 * Hasta hoy los dos iban en un solo renglón —«HOSPITAL MILITAR / RAMON
 * ENRIQUE PERDOMO ALVARADO»—, como en el papel viejo. El cliente fiscal
 * quedaba escrito con un nombre que no es el de nadie, y buscar las
 * facturas de un paciente era buscar un pedazo de texto.
 *
 * `paciente_nombre` se CONGELA al emitir, igual que el cliente: el
 * nombre se corrige en el expediente y la factura del año pasado tiene
 * que seguir diciendo lo que decía. Por eso entra al trigger de
 * inmutabilidad, junto con el cliente.
 *
 * Las facturas ya emitidas quedan como estaban —el paciente adentro del
 * nombre del cliente— y sin renglón de paciente. No se rellenan: son
 * documentos fiscales emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->string('paciente_nombre', 200)->nullable();
        });

        /*
         * Vacío no: o hay un nombre, o no hay renglón. Sin piso de tres
         * letras, a diferencia del cliente: el paciente sin identificar
         * de emergencia puede llamarse «NN».
         */
        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_paciente_con_nombre
             CHECK (paciente_nombre IS NULL OR btrim(paciente_nombre) <> '')"
        );

        DB::statement($this->triggerInmutable(conPaciente: true));
    }

    public function down(): void
    {
        /*
         * La función primero: el cuerpo de un plpgsql es texto, y si
         * siguiera nombrando la columna borrada reventaría recién la
         * próxima vez que alguien anule una factura.
         */
        DB::statement($this->triggerInmutable(conPaciente: false));

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_paciente_con_nombre');

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn('paciente_nombre');
        });
    }

    /**
     * El mismo trigger de `2026_08_28_120000`, con el paciente o sin él.
     */
    private function triggerInmutable(bool $conPaciente): string
    {
        $nuevo = $conPaciente ? ', NEW.paciente_nombre' : '';
        $viejo = $conPaciente ? ', OLD.paciente_nombre' : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION sihla_factura_inmutable() RETURNS trigger AS \$\$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.tipo, NEW.numero, NEW.correlativo, NEW.rango_cai_id,
                    NEW.cai, NEW.fecha_limite_emision, NEW.cuenta_id, NEW.cliente_nombre,
                    NEW.cliente_documento, NEW.cliente_documento_tipo{$nuevo}, NEW.emitida_en,
                    NEW.fecha_operacion, NEW.bruto, NEW.descuento_legal, NEW.descuento_comercial,
                    NEW.exento, NEW.gravado, NEW.isv, NEW.total, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.tipo, OLD.numero, OLD.correlativo, OLD.rango_cai_id,
                    OLD.cai, OLD.fecha_limite_emision, OLD.cuenta_id, OLD.cliente_nombre,
                    OLD.cliente_documento, OLD.cliente_documento_tipo{$viejo}, OLD.emitida_en,
                    OLD.fecha_operacion, OLD.bruto, OLD.descuento_legal, OLD.descuento_comercial,
                    OLD.exento, OLD.gravado, OLD.isv, OLD.total, OLD.created_by)
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
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
