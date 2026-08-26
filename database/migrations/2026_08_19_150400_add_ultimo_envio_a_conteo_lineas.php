<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El token que distingue «volví a contar» de «apreté dos veces».
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN TOKEN Y NO UNA VENTANA DE TIEMPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `exige_recuento` se apaga a la segunda lectura, así que un doble clic
 * con el mismo número satisfacía el control de segunda pasada sin que
 * nadie volviera al estante (§9.G4).

 * El primer intento de arreglarlo comparaba `contado_en` contra `now()`
 * de PHP: si la lectura anterior era idéntica y de hace pocos segundos,
 * era el mismo envío. **No funciona**, y falla en silencio: `contado_en`
 * es `timestamptz`, y comparar un instante leído de la base contra el
 * reloj de la aplicación asume que el viaje de ida y vuelta conserva la
 * zona horaria. Es justo la suposición que el §7.5 prohíbe hacer, y de
 * la que depender significa que el control se apaga o se queda pegado
 * según la configuración del servidor.
 *
 * El token no mira ningún reloj: la pantalla genera uno por envío y lo
 * renueva **después de cada registro exitoso**. Dos peticiones con el
 * mismo token son la misma acción de la persona; una con token nuevo es
 * una observación nueva. Es el mismo cinturón que `ajustes.clave_idempotencia`.
 *
 * Nulo cuando la lectura viene de un comando o un import, que no tienen
 * pantalla ni doble clic posible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conteo_lineas', function (Blueprint $tabla): void {
            $tabla->uuid('ultimo_envio')->nullable()->after('exige_recuento');
        });

        /*
         * NO es único: la misma línea puede recibir varios envíos a lo
         * largo del conteo, y lo que interesa es solo el último. El
         * índice existe para que la comparación no recorra la tabla el
         * día que un conteo tenga trescientas líneas.
         */
        DB::statement(
            'CREATE INDEX conteo_lineas_ultimo_envio
             ON conteo_lineas (ultimo_envio)
             WHERE ultimo_envio IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conteo_lineas_ultimo_envio');

        Schema::table('conteo_lineas', function (Blueprint $tabla): void {
            $tabla->dropColumn('ultimo_envio');
        });
    }
};
