<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El presupuesto al paciente — un estimado, no un cargo (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ESTA TABLA NO MUEVE PLATA
 * ─────────────────────────────────────────────────────────────────────
 *
 * No genera cargos, no toca el kardex, no entra a la factura. La cuenta
 * sigue siendo la única verdad. Lo único que aporta el presupuesto es el
 * DENOMINADOR de un medidor cuyo numerador ya existe y es gratis:
 *
 *   Presupuestado  40,000.00   <- presupuestos.total
 *   Consumido      28,350.00   <- cuentas.total, materializado (§13.5)
 *   Disponible     11,650.00   <- se calcula, NUNCA se almacena
 *
 * `disponible` como columna sería el `UPDATE productos SET existencia`
 * del §9.G1 otra vez: un número editable que en tres días deja de
 * corresponder con los hechos y nadie puede decir cuándo se desvió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE PRESUPUESTA EL TOTAL, NO LA PORCIÓN DEL PACIENTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Decisión del negocio: «se le da el total al paciente; ya los seguros
 * arreglan qué pagan ellos y qué paga el cliente». La división pagador /
 * paciente la sigue haciendo `CalculadoraDeCobertura` línea por línea al
 * cobrar, y no se refleja acá. Es coherente con el ADR-0007: una
 * atención, una cuenta, un total.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `encuentro_id` ES NULLABLE A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mucha gente llega solo a preguntar cuánto le sale. Exigir un ingreso
 * abierto para poder cotizar obligaría a abrir encuentros fantasma —y un
 * encuentro fantasma ensucia el censo, el reporte de ocupación y el
 * indicador de reingreso a 30 días (§9.K14).
 *
 * El expediente SÍ es obligatorio: cotizarle a alguien sin identificar
 * es como se pierde el rastro de a quién se le prometió qué.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CORREGIR ES SUSTITUIR, NO EDITAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se complicó la cirugía: se emite uno nuevo de 60,000 y el de 40,000
 * queda `sustituido`, apuntado por `presupuesto_anterior_id`. Mismo
 * patrón que `cuentas.cuenta_anterior_id`. Editar el emitido sería
 * cambiarle el número al papel que la familia tiene en la mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuestos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            $tabla->string('numero', 40);

            $tabla->foreignId('expediente_id')->constrained('expedientes')->restrictOnDelete();

            /*
             * Desnormalizada por la misma razón que en `encuentros`: la
             * bandeja de presupuestos necesita el nombre sin un salto
             * más, y si una fusión de expedientes mueve al paciente de
             * dueño (§9.D4), esta columna dice a quién se le cotizó ESE
             * día.
             */
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            $tabla->foreignId('encuentro_id')->nullable()
                ->constrained('encuentros')->restrictOnDelete();

            /*
             * Con qué tarifario se cotizó. NOT NULL: un presupuesto sin
             * pagador no tiene precios, tiene números sueltos.
             *
             * ⚠️ Si después la cuenta abre con OTRO convenio —el NN de
             * las 3 am del §1.5—, este presupuesto quedó cotizado contra
             * otro pagador. Se marca y se ofrece recotizar; no se
             * recalcula solo, porque la familia ya firmó un papel.
             */
            $tabla->foreignId('convenio_id')->constrained('convenios')->restrictOnDelete();

            $tabla->foreignId('plantilla_id')->nullable()
                ->constrained('plantillas_presupuesto')->restrictOnDelete();

            $tabla->string('titulo', 150);

            $tabla->string('estado', 20)->default('borrador');

            $tabla->timestampTz('emitido_en')->nullable();
            $tabla->date('vence_el')->nullable();

            // ── Totales congelados al emitir ──────────────────────────
            $tabla->decimal('total_bruto', 14, 2)->default(0);
            $tabla->decimal('total_descuento', 14, 2)->default(0);
            $tabla->decimal('total_exento', 14, 2)->default(0);
            $tabla->decimal('total_gravado', 14, 2)->default(0);
            $tabla->decimal('total_isv', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2)->default(0);
            $tabla->integer('lineas')->default(0);

            $tabla->foreignId('presupuesto_anterior_id')->nullable()
                ->constrained('presupuestos')->restrictOnDelete();
            $tabla->string('motivo_revision', 200)->nullable();

            /*
             * Quien firma el papel no es siempre el paciente (§9.K12):
             * el menor, el inconsciente y el adulto mayor tienen quien
             * responda por la plata.
             */
            $tabla->foreignId('responsable_persona_id')->nullable()
                ->constrained('personas')->restrictOnDelete();
            $tabla->timestampTz('firmado_en')->nullable();

            $tabla->string('notas', 500)->nullable();

            $tabla->timestampTz('anulado_en')->nullable();
            $tabla->string('motivo_anulacion', 200)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX presupuestos_numero_unico ON presupuestos (sede_id, numero)');

        /*
         * 🔴 UN SOLO PRESUPUESTO EMITIDO POR ENCUENTRO.
         *
         * Se pueden cotizar dos opciones a la misma persona —las dos
         * viven en `borrador`—, pero la barra de la cuenta necesita UN
         * denominador. Con dos emitidos, la pantalla elegiría uno por
         * orden de id y nadie sabría cuál está midiendo.
         */
        DB::statement(
            "CREATE UNIQUE INDEX presupuestos_uno_emitido_por_encuentro
             ON presupuestos (encuentro_id)
             WHERE estado = 'emitido' AND encuentro_id IS NOT NULL"
        );

        DB::statement(
            "CREATE INDEX presupuestos_bandeja
             ON presupuestos (sede_id, created_at DESC)
             WHERE estado IN ('borrador', 'emitido')"
        );

        DB::statement('CREATE INDEX presupuestos_por_expediente ON presupuestos (expediente_id, created_at DESC)');

        DB::statement(
            "CREATE INDEX presupuestos_por_vencer ON presupuestos (vence_el) WHERE estado = 'emitido'"
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_estado_conocido
             CHECK (estado IN ('borrador', 'emitido', 'sustituido', 'vencido', 'cerrado', 'anulado'))"
        );

        /*
         * El mismo cruce que verifica `cuentas`: si el presupuesto no
         * cuadra, no llega a existir. Es lo que hace comparables los dos
         * lados del medidor.
         */
        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_totales_cuadran
             CHECK (total = total_exento + total_gravado + total_isv)'
        );

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_lineas_no_negativas CHECK (lineas >= 0)'
        );

        /*
         * Un emitido sin fecha de emisión o sin vencimiento es un papel
         * que no caduca nunca — y a los seis meses alguien lo presenta
         * reclamando el precio del año pasado.
         */
        DB::statement(
            "ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_emision_completa
             CHECK (estado = 'borrador' OR estado = 'anulado'
                    OR (emitido_en IS NOT NULL AND vence_el IS NOT NULL))"
        );

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_anulacion_completa
             CHECK (estado <> \'anulado\' OR (anulado_en IS NOT NULL AND length(btrim(motivo_anulacion)) >= 10))'
        );

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_no_es_su_propio_anterior
             CHECK (presupuesto_anterior_id IS NULL OR presupuesto_anterior_id <> id)'
        );

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_numero_no_vacio
             CHECK (length(btrim(numero)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_titulo_no_vacio
             CHECK (length(btrim(titulo)) >= 3)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuestos');
    }
};
