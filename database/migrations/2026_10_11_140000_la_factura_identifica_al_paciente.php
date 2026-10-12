<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL PACIENTE SALE CON SU IDENTIDAD Y SU PÓLIZA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si el paciente tiene identidad registrada, que se imprima»,
 * y «en código de cliente, el código que tiene el paciente en el
 * sistema» —el número de expediente—.
 *
 * El «Código de Cliente» del papel venía siendo la póliza. Ahora es el
 * expediente, así que la póliza se muda al renglón del paciente, que es
 * de quien es: la aseguradora busca al asegurado por ella, y sin ella
 * no reembolsa.
 *
 * Las tres columnas se congelan al emitir, igual que el nombre: una
 * identidad corregida en el expediente no reescribe la factura del año
 * pasado. Por eso entran al trigger de inmutabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->string('paciente_documento', 30)->nullable();
            $tabla->string('paciente_documento_tipo', 30)->nullable();
            $tabla->string('paciente_poliza', 40)->nullable();
        });

        /*
         * Un número sin tipo no se puede rotular en el papel, y un tipo
         * sin número no identifica a nadie: van juntos o no van. Misma
         * regla que el documento del cliente.
         */
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_documento_del_paciente_completo
             CHECK ((paciente_documento IS NULL) = (paciente_documento_tipo IS NULL))'
        );

        DB::statement($this->triggerInmutable(conIdentidad: true));
    }

    public function down(): void
    {
        /*
         * La función primero: su cuerpo es texto, y si siguiera
         * nombrando las columnas borradas reventaría recién la próxima
         * vez que alguien anule una factura.
         */
        DB::statement($this->triggerInmutable(conIdentidad: false));

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_documento_del_paciente_completo');

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['paciente_documento', 'paciente_documento_tipo', 'paciente_poliza']);
        });
    }

    /**
     * El trigger de `2026_10_11_130000`, con la identidad y la póliza
     * del paciente o sin ellas.
     */
    private function triggerInmutable(bool $conIdentidad): string
    {
        $nuevo = $conIdentidad
            ? ', NEW.paciente_documento, NEW.paciente_documento_tipo, NEW.paciente_poliza'
            : '';
        $viejo = $conIdentidad
            ? ', OLD.paciente_documento, OLD.paciente_documento_tipo, OLD.paciente_poliza'
            : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION sihla_factura_inmutable() RETURNS trigger AS \$\$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.tipo, NEW.numero, NEW.correlativo, NEW.rango_cai_id,
                    NEW.cai, NEW.fecha_limite_emision, NEW.cuenta_id, NEW.cliente_nombre,
                    NEW.cliente_documento, NEW.cliente_documento_tipo, NEW.paciente_nombre{$nuevo},
                    NEW.emitida_en, NEW.fecha_operacion, NEW.bruto, NEW.descuento_legal,
                    NEW.descuento_comercial, NEW.exento, NEW.gravado, NEW.isv, NEW.total, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.tipo, OLD.numero, OLD.correlativo, OLD.rango_cai_id,
                    OLD.cai, OLD.fecha_limite_emision, OLD.cuenta_id, OLD.cliente_nombre,
                    OLD.cliente_documento, OLD.cliente_documento_tipo, OLD.paciente_nombre{$viejo},
                    OLD.emitida_en, OLD.fecha_operacion, OLD.bruto, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.exento, OLD.gravado, OLD.isv, OLD.total, OLD.created_by)
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
