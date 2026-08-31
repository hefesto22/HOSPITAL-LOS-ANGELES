<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cargo recuerda en qué envase se cobró.
 *
 * ─────────────────────────────────────────────────────────────────────
 * «60 × 61.11» NO ES LO QUE PASÓ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se entregó UN frasco de 60 ml y el renglón de la cuenta decía:
 *
 *     ACETAMINOFEN JARABE    60    61.11    733.33    L 2,933.34
 *
 * Los números están bien y la lectura está mal. Nadie entregó sesenta de
 * nada, y ningún precio de este hospital es L 61.11: es lo que sale de
 * dividir el frasco entre sus mililitros. El paciente lee su factura y
 * ve una cantidad que no reconoce a un precio que nunca le dijeron.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL KARDEX NO CAMBIA, Y NO PUEDE CAMBIAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cantidad` sigue en unidad de dispensación —60 ML— y esa es la que
 * descuenta existencia, pondera el costo y cuadra con el movimiento de
 * kardex (§8.7). Guardar envases ahí volvería incomparables dos cargos
 * del mismo producto en presentaciones distintas.
 *
 * Estas dos columnas son el OTRO dato: en qué se cobró. Con ellas el
 * renglón se lee «1 FRASCO 60 ML a L 3,666.67» y sigue descontando 60 ML
 * del estante.
 *
 * Nulas para todo lo que no se cobra por envase: un honorario, una
 * consulta, un jarabe fraccionable cobrado por mililitro. Ahí la cantidad
 * de dispensación YA es la que hay que leer.
 *
 * ⚠️ Entran a la tupla del trigger `cargos_append_only`: cómo se cobró
 * es parte del snapshot y no se edita después, igual que el precio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $tabla): void {
            $tabla->foreignId('item_presentacion_id')
                ->nullable()
                ->after('unidad_id')
                ->constrained('item_presentaciones')
                ->restrictOnDelete();

            /*
             * Cuántos envases. Cuatro decimales como el resto de las
             * cantidades: media caja es una venta legal.
             */
            $tabla->decimal('cantidad_presentacion', 14, 4)->nullable()->after('item_presentacion_id');
        });

        /*
         * Los dos o ninguno: un envase sin cantidad no dice cuántos, y
         * una cantidad sin envase no dice de qué.
         */
        DB::statement(
            'ALTER TABLE cargos
             ADD CONSTRAINT cargos_envase_completo
             CHECK ((item_presentacion_id IS NULL) = (cantidad_presentacion IS NULL))'
        );

        DB::statement(
            'ALTER TABLE cargos
             ADD CONSTRAINT cargos_envases_positivos
             CHECK (cantidad_presentacion IS NULL OR cantidad_presentacion > 0)'
        );

        /*
         * El trigger se reescribe entero con las dos columnas nuevas
         * adentro. `CREATE OR REPLACE` no cambia el trigger: cambia la
         * función que el trigger ya llama, así que no hay ventana sin
         * protección.
         */
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

        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envases_positivos');
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envase_completo');

        Schema::table('cargos', function (Blueprint $tabla): void {
            $tabla->dropColumn('cantidad_presentacion');
            $tabla->dropConstrainedForeignId('item_presentacion_id');
        });
    }
};
