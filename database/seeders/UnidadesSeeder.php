<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\MagnitudDeMedida;
use App\Models\Unidad;
use Illuminate\Database\Seeder;

/**
 * Las unidades con las que arranca el catálogo.
 *
 * No pretende ser exhaustiva: es el mínimo para poder cargar un
 * medicamento, un insumo y un servicio el primer día. El hospital agrega
 * las suyas desde la pantalla, que para eso es tabla y no enum.
 *
 * Es idempotente (`updateOrCreate` sobre el código), así que se puede
 * volver a correr después de agregar unidades a esta lista sin duplicar
 * ni pisar lo que el hospital haya editado a mano —salvo el nombre y el
 * símbolo, que son justamente lo que esta lista define.
 */
class UnidadesSeeder extends Seeder
{
    /**
     * código => [nombre, símbolo, magnitud, permite fracción]
     *
     * @var array<string, array{0: string, 1: string|null, 2: MagnitudDeMedida, 3: bool}>
     */
    private const UNIDADES = [
        // ── Conteo ────────────────────────────────────────────────────
        'UND'     => ['UNIDAD', 'u', MagnitudDeMedida::Conteo, false],
        'TAB'     => ['TABLETA', 'tab', MagnitudDeMedida::Conteo, false],
        'CAP'     => ['CÁPSULA', 'cáp', MagnitudDeMedida::Conteo, false],
        'AMP'     => ['AMPOLLA', 'amp', MagnitudDeMedida::Conteo, false],
        'VIAL'    => ['VIAL', 'vial', MagnitudDeMedida::Conteo, false],
        'FRASCO'  => ['FRASCO', 'fco', MagnitudDeMedida::Conteo, false],
        'SOBRE'   => ['SOBRE', 'sob', MagnitudDeMedida::Conteo, false],
        'CAJA'    => ['CAJA', 'caja', MagnitudDeMedida::Conteo, false],
        'BLISTER' => ['BLÍSTER', 'blist', MagnitudDeMedida::Conteo, false],
        'PAR'     => ['PAR', 'par', MagnitudDeMedida::Conteo, false],
        'KIT'     => ['KIT', 'kit', MagnitudDeMedida::Conteo, false],
        'BOLSA'   => ['BOLSA', 'bolsa', MagnitudDeMedida::Conteo, false],

        // ── Volumen ───────────────────────────────────────────────────
        /*
         * Mililitro y centímetro cúbico son la MISMA cantidad y aun así
         * van los dos: el médico prescribe en cc y la etiqueta del frasco
         * dice ml. Obligar a uno de los dos genera conversiones mentales
         * en cada dosis, y ahí es donde se equivoca alguien a las 3 am.
         */
        'ML' => ['MILILITRO', 'ml', MagnitudDeMedida::Volumen, true],
        'CC' => ['CENTÍMETRO CÚBICO', 'cc', MagnitudDeMedida::Volumen, true],
        'L'  => ['LITRO', 'L', MagnitudDeMedida::Volumen, true],

        // ── Masa ──────────────────────────────────────────────────────
        'MG'  => ['MILIGRAMO', 'mg', MagnitudDeMedida::Masa, true],
        'G'   => ['GRAMO', 'g', MagnitudDeMedida::Masa, true],
        'MCG' => ['MICROGRAMO', 'mcg', MagnitudDeMedida::Masa, true],
        'UI'  => ['UNIDAD INTERNACIONAL', 'UI', MagnitudDeMedida::Masa, true],

        // ── Longitud ──────────────────────────────────────────────────
        'CM'    => ['CENTÍMETRO', 'cm', MagnitudDeMedida::Longitud, true],
        'METRO' => ['METRO', 'm', MagnitudDeMedida::Longitud, true],

        // ── Tiempo ────────────────────────────────────────────────────
        /*
         * El oxígeno y el alquiler de equipo se cobran por hora o por
         * día. Son ítems facturables como cualquier otro.
         */
        'HORA' => ['HORA', 'h', MagnitudDeMedida::Tiempo, true],
        'DIA'  => ['DÍA', 'd', MagnitudDeMedida::Tiempo, false],
    ];

    public function run(): void
    {
        foreach (self::UNIDADES as $codigo => [$nombre, $simbolo, $magnitud, $permiteFraccion]) {
            Unidad::query()->updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'           => $nombre,
                    'simbolo'          => $simbolo,
                    'magnitud'         => $magnitud,
                    'permite_fraccion' => $permiteFraccion,
                ],
            );
        }

        $this->command?->info('✓ '.count(self::UNIDADES).' unidades de medida sembradas.');
    }
}
