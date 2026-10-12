<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL DESCUENTO EN SERVICIOS, AL LADO DEL DE FARMACIA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si se aplica un descuento por monto o por porcentaje, se
 * aplicaría a todo lo que no sea materiales y medicamentos, ya que ese
 * es descuento exclusivo de medicamentos y materiales».
 *
 * Son dos descuentos de la cuenta y no uno con un interruptor. El de
 * farmacia (`descuento_hospital`) es solo porcentaje y se topa con la
 * política de medicamentos; este va en porcentaje O en lempiras, cae en
 * todo lo demás —hospitalización, consultas, laboratorio, rayos X— y se
 * topa por categoría con la ley (`PoliticaDeDescuentoComercial`).
 *
 * Igual que el de farmacia, vive en la cuenta y no en la pantalla: se
 * autoriza una vez, alcanza a lo que ya está cargado y a lo que se
 * cargue después.
 *
 * ─────────────────────────────────────────────────────────────────────
 * O PORCENTAJE O MONTO, NUNCA LOS DOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * «10 % y L 500» no dice cuánto se le rebajó. El CHECK deja uno solo, y
 * amarra el motivo, el cuándo y el quién como el de farmacia: un
 * descuento sin explicación no lo deja ninguna ruta de escritura, ni un
 * import ni una consola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $tabla): void {
            /*
             * Fracción, como el de farmacia: 0.1000 = 10 %. El monto va
             * en lempiras con dos decimales, que es como se reparte entre
             * los renglones.
             */
            $tabla->decimal('descuento_servicios', 5, 4)->nullable();
            $tabla->decimal('descuento_servicios_monto', 14, 2)->nullable();
            $tabla->string('motivo_descuento_servicios', 200)->nullable();

            $tabla->foreignId('descuento_servicios_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $tabla->timestampTz('descuento_servicios_en')->nullable();
        });

        DB::statement(
            'ALTER TABLE cuentas ADD CONSTRAINT cuentas_descuento_servicios_completo
             CHECK (
                 (descuento_servicios IS NULL AND descuento_servicios_monto IS NULL)
                 OR (
                     (descuento_servicios IS NULL) <> (descuento_servicios_monto IS NULL)
                     AND (descuento_servicios IS NULL OR (descuento_servicios > 0 AND descuento_servicios <= 1))
                     AND (descuento_servicios_monto IS NULL OR descuento_servicios_monto > 0)
                     AND motivo_descuento_servicios IS NOT NULL
                     AND length(btrim(motivo_descuento_servicios)) >= 10
                     AND descuento_servicios_en IS NOT NULL
                 )
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas DROP CONSTRAINT IF EXISTS cuentas_descuento_servicios_completo');

        Schema::table('cuentas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('descuento_servicios_por');
            $tabla->dropColumn([
                'descuento_servicios',
                'descuento_servicios_monto',
                'motivo_descuento_servicios',
                'descuento_servicios_en',
            ]);
        });
    }
};
