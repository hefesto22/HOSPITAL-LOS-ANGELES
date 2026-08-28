<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No todo paciente tiene RTN — y eso no puede dejarlo sin factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PROBLEMA REAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * El umbral del SAR exige los datos del cliente arriba de L 10,000, y
 * el sistema los pedía como RTN. Una cuenta de L 24,000 de alguien que
 * nunca sacó RTN quedaba imposible de facturar, con la familia esperando
 * el papel para irse. Eso es peor que cualquier duda de forma.
 *
 * Ahora el cliente se identifica con UN documento: RTN, identidad o
 * pasaporte. Arriba del umbral se exige que haya uno; cuál vale ante una
 * revisión es la pregunta que queda para el contador del hospital.
 *
 * ⚠️ Si la respuesta es «tiene que ser RTN sí o sí», el cambio es una
 * línea en `EmisorDeFactura`: exigir que el tipo sea `rtn` en vez de
 * exigir que haya documento. El esquema ya lo soporta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ SE RENOMBRA LA COLUMNA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cliente_rtn` guardando una identidad es una mentira que dura hasta
 * que alguien arma un reporte por RTN y le salen números de trece
 * dígitos que no son RTN de nadie. El tipo va al lado, y el papel
 * imprime la etiqueta que corresponde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->renameColumn('cliente_rtn', 'cliente_documento');
        });

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->string('cliente_documento_tipo', 20)->nullable()->after('cliente_documento');
        });

        DB::statement('ALTER INDEX facturas_por_rtn RENAME TO facturas_por_documento');

        DB::statement(
            "ALTER TABLE facturas ADD CONSTRAINT facturas_documento_conocido
             CHECK (cliente_documento_tipo IS NULL
                OR cliente_documento_tipo IN ('rtn', 'dni', 'pasaporte'))"
        );

        /*
         * Un número sin tipo no se puede etiquetar en el papel, y un tipo
         * sin número no identifica a nadie. Van juntos o no van.
         */
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_documento_completo
             CHECK ((cliente_documento IS NULL) = (cliente_documento_tipo IS NULL))'
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 RENOMBRAR UNA COLUMNA NO TOCA LAS FUNCIONES QUE LA NOMBRAN
         * ─────────────────────────────────────────────────────────────
         *
         * PostgreSQL actualiza índices y constraints solo, pero el
         * cuerpo de una función plpgsql es TEXTO: sigue diciendo
         * `NEW.cliente_rtn` y revienta recién cuando alguien dispara el
         * trigger —o sea, la primera vez que se anule una factura, en
         * producción, con el cliente enfrente—.
         *
         * Acá lo atajó la prueba de «no se puede anular dos veces».
         */
        DB::statement($this->triggerInmutable('cliente_documento, NEW.cliente_documento_tipo'));
    }

    /**
     * El cuerpo del trigger, con el nombre de la columna del documento
     * como parámetro para no repetirlo entre `up()` y `down()`.
     */
    private function triggerInmutable(string $documento): string
    {
        $viejo = str_replace('NEW.', 'OLD.', $documento);

        return <<<SQL
            CREATE OR REPLACE FUNCTION sihla_factura_inmutable() RETURNS trigger AS \$\$
            BEGIN
                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.sede_id, NEW.tipo, NEW.numero, NEW.correlativo, NEW.rango_cai_id,
                    NEW.cai, NEW.fecha_limite_emision, NEW.cuenta_id, NEW.cliente_nombre,
                    NEW.{$documento}, NEW.emitida_en, NEW.fecha_operacion, NEW.bruto,
                    NEW.descuento_legal, NEW.descuento_comercial, NEW.exento, NEW.gravado,
                    NEW.isv, NEW.total, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.sede_id, OLD.tipo, OLD.numero, OLD.correlativo, OLD.rango_cai_id,
                    OLD.cai, OLD.fecha_limite_emision, OLD.cuenta_id, OLD.cliente_nombre,
                    OLD.{$viejo}, OLD.emitida_en, OLD.fecha_operacion, OLD.bruto,
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
            \$\$ LANGUAGE plpgsql;
            SQL;
    }

    public function down(): void
    {
        /* La función vuelve a nombrar la columna vieja, o el trigger queda roto. */
        DB::statement($this->triggerInmutable('cliente_rtn'));

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_documento_completo');
        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_documento_conocido');
        DB::statement('ALTER INDEX facturas_por_documento RENAME TO facturas_por_rtn');

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn('cliente_documento_tipo');
        });

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->renameColumn('cliente_documento', 'cliente_rtn');
        });
    }
};
