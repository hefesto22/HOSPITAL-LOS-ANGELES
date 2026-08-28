<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El turno de caja: quién tuvo la gaveta y qué entró en ella.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE ANTES QUE LA FACTURA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El paciente internado deja L 5,000 hoy y L 3,000 mañana, y la factura
 * no se emite hasta el egreso. Esa plata entra a una gaveta física que
 * alguien cuenta al final de su turno. Sin turno, un abono es una fila
 * en una tabla que nadie cuadra contra billetes — y ahí es donde
 * desaparece el dinero de un hospital privado.
 *
 * Turno A, turno B, turno C: lo abre y lo cierra la persona, a mano.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UNO ABIERTO POR PERSONA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Índice parcial, no validación de pantalla. Con dos turnos abiertos la
 * misma cajera repartiría sus recibos entre los dos sin darse cuenta, y
 * al cerrar ninguno de los dos cuadraría contra lo que tiene en la mano.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ARQUEO CUENTA EFECTIVO, NO EL TOTAL COBRADO
 * ─────────────────────────────────────────────────────────────────────
 *
 *   efectivo_esperado = fondo_inicial + efectivo recibido en el turno
 *   diferencia        = efectivo_contado − efectivo_esperado
 *
 * Lo de tarjeta lo liquida el POS y lo de transferencia el banco:
 * meterlos en el arqueo daría un sobrante fantasma todas las noches.
 *
 * `efectivo_esperado` se CONGELA al cerrar. Recalcularlo después haría
 * que un abono anulado mañana cambie el arqueo de anoche, y un arqueo
 * que cambia solo no sirve para responsabilizar a nadie.
 *
 * ⚠️ `fecha_operacion` la pone PHP (§ prohibición de `now()` de
 * PostgreSQL): el servidor puede estar en UTC y el turno que cierra a
 * las 11 de la noche caería en el día siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_de_caja', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            $tabla->string('numero', 40);

            /*
             * Cómo lo llama el hospital: «Turno A», «Nocturno»,
             * «Emergencia». Es para el reporte y para que la cajera
             * reconozca el suyo, no para el sistema.
             */
            $tabla->string('nombre', 40)->nullable();

            /*
             * El turno es de una PERSONA, no de un escritorio. La gaveta
             * la cuenta alguien con nombre, y ese alguien responde por
             * el faltante.
             */
            $tabla->foreignId('usuario_id')->constrained('users')->restrictOnDelete();

            $tabla->string('estado', 20)->default('abierto');

            $tabla->decimal('fondo_inicial', 14, 2)->default(0);

            $tabla->timestampTz('abierto_en');
            $tabla->date('fecha_operacion');

            $tabla->timestampTz('cerrado_en')->nullable();
            $tabla->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->decimal('efectivo_esperado', 14, 2)->nullable();
            $tabla->decimal('efectivo_contado', 14, 2)->nullable();
            $tabla->decimal('diferencia', 14, 2)->nullable();

            $tabla->string('notas_cierre', 300)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX turnos_de_caja_numero_unico ON turnos_de_caja (sede_id, numero)');

        DB::statement(
            "CREATE UNIQUE INDEX turnos_de_caja_uno_abierto_por_usuario
             ON turnos_de_caja (usuario_id)
             WHERE estado = 'abierto'"
        );

        DB::statement(
            'CREATE INDEX turnos_de_caja_del_dia ON turnos_de_caja (sede_id, fecha_operacion DESC)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_estado_conocido
             CHECK (estado IN ('abierto', 'cerrado'))"
        );

        DB::statement(
            'ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_fondo_no_negativo
             CHECK (fondo_inicial >= 0)'
        );

        /*
         * Un turno cerrado sin arqueo es un turno que nadie contó. Si
         * falta cualquiera de las cuatro columnas, el cierre no llega a
         * existir.
         */
        DB::statement(
            "ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_cierre_completo
             CHECK (estado <> 'cerrado' OR (
                 cerrado_en IS NOT NULL
                 AND cerrado_por IS NOT NULL
                 AND efectivo_esperado IS NOT NULL
                 AND efectivo_contado IS NOT NULL
                 AND diferencia IS NOT NULL
             ))"
        );

        DB::statement(
            'ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_diferencia_cuadra
             CHECK (diferencia IS NULL OR diferencia = efectivo_contado - efectivo_esperado)'
        );

        /*
         * 🔴 UN FALTANTE SIN EXPLICACIÓN NO SE GUARDA.
         *
         * Es la razón de ser del arqueo: si sobran o faltan billetes,
         * alguien tiene que escribir por qué la misma noche, no tres
         * días después cuando ya nadie se acuerda.
         */
        DB::statement(
            'ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_diferencia_con_motivo
             CHECK (diferencia IS NULL OR diferencia = 0 OR length(btrim(notas_cierre)) >= 10)'
        );

        DB::statement(
            'ALTER TABLE turnos_de_caja ADD CONSTRAINT turnos_de_caja_efectivo_no_negativo
             CHECK ((efectivo_contado IS NULL OR efectivo_contado >= 0)
                AND (efectivo_esperado IS NULL OR efectivo_esperado >= 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos_de_caja');
    }
};
