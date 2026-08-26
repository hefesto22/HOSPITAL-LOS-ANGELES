<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL DESCUENTO DEL HOSPITAL VIVE EN LA CUENTA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EN LA TABLA Y NO EN LA PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Estaba guardado en el estado de Livewire, que dura lo que dura la
 * pantalla abierta. Alcanzaba para cargar diez líneas seguidas, pero se
 * perdía en cuanto quien atiende iba a otro módulo y volvía — y entonces
 * el mismo paciente terminaba con dos líneas al 30 % y una a precio
 * lleno, en la misma cuenta, sin que nadie lo decidiera.
 *
 * Acá la rebaja se autoriza UNA vez por cuenta. Se cambia o se quita,
 * pero no se vuelve a poner en cada línea: es un acuerdo con el paciente,
 * no un atributo del renglón.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS CUATRO CAMPOS SON UNA SOLA COSA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porcentaje, motivo, quién y cuándo van juntos o no van. Un descuento
 * sin motivo no se puede explicar; uno sin nombre no se le puede
 * preguntar a nadie. El CHECK los amarra para que ninguna ruta de
 * escritura —ni un import, ni una consola— pueda dejar la mitad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $tabla): void {
            /*
             * Fracción, no porcentaje: 0.3000 = 30 %. El dominio habla en
             * fracciones y la pantalla en porcentajes, y la conversión
             * ocurre en un solo lugar. Cuatro decimales para que un
             * 12.5 % no se pierda al redondear.
             */
            $tabla->decimal('descuento_hospital', 5, 4)->nullable();
            $tabla->string('motivo_descuento_hospital', 200)->nullable();

            $tabla->foreignId('descuento_hospital_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $tabla->timestampTz('descuento_hospital_en')->nullable();
        });

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_descuento_hospital_completo
             CHECK (
                 descuento_hospital IS NULL
                 OR (
                     descuento_hospital > 0
                     AND descuento_hospital <= 1
                     AND motivo_descuento_hospital IS NOT NULL
                     AND length(btrim(motivo_descuento_hospital)) >= 10
                     AND descuento_hospital_en IS NOT NULL
                 )
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_descuento_hospital_completo');

        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('descuento_hospital_por');
            $tabla->dropColumn([
                'descuento_hospital',
                'motivo_descuento_hospital',
                'descuento_hospital_en',
            ]);
        });
    }
};
