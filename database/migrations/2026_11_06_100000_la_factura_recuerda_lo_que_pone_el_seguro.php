<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto de la factura queda a cargo del seguro, congelado al emitir
 * (Mauricio, 6-nov-2026: el reporte del mes dice «cuánto se dio a cada
 * seguro»).
 *
 * Hasta acá ese número vivía en dos lugares que no sirven para sumar: como
 * texto en «Comentarios» («COPAGO … · REEMBOLSO …») y en la cuenta, que se
 * reabre si la factura se anula. Va en la factura, junto a los otros
 * montos, y entra al trigger de inmutabilidad: lo que se le facturó a un
 * seguro no cambia después.
 *
 * Las facturas que ya existen lo toman de su cuenta, recortado al total,
 * como lo hace `EmisorDeFactura::aCargoDelSeguro()`. En una anulada la
 * cuenta pudo cambiar después; no importa: las anuladas no suman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->decimal('a_cargo_del_seguro', 14, 2)->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE facturas f
               SET a_cargo_del_seguro = LEAST(GREATEST(ROUND(c.total_aseguradora, 2), 0), GREATEST(f.total, 0))
              FROM cuentas c, convenios v
             WHERE c.id = f.cuenta_id
               AND v.id = f.convenio_id
               AND v.tipo NOT IN ('contado', 'reembolso')
            SQL);

        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_a_cargo_del_seguro_valido
             CHECK (a_cargo_del_seguro >= 0 AND a_cargo_del_seguro <= GREATEST(total, 0))'
        );

        DB::statement($this->triggerInmutable(conElSeguro: true));
    }

    public function down(): void
    {
        DB::statement($this->triggerInmutable(conElSeguro: false));

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_a_cargo_del_seguro_valido');

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn('a_cargo_del_seguro');
        });
    }

    /**
     * El trigger de `2026_10_11_140000`, con la columna nueva entre los
     * montos que no se editan.
     */
    private function triggerInmutable(bool $conElSeguro): string
    {
        $nuevo = $conElSeguro ? ', NEW.a_cargo_del_seguro' : '';
        $viejo = $conElSeguro ? ', OLD.a_cargo_del_seguro' : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION sihla_factura_inmutable() RETURNS trigger AS \$\$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.tipo, NEW.numero, NEW.correlativo, NEW.rango_cai_id,
                    NEW.cai, NEW.fecha_limite_emision, NEW.cuenta_id, NEW.cliente_nombre,
                    NEW.cliente_documento, NEW.cliente_documento_tipo, NEW.paciente_nombre,
                    NEW.paciente_documento, NEW.paciente_documento_tipo, NEW.paciente_poliza,
                    NEW.emitida_en, NEW.fecha_operacion, NEW.bruto, NEW.descuento_legal,
                    NEW.descuento_comercial, NEW.exento, NEW.gravado, NEW.isv, NEW.total{$nuevo}, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.tipo, OLD.numero, OLD.correlativo, OLD.rango_cai_id,
                    OLD.cai, OLD.fecha_limite_emision, OLD.cuenta_id, OLD.cliente_nombre,
                    OLD.cliente_documento, OLD.cliente_documento_tipo, OLD.paciente_nombre,
                    OLD.paciente_documento, OLD.paciente_documento_tipo, OLD.paciente_poliza,
                    OLD.emitida_en, OLD.fecha_operacion, OLD.bruto, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.exento, OLD.gravado, OLD.isv, OLD.total{$viejo}, OLD.created_by)
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
