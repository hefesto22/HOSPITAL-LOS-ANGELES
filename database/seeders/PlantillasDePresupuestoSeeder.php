<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PlantillaLinea;
use App\Models\PlantillaPresupuesto;
use Illuminate\Database\Seeder;

/**
 * Plantillas de presupuesto de ARRANQUE (ADR-0008).
 *
 * ⚠️ ESTAS LISTAS SON UN PUNTO DE PARTIDA, NO LA VERDAD DEL HOSPITAL.
 *
 * Las armó el desarrollo con los ítems que ya están en el catálogo y con
 * cantidades razonables, para que el módulo se pueda probar el día uno.
 * **Lo que lleva de verdad una apendicectomía lo sabe el quirófano**, y
 * se corrige en la pantalla de plantillas —que existe justamente para
 * eso—.
 *
 * 🔴 IDEMPOTENTE Y NO PISA NADA. Si la plantilla ya existe, este seeder
 * NO la toca: correrlo de nuevo después de que el hospital ajustó las
 * cantidades borraría ese trabajo sin avisar.
 *
 * Los honorarios del cirujano y del anestesiólogo NO están acá a
 * propósito: cambian por médico y por caso, así que entran como línea
 * manual al cotizar. El catálogo solo tiene `HOS-020 HONORARIOS MEDICO
 * GENERAL`, que no es lo mismo.
 */
class PlantillasDePresupuestoSeeder extends Seeder
{
    /**
     * @var array<string, array{nombre: string, descripcion: string, dias: int, holgura: string, lineas: array<int, array{0: string, 1: string, 2?: bool}>}>
     */
    private const PLANTILLAS = [
        'CX-APENDICE' => [
            'nombre'      => 'APENDICECTOMIA',
            'descripcion' => 'Cirugía de apéndice sin complicaciones, con tres días de estancia estimados.',
            'dias'        => 15,
            'holgura'     => '0.1000',
            'lineas'      => [
                ['HOS-010', '1'],
                ['HOS-008', '1'],
                ['HOS-003', '3'],
                ['HOS-001', '3'],
                ['HOS-002', '1'],
                ['HOS-020', '1'],
                ['HOS-026', '1'],
                ['HOS-027', '1'],
                ['HOS-025', '1'],
                ['HOS-021', '3'],
                ['EQP-011', '1'],
                ['EQP-006', '1'],
                ['EQP-002', '3'],
                ['LAB-029', '1'],
                ['LAB-025', '1'],
                ['LAB-014', '1'],
                ['LAB-024', '1'],
                ['RX-001', '1', true],
            ],
        ],
        'CX-CESAREA' => [
            'nombre'      => 'CESAREA',
            'descripcion' => 'Cesárea con dos días de estancia y sala cuna para el recién nacido.',
            'dias'        => 30,
            'holgura'     => '0.1000',
            'lineas'      => [
                ['HOS-011', '1'],
                ['HOS-012', '1'],
                ['HOS-005', '2'],
                ['HOS-003', '2'],
                ['HOS-001', '2'],
                ['HOS-002', '1'],
                ['HOS-024', '1'],
                ['HOS-025', '1'],
                ['HOS-026', '1'],
                ['EQP-011', '1'],
                ['EQP-004', '1'],
                ['LAB-029', '1'],
            ],
        ],
    ];

    public function run(): void
    {
        $faltantes = [];

        foreach (self::PLANTILLAS as $codigo => $definicion) {
            if (PlantillaPresupuesto::query()->where('codigo', $codigo)->exists()) {
                $this->command?->info("Plantilla {$codigo} ya existe — no se toca.");

                continue;
            }

            $plantilla = PlantillaPresupuesto::create([
                'codigo'           => $codigo,
                'nombre'           => $definicion['nombre'],
                'descripcion'      => $definicion['descripcion'],
                'dias_vigencia'    => $definicion['dias'],
                'holgura_fraccion' => $definicion['holgura'],
                'vigencia_desde'   => now()->toDateString(),
            ]);

            $orden = 0;

            foreach ($definicion['lineas'] as $renglon) {
                $item = Item::query()->where('codigo', $renglon[0])->first();

                if (! $item instanceof Item) {
                    $faltantes[] = $renglon[0];

                    continue;
                }

                $orden += 10;

                PlantillaLinea::create([
                    'plantilla_id' => $plantilla->id,
                    'item_id'      => $item->id,
                    'cantidad'     => $renglon[1],
                    'orden'        => $orden,
                    'opcional'     => $renglon[2] ?? false,
                ]);
            }

            $this->command?->info("Plantilla {$codigo} creada con {$plantilla->lineas()->count()} líneas.");
        }

        if ($faltantes === []) {
            return;
        }

        /*
         * No revienta: avisa. Un catálogo a medio cargar es normal en la
         * puesta en marcha, y una plantilla con quince de dieciocho
         * renglones sigue sirviendo. Lo que no puede pasar es que falten
         * en silencio.
         */
        $this->command?->warn(
            'Ítems que no están en el catálogo y quedaron fuera de las plantillas: '
            .implode(', ', array_unique($faltantes))
        );
    }
}
