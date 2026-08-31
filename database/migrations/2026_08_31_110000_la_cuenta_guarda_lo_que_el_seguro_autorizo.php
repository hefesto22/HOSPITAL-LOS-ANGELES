<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el seguro autorizó PARA ESTA CUENTA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CONVENIO TRAE EL DEFAULT; EL CASO TRAE LA REALIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * `convenios.cobertura_fraccion` dice lo que ese pagador cubre EN
 * GENERAL, y eso es lo que se usa mientras la cuenta se va cargando.
 * Pero la autorización llega después y no siempre coincide:
 *
 *   · El Hospital Militar no cubre porcentaje: aprueba un MONTO. La
 *     cuenta sumó L 10,000 y ellos autorizaron L 5,000.
 *   · PALIG cubre el 50 % por contrato, pero en un caso concreto puede
 *     decir «de esto solo cubro el 30 %».
 *
 * Sin un lugar donde anotarlo, la única salida era corregir a mano cada
 * cobro —o sea, que tarde o temprano se cobrara mal—.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LOS CARGOS NO SE TOCAN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada cargo guarda el reparto que se calculó EL DÍA QUE OCURRIÓ, y el
 * trigger `cargos_append_only` lo impide cambiar —a propósito: un cargo
 * es un hecho, no una opinión revisable—.
 *
 * La autorización es información POSTERIOR sobre la misma cuenta, y lo
 * que corrige no es ningún asiento: es a quién se le cobra el total.
 * Por eso vive acá, en la cuenta, y `recalcular()` reparte el total
 * según ella. El total nunca cambia; cambia de qué lado cae.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA U OTRA, NUNCA LAS DOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porcentaje y monto juntos no es «más preciso»: es una ambigüedad
 * sobre cuánto cubre el seguro, y quien la resuelva eligiendo uno de los
 * dos va a elegir mal alguna vez. Lo impide un CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $tabla): void {
            /*
             * El porcentaje que el seguro autorizó para esta cuenta.
             * Nulo = manda el del convenio.
             */
            $tabla->decimal('cobertura_autorizada', 6, 4)->nullable()->after('numero_autorizacion');

            /*
             * El monto tope que autorizó. Nulo = sin monto: o manda el
             * porcentaje de arriba, o el del convenio.
             */
            $tabla->decimal('monto_autorizado', 14, 2)->nullable()->after('cobertura_autorizada');

            /* Quién lo anotó y cuándo: es una decisión con plata detrás. */
            $tabla->timestamp('autorizacion_en')->nullable()->after('monto_autorizado');
            $tabla->foreignId('autorizacion_por')->nullable()->after('autorizacion_en')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE cuentas
             ADD CONSTRAINT cuentas_autorizacion_en_una_sola_forma
             CHECK (cobertura_autorizada IS NULL OR monto_autorizado IS NULL)'
        );

        DB::statement(
            'ALTER TABLE cuentas
             ADD CONSTRAINT cuentas_cobertura_autorizada_valida
             CHECK (cobertura_autorizada IS NULL OR (cobertura_autorizada >= 0 AND cobertura_autorizada <= 1))'
        );

        DB::statement(
            'ALTER TABLE cuentas
             ADD CONSTRAINT cuentas_monto_autorizado_no_negativo
             CHECK (monto_autorizado IS NULL OR monto_autorizado >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_monto_autorizado_no_negativo');
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_cobertura_autorizada_valida');
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_autorizacion_en_una_sola_forma');

        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('autorizacion_por');
            $tabla->dropColumn(['cobertura_autorizada', 'monto_autorizado', 'autorizacion_en']);
        });
    }
};
