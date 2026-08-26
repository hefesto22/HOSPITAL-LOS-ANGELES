<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto cubre el pagador — el mínimo para dividir la cuenta en el
 * momento del cargo (§8.6.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO PUEDE ESPERAR AL MOTOR COMPLETO
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.6.3 es tajante: **la división paciente/aseguradora se calcula en el
 * momento del cargo, no al cierre.** Calcularla al final significa que
 * nunca se supo cuánto debía el paciente mientras estaba internado — y
 * cuando se supo, ya se fue.
 *
 * Así que entran ahora las dos piezas que viven en el convenio y no
 * necesitan acumuladores por persona:
 *
 *   `cobertura_fraccion` — el 80/20 típico del mercado hondureño.
 *   `tope_por_evento`    — el techo de esa cobertura EN ESTE EVENTO
 *                          (L 30,000 en las pólizas verificadas; el
 *                          exceso lo paga el paciente al 100 %).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO ENTRA ACÁ, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 * El **deducible** es un saldo acumulado por persona y por año póliza,
 * no un porcentaje (§8.6.5, §9.H9). Modelarlo como columna del convenio
 * sería modelarlo mal y produciría cobros dobles. Vive en la tabla de
 * pólizas del bloque 4b, junto con las carencias, la precertificación y
 * el máximo vitalicio.
 *
 * ⚠️ Estos valores no llevan vigencia propia todavía: renegociar el
 * porcentaje con una aseguradora cambia la fila y no reconstruye «qué
 * cubría en marzo». Lo que sí queda entero es el pasado, porque cada
 * cargo guarda su `cobertura_fraccion` congelada. El día que haga falta
 * el historial, esto se muda a `convenio_condiciones`, que ya tiene
 * vigencia — y los cargos viejos no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenios', function (Blueprint $tabla): void {
            /*
             * Cero por defecto y no 0.80: un convenio recién creado no
             * cubre nada hasta que alguien escriba cuánto cubre. El
             * default generoso es cómo se regala dinero en silencio.
             */
            $tabla->decimal('cobertura_fraccion', 6, 4)->default(0)->after('requiere_autorizacion');

            $tabla->decimal('tope_por_evento', 14, 2)->nullable()->after('cobertura_fraccion');

            /*
             * Si el ítem no tiene fila propia en el tarifario de este
             * convenio, ¿se asume cubierto? Para una aseguradora, sí:
             * cubre el catálogo y excluye lo que excluye. Para CONTADO,
             * no hay nada que cubrir.
             */
            $tabla->boolean('cubre_por_defecto')->default(false)->after('tope_por_evento');
        });

        DB::statement(
            'ALTER TABLE convenios ADD CONSTRAINT convenios_cobertura_valida
             CHECK (cobertura_fraccion >= 0 AND cobertura_fraccion <= 1)'
        );

        DB::statement(
            'ALTER TABLE convenios ADD CONSTRAINT convenios_tope_positivo
             CHECK (tope_por_evento IS NULL OR tope_por_evento > 0)'
        );

        /*
         * El contado no cubre. Que la base lo impida evita el error de
         * dedo que le regalaría el 80 % a todo el que paga en efectivo.
         */
        DB::statement(
            "ALTER TABLE convenios ADD CONSTRAINT convenios_contado_no_cubre
             CHECK (tipo <> 'contado' OR (cobertura_fraccion = 0 AND cubre_por_defecto = false))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_contado_no_cubre');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_tope_positivo');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_cobertura_valida');

        Schema::table('convenios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['cobertura_fraccion', 'tope_por_evento', 'cubre_por_defecto']);
        });
    }
};
