<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anular la factura devuelve los cargos a `pendiente`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ HACE FALTA ABRIR ESTA TRANSICIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Facturar mueve los cargos de `pendiente` a `facturado` y cierra la
 * cuenta. Cuando la factura se anula —el papel se arrugó, salió con el
 * cliente equivocado, se emitió en la cuenta que no era— esos cargos
 * quedaban `facturado` para siempre: la cuenta no se podía volver a
 * facturar y la única salida era abrir una cuenta nueva y repetir a mano
 * todo lo que el paciente ya tenía cargado.
 *
 * `facturado → pendiente` es exactamente eso deshecho, y es la única
 * transición nueva. El resto del trigger queda igual: un cargo sigue sin
 * poder borrarse, sigue sin poder cambiar de monto, y `anulado` sigue
 * siendo terminal.
 *
 * ⚠️ Esto NO libera el número fiscal. El correlativo queda consumido y
 * la factura anulada queda en la lista con su motivo: el SAR audita la
 * secuencia y un hueco es una factura que alguien escondió. La cuenta
 * vuelve a facturarse con el número SIGUIENTE.
 *
 * ⚠️ Tampoco toca los abonos. La plata se recibió de verdad; si además
 * hay que devolverla, eso es anular el abono, que es otro hecho, con su
 * turno de caja abierto y su propio rastro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 `CREATE OR REPLACE` Y NO UN TRIGGER NUEVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La función se llama igual y el trigger sigue apuntando a ella, así que
 * reemplazarla alcanza y no hay ventana sin protección. Y va completa,
 * no parchada: `migrate:fresh` no borra funciones, así que una base
 * recreada corre primero la versión vieja de la migración original y
 * después ESTA — que tiene que dejar el cuerpo entero y definitivo.
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
                    NEW.convenio_id, NEW.regimen_isv, NEW.clave_idempotencia, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.fecha_operacion, OLD.cuenta_id, OLD.encuentro_id, OLD.item_id,
                    OLD.cantidad, OLD.precio_unitario, OLD.bruto, OLD.subtotal, OLD.total,
                    OLD.isv, OLD.base_exenta, OLD.base_gravada, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.porcion_paciente, OLD.porcion_aseguradora,
                    OLD.costo_unitario, OLD.costo_total, OLD.movimiento_id, OLD.tarifario_id,
                    OLD.convenio_id, OLD.regimen_isv, OLD.clave_idempotencia, OLD.created_by)
                THEN
                    RAISE EXCEPTION 'El cargo % ya está asentado: su monto, su cantidad y su snapshot de precio no se editan. Se corrige con una reversa (SIHLA §8.5-5).', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

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

    public function down(): void
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
                    NEW.convenio_id, NEW.regimen_isv, NEW.clave_idempotencia, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.fecha_operacion, OLD.cuenta_id, OLD.encuentro_id, OLD.item_id,
                    OLD.cantidad, OLD.precio_unitario, OLD.bruto, OLD.subtotal, OLD.total,
                    OLD.isv, OLD.base_exenta, OLD.base_gravada, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.porcion_paciente, OLD.porcion_aseguradora,
                    OLD.costo_unitario, OLD.costo_total, OLD.movimiento_id, OLD.tarifario_id,
                    OLD.convenio_id, OLD.regimen_isv, OLD.clave_idempotencia, OLD.created_by)
                THEN
                    RAISE EXCEPTION 'El cargo % ya está asentado: su monto, su cantidad y su snapshot de precio no se editan. Se corrige con una reversa (SIHLA §8.5-5).', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.estado <> NEW.estado AND NOT (
                       (OLD.estado = 'pendiente'  AND NEW.estado IN ('facturado', 'anulado', 'trasladado'))
                    OR (OLD.estado = 'facturado'  AND NEW.estado = 'anulado')
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
};
