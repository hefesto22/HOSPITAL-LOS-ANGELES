<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ANULAR UNA CUENTA ABIERTA POR ERROR (Mauricio, 21-oct-2026).
 *
 * Las columnas `anulada_en` y `motivo_anulacion` existían desde el bloque
 * 4 —y el CHECK `cuentas_anulacion_completa` las exige—, pero ningún
 * servicio anulaba: una cuenta abierta por accidente quedaba viva para
 * siempre y además le bloqueaba al paciente abrir la correcta (una sola
 * cuenta viva por paciente, ADR-0007).
 *
 * Lo que se agrega es lo que hace falta para AUDITAR la anulación, no
 * solo para hacerla:
 *
 *   - `anulada_por` / `anulado_por`: quién. `updated_by` no alcanza: lo
 *     pisa el próximo que toque la fila.
 *   - `tipo_anulacion`: el motivo tipificado, para poder agruparlas
 *     (`MotivoDeAnulacionDeCuenta`). El texto sigue en `motivo_anulacion`.
 *   - `total_al_anular`: cuánto llevaba cargado. Después de anular, los
 *     totales quedan en cero —cada cargo con su reversa—, y el escritorio
 *     de dirección necesita decir «se anularon L 1,200 hoy» sin sumar
 *     reversas.
 *
 * Todo nullable y sin backfill: las cuentas anuladas que pudiera haber
 * de antes no tienen de dónde sacar esos datos, y un CHECK que los exija
 * rompería la migración sobre una base con datos. El servicio
 * (`AnuladorDeCuenta`) es el que los llena siempre.
 */
return new class extends Migration
{
    private const TIPOS = "'abierta_por_error', 'paciente_equivocado', 'pagador_o_tipo', 'no_se_atendio', 'otro'";

    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->foreignId('anulada_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $tabla->string('tipo_anulacion', 30)->nullable();
            $tabla->decimal('total_al_anular', 14, 2)->nullable();
        });

        Schema::table('encuentros', function (Blueprint $tabla): void {
            $tabla->foreignId('anulado_por')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_tipo_anulacion_conocido
             CHECK (tipo_anulacion IS NULL OR tipo_anulacion IN ('.self::TIPOS.'))'
        );

        /*
         * Para el escritorio de dirección: «anuladas hoy» lee solo las
         * anuladas, que son pocas, y no la tabla entera.
         */
        DB::statement(
            "CREATE INDEX cuentas_anuladas ON cuentas (sede_id, anulada_en DESC)
             WHERE estado = 'anulada'"
        );

        DB::statement('CREATE INDEX cuentas_anulada_por ON cuentas (anulada_por) WHERE anulada_por IS NOT NULL');
        DB::statement('CREATE INDEX encuentros_anulado_por ON encuentros (anulado_por) WHERE anulado_por IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS encuentros_anulado_por');
        DB::statement('DROP INDEX IF EXISTS cuentas_anulada_por');
        DB::statement('DROP INDEX IF EXISTS cuentas_anuladas');
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_tipo_anulacion_conocido');

        Schema::table('encuentros', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('anulado_por');
        });

        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('anulada_por');
            $tabla->dropColumn(['tipo_anulacion', 'total_al_anular']);
        });
    }
};
