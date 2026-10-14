<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA COBERTURA VACÍA SE DECIDE AL FACTURAR (Mauricio, 13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * «NO CON TODOS ES IGUAL»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Guardar el Hospital Militar con la cobertura vacía daba 500: la columna
 * era NOT NULL. Y vacía es justo lo que se quería decir: con ese pagador
 * no hay un porcentaje general, cada cuenta se autoriza aparte y el resto
 * lo paga el paciente —el copago, como lo llamó la doctora—.
 *
 * Desde acá, tres valores con tres significados:
 *
 *   NULL   se decide al facturar. Mientras la cuenta se carga, cada
 *          renglón va entero al paciente; al emitir, quien cobra
 *          escribe lo que autorizó el seguro y no puede dejarlo vacío.
 *   0      el pagador no pone nada salvo que esa cuenta diga otra cosa.
 *   0.80   el 80 % en general; al facturar se puede cambiar por cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS CHECK
 * ─────────────────────────────────────────────────────────────────────
 *
 * `convenios_cobertura_valida` (entre 0 y 1) se queda como está: en
 * PostgreSQL un CHECK que da NULL no rechaza, así que la fila vacía pasa
 * sola y la que trae número se sigue midiendo.
 *
 * `convenios_no_cubre_si_no_paga_un_tercero` SÍ se reescribe. Por esa
 * misma regla del NULL, un contado con la cobertura vacía habría pasado
 * el `cobertura_fraccion = 0`. Quien no paga no cubre: ni un porcentaje
 * ni «lo vemos al facturar». Se exige el cero explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE convenios ALTER COLUMN cobertura_fraccion DROP NOT NULL');

        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_no_cubre_si_no_paga_un_tercero');
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_no_cubre_si_no_paga_un_tercero
             CHECK (tipo NOT IN ('contado', 'reembolso')
                    OR (cobertura_fraccion IS NOT NULL AND cobertura_fraccion = 0 AND cubre_por_defecto = false))"
        );
    }

    /**
     * ⚠️ Volver atrás pone en CERO los que estaban vacíos: es lo más
     * cercano que entiende la columna vieja —el paciente carga todo hasta
     * que se anote una autorización—, pero se pierde la marca de «se
     * decide al facturar».
     */
    public function down(): void
    {
        DB::statement('UPDATE convenios SET cobertura_fraccion = 0 WHERE cobertura_fraccion IS NULL');

        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_no_cubre_si_no_paga_un_tercero');
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_no_cubre_si_no_paga_un_tercero
             CHECK (tipo NOT IN ('contado', 'reembolso') OR (cobertura_fraccion = 0 AND cubre_por_defecto = false))"
        );

        DB::statement('ALTER TABLE convenios ALTER COLUMN cobertura_fraccion SET NOT NULL');
    }
};
