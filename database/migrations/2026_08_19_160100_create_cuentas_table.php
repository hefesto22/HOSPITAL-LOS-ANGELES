<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta del paciente — la entidad VIVA (§8.6.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * CUENTA ≠ FACTURA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La cuenta acumula lo que pasó: turno A carga dos tabletas, turno B
 * cuatro, turno C la radiografía. La factura es una PROYECCIÓN de la
 * cuenta a un instante, se timbra una sola vez y es inmutable.
 *
 * Si fueran la misma tabla, cada cargo tardío obligaría a reabrir un
 * documento fiscal —que es exactamente lo que el Acuerdo 481-2017 no
 * permite— y el sistema terminaría rechazando la transfusión de las
 * 23:50 porque «la factura ya se emitió» (§8.6.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LA CUENTA SE SEPARA DEL ENCUENTRO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque el pagador cambia a mitad del ingreso, y es el escenario del
 * §1.5: entra un NN a las 3 am y se le abre cuenta CONTADO; a las 6 am
 * llega la familia con la póliza. Con el convenio en el encuentro, eso
 * obliga a reescribir cargos ya calculados. Con la cuenta aparte, se
 * abre una cuenta nueva con el pagador correcto, los cargos pendientes
 * se marcan `trasladado` y la historia de las dos queda entera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS TOTALES SON MATERIALIZADOS, Y ESO ES A PROPÓSITO (§13.5)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se actualizan en la MISMA transacción que el cargo, nunca por un job.
 * La pantalla de cuentas abiertas muestra veinte tarjetas: con `SUM()`
 * en vivo serían veinte agregaciones sobre una tabla particionada de
 * millones de filas cada vez que alguien entra (§13.2). Y el tope por
 * evento del seguro necesita saber cuánto lleva la aseguradora ANTES de
 * calcular la línea nueva, bajo el mismo candado.
 *
 * Los CHECK verifican que sigan cuadrando: si un día no cuadran, la base
 * lo rechaza en vez de dejar una cuenta silenciosamente falsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $tabla->foreignId('encuentro_id')->constrained('encuentros')->restrictOnDelete();

            $tabla->string('numero', 40);

            /*
             * NOT NULL: quién paga siempre se sabe, aunque sea CONTADO.
             * Un nulo obligaría a preguntar «¿hay convenio?» en cada
             * cálculo de precio, y esa pregunta se olvida en algún lado.
             */
            $tabla->foreignId('convenio_id')->constrained('convenios')->restrictOnDelete();

            $tabla->string('numero_poliza', 60)->nullable();
            $tabla->string('numero_autorizacion', 60)->nullable();

            /*
             * Quien responde por la plata NO es siempre el paciente ni el
             * contacto clínico (§9.K12). El menor de edad, el paciente
             * inconsciente y el adulto mayor tienen quien firme.
             */
            $tabla->foreignId('responsable_persona_id')->nullable()
                ->constrained('personas')->restrictOnDelete();

            $tabla->string('estado', 20)->default('abierta');

            $tabla->timestampTz('abierta_en');
            $tabla->timestampTz('congelada_en')->nullable();
            $tabla->timestampTz('cerrada_en')->nullable();
            $tabla->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestampTz('anulada_en')->nullable();
            $tabla->string('motivo_anulacion', 200)->nullable();

            $tabla->string('motivo_apertura', 200)->nullable();

            /*
             * De qué cuenta vienen los cargos trasladados cuando cambió
             * el pagador. Sin esto, la cuenta nueva parece haber nacido
             * con veinte cargos de la nada.
             */
            $tabla->foreignId('cuenta_anterior_id')->nullable()
                ->constrained('cuentas')->restrictOnDelete();

            // ── Totales materializados (§13.5) ────────────────────────
            $tabla->decimal('total_bruto', 14, 2)->default(0);
            $tabla->decimal('total_descuento', 14, 2)->default(0);
            $tabla->decimal('total_exento', 14, 2)->default(0);
            $tabla->decimal('total_gravado', 14, 2)->default(0);
            $tabla->decimal('total_isv', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2)->default(0);
            $tabla->decimal('total_paciente', 14, 2)->default(0);
            $tabla->decimal('total_aseguradora', 14, 2)->default(0);
            $tabla->integer('lineas')->default(0);

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX cuentas_numero_unico ON cuentas (sede_id, numero)');

        /*
         * Una sola cuenta abierta por encuentro. El cambio de pagador
         * cierra la anterior y abre la nueva en la misma transacción; si
         * pudieran convivir dos, el cargo siguiente no sabría a cuál ir.
         */
        DB::statement(
            "CREATE UNIQUE INDEX cuentas_una_abierta_por_encuentro
             ON cuentas (encuentro_id)
             WHERE estado = 'abierta'"
        );

        DB::statement(
            "CREATE INDEX cuentas_bandeja
             ON cuentas (sede_id, abierta_en DESC)
             WHERE estado IN ('abierta', 'congelada')"
        );

        DB::statement('CREATE INDEX cuentas_por_convenio ON cuentas (convenio_id, estado)');

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE cuentas ADD CONSTRAINT cuentas_estado_conocido
             CHECK (estado IN ('abierta', 'congelada', 'cerrada', 'anulada'))"
        );

        /*
         * 🔴 Los dos cruces del golden test §9.H13.1, verificados por la
         * base en cada escritura: exento + gravado + ISV = total, y
         * paciente + aseguradora = total. Una cuenta que no cuadre no
         * llega a existir.
         */
        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_totales_cuadran
             CHECK (total = total_exento + total_gravado + total_isv)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_division_cuadra
             CHECK (total_paciente + total_aseguradora = total)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_lineas_no_negativas CHECK (lineas >= 0)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_cierre_completo
             CHECK (estado <> \'cerrada\' OR cerrada_en IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_congelada_completa
             CHECK (estado <> \'congelada\' OR congelada_en IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_anulacion_completa
             CHECK (estado <> \'anulada\' OR (anulada_en IS NOT NULL AND length(btrim(motivo_anulacion)) >= 10))'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_numero_no_vacio
             CHECK (length(btrim(numero)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_no_es_su_propia_anterior
             CHECK (cuenta_anterior_id IS NULL OR cuenta_anterior_id <> id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
