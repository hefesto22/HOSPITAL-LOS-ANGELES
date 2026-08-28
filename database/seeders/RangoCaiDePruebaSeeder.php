<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\RangoCai;
use App\Models\Sede;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Un rango de numeración FALSO, para poder emitir y ver cómo imprime.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ESTO NO ES UNA RESOLUCIÓN DEL SAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * El CAI de acá abajo es `AAAAAA-BBBBBB-…`: no lo autorizó nadie y se
 * ve a un metro de distancia que es de mentira, a propósito. Una
 * factura emitida con este rango NO VALE — no la puede usar el cliente,
 * no se puede declarar, y si sale por la ventanilla es una multa.
 *
 * Sirve para una sola cosa: que la pantalla de facturación tenga de
 * dónde sacar un número y se pueda ver la impresión con datos adentro.
 *
 * ⚠️ ANTES DE EMITIR DE VERDAD hay que entrar a «Rangos de CAI»,
 * DESACTIVAR este y cargar el de la resolución en papel. Mientras este
 * siga activo, la caja va a numerar con él.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE ESTE SEEDER NO HACE, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 * No toca el RTN, la dirección ni el teléfono de la sede. Esos son datos
 * reales del hospital y se cargan en «Sedes»: inventarlos acá haría que
 * alguien los diera por buenos y termináramos con un RTN falso impreso
 * en el encabezado de una factura. Hasta que se carguen, el papel sale
 * con «—» donde va el RTN. Es la misma decisión del `SedeSeeder`.
 *
 * No se corre solo: no está en `DatabaseSeeder`. Se pide a mano.
 *
 *     php artisan db:seed --class=RangoCaiDePruebaSeeder
 */
class RangoCaiDePruebaSeeder extends Seeder
{
    /**
     * 32 hexadecimales en los seis grupos de siempre, pero con una cara
     * que nadie puede confundir con un CAI real.
     */
    private const CAI = 'AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE-FF';

    /**
     * Los tres segmentos de la izquierda. `003` y `01` son los que
     * aparecen en las facturas de papel del hospital; el
     * establecimiento queda en `000` porque ese sí sale de la
     * resolución y todavía no la tenemos.
     */
    private const ESTABLECIMIENTO = '000';

    private const PUNTO_EMISION = '003';

    private const TIPO_CODIGO = '01';

    public function run(): void
    {
        if (App::isProduction()) {
            $this->command?->error('✗ No. Este rango es de mentira y esto es producción.');

            return;
        }

        $sede = Sede::query()->where('codigo', 'HLA')->first();

        if (! $sede instanceof Sede) {
            $this->command?->error('✗ No hay sede. Corré primero: php artisan db:seed --class=SedeSeeder');

            return;
        }

        /*
         * Si ya está, NO se vuelve a escribir. `updateOrCreate` acá
         * sería un error grave: devolvería `siguiente` a 1 y la próxima
         * factura repetiría un número ya emitido, que es exactamente lo
         * que el correlativo bloqueado existe para impedir.
         */
        $yaEstaba = RangoCai::query()
            ->deTodasLasSedes()
            ->where('cai', self::CAI)
            ->first();

        if ($yaEstaba instanceof RangoCai) {
            $this->command?->info('✓ El rango de prueba ya estaba cargado.');
            $this->avisar($yaEstaba);

            return;
        }

        /*
         * Un índice único parcial impide dos rangos activos para la
         * misma sede, tipo y punto de emisión. Si ya hay uno —y podría
         * ser el de verdad— no se lo desactiva por la espalda: se avisa
         * y no se hace nada.
         */
        $enUso = RangoCai::query()
            ->deTodasLasSedes()
            ->where('sede_id', $sede->getKey())
            ->where('tipo', TipoDocumentoDeVenta::Factura->value)
            ->where('punto_emision', self::PUNTO_EMISION)
            ->where('activo', true)
            ->first();

        if ($enUso instanceof RangoCai) {
            $this->command?->warn('⚠ Ya hay un rango activo en el punto '.self::PUNTO_EMISION.': '.$enUso->cai);
            $this->command?->warn('  No se toca. Desactivalo desde «Rangos de CAI» si querés usar el de prueba.');

            return;
        }

        $rango = RangoCai::create([
            'sede_id'         => $sede->getKey(),
            'tipo'            => TipoDocumentoDeVenta::Factura->value,
            'cai'             => self::CAI,
            'establecimiento' => self::ESTABLECIMIENTO,
            'punto_emision'   => self::PUNTO_EMISION,
            'tipo_codigo'     => self::TIPO_CODIGO,

            /*
             * Quinientos y no cinco mil: un rango corto hace que
             * «Quedan» se mueva a ojo mientras se prueba, y que agotarlo
             * a propósito para ver el aviso cueste una tarde y no un
             * año.
             */
            'desde'     => 1,
            'hasta'     => 500,
            'siguiente' => 1,

            'fecha_limite_emision' => now()->addMonths(6)->toDateString(),
            'activo'               => true,

            'resolucion' => 'PRUEBA — no autorizada',
            'nota'       => 'Rango FALSO, solo para probar la emisión y la impresión. '
                .'Desactivar antes de emitir facturas de verdad.',
        ]);

        $this->command?->info('✓ Rango de prueba cargado.');
        $this->avisar($rango);
    }

    private function avisar(RangoCai $rango): void
    {
        $this->command?->line('  CAI:              '.$rango->cai);
        $this->command?->line('  Próximo número:   '.$rango->proximoNumero());
        $this->command?->line('  Quedan:           '.$rango->disponibles());
        $this->command?->line('  Vence:            '.$rango->fecha_limite_emision->format('d/m/Y'));
        $this->command?->warn('  ⚠ No vale ante el SAR. Desactivalo antes de emitir de verdad.');
        $this->command?->warn('  ⚠ El encabezado sale sin RTN hasta que lo cargués en «Sedes».');
    }
}
