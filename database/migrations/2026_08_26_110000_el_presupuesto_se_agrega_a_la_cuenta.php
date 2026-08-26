<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El paquete presupuestado entra a la cuenta (ADR-0009).
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN RENGLÓN COBRABLE Y UNA LISTA QUE SE VA MARCANDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La cuenta muestra «APENDICECTOMIA · L 40,000». Debajo, lo que ese
 * renglón incluye, marcándose solo a medida que se consume. Lo que
 * estaba previsto NO se vuelve a cobrar; lo que no estaba, se cobra
 * aparte y avisa.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO CONSUMIDO ES DERIVADO — POR ESO NO HAY COLUMNA `consumido`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se calcula agrupando `cargos` por `presupuesto_linea_id`. Una sola
 * consulta. Materializarlo sería el `UPDATE productos SET existencia`
 * del §9.G1: un número editable que en tres días deja de corresponder
 * con los hechos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ POR QUÉ `presupuestos` NO APUNTA AL CARGO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cargos` está particionada y su llave primaria es
 * `(id, fecha_operacion)`: una FK hacia ella exige las DOS columnas
 * (consecuencia declarada del bloque 4). Se resuelve al revés —
 * consultando `cargos` por `presupuesto_id`— y así ninguna tabla nueva
 * arrastra la llave compuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Con qué ítem del catálogo se cobra el paquete. Nullable: un
         * presupuesto en borrador todavía no sabe si va a cobrarse como
         * paquete o si solo era una cotización para la familia.
         */
        Schema::table('presupuestos', function (Blueprint $tabla): void {
            $tabla->foreignId('item_cobro_id')->nullable()->constrained('items')->restrictOnDelete();
        });

        /*
         * ⚠️ `cargos` está PARTICIONADA: el ADD COLUMN se propaga a las
         * once particiones. Sin datos es instantáneo; con dos millones de
         * filas habría que planificarlo (§12).
         */
        DB::statement('ALTER TABLE cargos ADD COLUMN presupuesto_id bigint REFERENCES presupuestos(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE cargos ADD COLUMN presupuesto_linea_id bigint REFERENCES presupuesto_lineas(id) ON DELETE RESTRICT');

        DB::statement(
            'CREATE INDEX cargos_del_paquete ON cargos (presupuesto_id) WHERE presupuesto_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX cargos_por_linea_presupuestada ON cargos (presupuesto_linea_id)
             WHERE presupuesto_linea_id IS NOT NULL'
        );

        /*
         * Una línea del presupuesto no se consume sin el presupuesto al
         * que pertenece: sin esto, un cargo podría decir que cumplió un
         * renglón de OTRO paquete.
         */
        DB::statement(
            'ALTER TABLE cargos ADD CONSTRAINT cargos_linea_exige_paquete
             CHECK (presupuesto_linea_id IS NULL OR presupuesto_id IS NOT NULL)'
        );

        // ── «Emitido» pasa a llamarse «agregado» ──────────────────────

        /*
         * Ya no se «emite» un papel que se congela: se AGREGA a la cuenta
         * y se sigue tocando mientras el paciente está internado, porque
         * es lo que pasa de verdad (ADR-0009).
         */
        DB::statement("UPDATE presupuestos SET estado = 'agregado' WHERE estado = 'emitido'");

        DB::statement('ALTER TABLE presupuestos DROP CONSTRAINT IF EXISTS presupuestos_estado_conocido');
        DB::statement(
            "ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_estado_conocido
             CHECK (estado IN ('borrador', 'agregado', 'sustituido', 'vencido', 'cerrado', 'anulado'))"
        );

        DB::statement('DROP INDEX IF EXISTS presupuestos_uno_emitido_por_encuentro');
        DB::statement(
            "CREATE UNIQUE INDEX presupuestos_uno_agregado_por_encuentro
             ON presupuestos (encuentro_id)
             WHERE estado = 'agregado' AND encuentro_id IS NOT NULL"
        );

        DB::statement('DROP INDEX IF EXISTS presupuestos_por_vencer');
        DB::statement(
            "CREATE INDEX presupuestos_por_vencer ON presupuestos (vence_el) WHERE estado = 'agregado'"
        );

        DB::statement('DROP INDEX IF EXISTS presupuestos_bandeja');
        DB::statement(
            "CREATE INDEX presupuestos_bandeja
             ON presupuestos (sede_id, created_at DESC)
             WHERE estado IN ('borrador', 'agregado')"
        );

        /*
         * 🔴 EL TRIGGER SE AFLOJA, NO SE QUITA.
         *
         * Las líneas se siguen tocando mientras el presupuesto está
         * `agregado` —la cirugía se complica, la familia pide otra
         * habitación— pero un presupuesto CERRADO, sustituido o anulado
         * sigue siendo de piedra: ahí el caso ya terminó y lo que dice
         * tiene que seguir explicando la cuenta.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION presupuesto_lineas_solo_en_borrador()
            RETURNS trigger AS $$
            DECLARE
                id_padre bigint;
                estado_padre varchar(20);
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    id_padre := OLD.presupuesto_id;
                ELSE
                    id_padre := NEW.presupuesto_id;
                END IF;

                SELECT estado INTO estado_padre FROM presupuestos WHERE id = id_padre;

                IF NOT FOUND THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;

                IF estado_padre NOT IN ('borrador', 'agregado') THEN
                    RAISE EXCEPTION
                        'El presupuesto % está en estado "%" y sus renglones ya no se tocan (ADR-0009).',
                        id_padre, estado_padre;
                END IF;

                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE presupuestos SET estado = 'emitido' WHERE estado = 'agregado'");

        DB::statement('DROP INDEX IF EXISTS presupuestos_uno_agregado_por_encuentro');
        DB::statement('ALTER TABLE presupuestos DROP CONSTRAINT IF EXISTS presupuestos_estado_conocido');
        DB::statement(
            "ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_estado_conocido
             CHECK (estado IN ('borrador', 'emitido', 'sustituido', 'vencido', 'cerrado', 'anulado'))"
        );

        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_linea_exige_paquete');
        DB::statement('DROP INDEX IF EXISTS cargos_por_linea_presupuestada');
        DB::statement('DROP INDEX IF EXISTS cargos_del_paquete');
        DB::statement('ALTER TABLE cargos DROP COLUMN IF EXISTS presupuesto_linea_id');
        DB::statement('ALTER TABLE cargos DROP COLUMN IF EXISTS presupuesto_id');

        Schema::table('presupuestos', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('item_cobro_id');
        });
    }
};
