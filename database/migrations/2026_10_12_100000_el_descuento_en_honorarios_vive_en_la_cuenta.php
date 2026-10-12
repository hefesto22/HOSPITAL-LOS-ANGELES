<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL DESCUENTO EN HONORARIOS, AL LADO DEL DE SERVICIOS (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «según lo que me dijeron también hacían descuentos en
 * honorarios, así que estaría bueno dejar un campo para descuentos ahí».
 *
 * Las mismas cinco columnas que el de servicios
 * (`2026_10_11_150000`), con el mismo CHECK: porcentaje O monto, nunca
 * los dos, y nunca sin motivo, sin cuándo. Va aparte del de servicios
 * porque rebajar un honorario es rebajarle al médico: se decide y se
 * explica por separado. Ver `RubroDeDescuento`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->decimal('descuento_honorarios', 5, 4)->nullable();
            $tabla->decimal('descuento_honorarios_monto', 14, 2)->nullable();
            $tabla->string('motivo_descuento_honorarios', 200)->nullable();

            $tabla->foreignId('descuento_honorarios_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $tabla->timestampTz('descuento_honorarios_en')->nullable();
        });

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_descuento_honorarios_completo
             CHECK (
                 (descuento_honorarios IS NULL AND descuento_honorarios_monto IS NULL)
                 OR (
                     (descuento_honorarios IS NULL) <> (descuento_honorarios_monto IS NULL)
                     AND (descuento_honorarios IS NULL OR (descuento_honorarios > 0 AND descuento_honorarios <= 1))
                     AND (descuento_honorarios_monto IS NULL OR descuento_honorarios_monto > 0)
                     AND motivo_descuento_honorarios IS NOT NULL
                     AND length(btrim(motivo_descuento_honorarios)) >= 10
                     AND descuento_honorarios_en IS NOT NULL
                 )
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_descuento_honorarios_completo');

        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('descuento_honorarios_por');
            $tabla->dropColumn([
                'descuento_honorarios',
                'descuento_honorarios_monto',
                'motivo_descuento_honorarios',
                'descuento_honorarios_en',
            ]);
        });
    }
};
