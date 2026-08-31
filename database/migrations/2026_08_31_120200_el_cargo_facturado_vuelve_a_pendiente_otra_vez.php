<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repone la transición que la migración de los envases borró sin querer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA LECCIÓN: `CREATE OR REPLACE` PARTE DE LA VERSIÓN VIGENTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * `2026_08_31_120000` agregó dos columnas a la tupla inmutable del
 * trigger y, para hacerlo, reescribió la función entera. Copió el cuerpo
 * de la migración ORIGINAL —la del 19 de agosto— sin ver que la del 28
 * ya lo había cambiado: ahí se había permitido `facturado → pendiente`,
 * que es como una factura anulada devuelve sus cargos a la cuenta.
 *
 * Resultado: anular una factura reventaba con «Transición no permitida
 * en el cargo N: de facturado a pendiente». Siete pruebas lo dijeron.
 *
 * Reemplazar una función de PostgreSQL es reemplazarla ENTERA. Quien lo
 * haga tiene que partir del cuerpo que está corriendo hoy —el de la
 * última migración que la tocó—, no del que escribió el primero.
 *
 * Este cuerpo es el bueno y el completo: las transiciones del 28 más las
 * dos columnas de envase del 31.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sihla_cargo_solo_transiciona() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un cargo no se borra. Se corrige asentando un cargo de reversa que deja rastro (SIHLA §9.0.3).'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.fecha_operacion, NEW.cuenta_id, NEW.encuentro_id, NEW.item_id,
                    NEW.cantidad, NEW.precio_unitario, NEW.bruto, NEW.subtotal, NEW.total,
                    NEW.isv, NEW.base_exenta, NEW.base_gravada, NEW.descuento_legal,
                    NEW.descuento_comercial, NEW.porcion_paciente, NEW.porcion_aseguradora,
                    NEW.costo_unitario, NEW.costo_total, NEW.movimiento_id, NEW.tarifario_id,
                    NEW.convenio_id, NEW.regimen_isv, NEW.clave_idempotencia, NEW.created_by,
                    NEW.item_presentacion_id, NEW.cantidad_presentacion)
                   IS DISTINCT FROM
                   (OLD.id, OLD.fecha_operacion, OLD.cuenta_id, OLD.encuentro_id, OLD.item_id,
                    OLD.cantidad, OLD.precio_unitario, OLD.bruto, OLD.subtotal, OLD.total,
                    OLD.isv, OLD.base_exenta, OLD.base_gravada, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.porcion_paciente, OLD.porcion_aseguradora,
                    OLD.costo_unitario, OLD.costo_total, OLD.movimiento_id, OLD.tarifario_id,
                    OLD.convenio_id, OLD.regimen_isv, OLD.clave_idempotencia, OLD.created_by,
                    OLD.item_presentacion_id, OLD.cantidad_presentacion)
                THEN
                    RAISE EXCEPTION 'El cargo % ya está asentado: su monto, su cantidad y su snapshot de precio no se editan. Se corrige con una reversa (SIHLA §8.5-5).', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 * `facturado → pendiente` es la vuelta de una factura
                 * anulada: los cargos regresan a la cuenta para poder
                 * facturarse otra vez. Sin esa transición, anular una
                 * factura deja los cargos atrapados y la cuenta cerrada
                 * con plata que nadie puede volver a cobrar.
                 */
                IF OLD.estado <> NEW.estado AND NOT (
                       (OLD.estado = 'pendiente'  AND NEW.estado IN ('facturado', 'anulado', 'trasladado'))
                    OR (OLD.estado = 'facturado'  AND NEW.estado IN ('anulado', 'pendiente'))
                    OR (OLD.estado = 'trasladado' AND NEW.estado IN ('facturado', 'anulado'))
                ) THEN
                    RAISE EXCEPTION 'Transición no permitida en el cargo %: de % a %.', OLD.id, OLD.estado, NEW.estado
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    /**
     * No hay vuelta atrás.
     *
     * Revertir esto sería reponer el cuerpo roto —el que impide anular
     * una factura— y eso no es un estado al que nadie quiera volver. La
     * migración anterior ya sabe deshacer las columnas.
     */
    public function down(): void {}
};
