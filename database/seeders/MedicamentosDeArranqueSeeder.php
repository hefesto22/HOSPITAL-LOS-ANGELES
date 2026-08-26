<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Tarifario;
use App\Models\Unidad;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Medicamentos e insumos de arranque, con sus presentaciones.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LOS PRECIOS DE ESTE ARCHIVO SON DE ARRANQUE, NO SON REALES
 * ─────────────────────────────────────────────────────────────────────
 *
 * El tarifario de PALIG que se fotografió NO trae medicamentos. Los
 * precios de acá son valores de referencia para que el sistema tenga con
 * qué trabajar desde el primer día: **hay que reemplazarlos por los del
 * hospital antes de cobrarle a un paciente.**
 *
 * Lo correcto, cuando estén los costos reales de compra, es dejar que el
 * precio se DERIVE del costo promedio por el margen objetivo —que es
 * para lo que existe `CalculadoraDePrecioDeLista` (§4.5)— en vez de
 * teclear cada uno. Estos números son el andamio, no la obra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ CADA UNO TRAE SU PRESENTACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El kardex se lleva SIEMPRE en unidad de dispensación —tabletas, no
 * cajas— pero lo que se compra y lo que se escanea es el envase. Sin la
 * presentación no hay dónde guardar el código de barras ni cómo saber
 * que una caja trae cien, y entonces «¿Se cobra por…?» no tiene qué
 * ofrecer en el mostrador.
 *
 * Los códigos de barras van vacíos a propósito: se cargan escaneando el
 * producto real desde el catálogo. Inventarlos sería peor que no
 * tenerlos, porque el día que alguien escanee de verdad no coincidiría.
 *
 * Se corre a mano y es idempotente:
 *
 *     php artisan db:seed --class=MedicamentosDeArranqueSeeder
 */
class MedicamentosDeArranqueSeeder extends Seeder
{
    private const VIGENCIA_DESDE = '2026-08-01';

    /**
     * código, nombre, principio activo, unidad de dispensación,
     * ¿receta?, ¿controlado?, precio de lista de arranque,
     * y las presentaciones: [nombre, unidad del envase, cuánto trae].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: bool, 6: numeric-string, 7: list<array{0: string, 1: string, 2: numeric-string}>}>
     */
    private const MEDICAMENTOS = [
        // ── Analgésicos y antiinflamatorios ───────────────────────────
        ['MED-101', 'ACETAMINOFEN 500 MG TABLETA', 'ACETAMINOFEN', 'TAB', false, false, '3.00', [
            ['CAJA X 100 TABLETAS', 'CAJA', '100'],
            ['BLISTER X 10 TABLETAS', 'BLISTER', '10'],
        ]],
        ['MED-102', 'IBUPROFENO 400 MG TABLETA', 'IBUPROFENO', 'TAB', false, false, '4.00', [
            ['CAJA X 100 TABLETAS', 'CAJA', '100'],
        ]],
        ['MED-103', 'DICLOFENACO 75 MG / 3 ML AMPOLLA', 'DICLOFENACO SODICO', 'AMP', true, false, '25.00', [
            ['CAJA X 5 AMPOLLAS', 'CAJA', '5'],
        ]],
        ['MED-104', 'KETOROLACO 30 MG / ML AMPOLLA', 'KETOROLACO TROMETAMINA', 'AMP', true, false, '35.00', [
            ['CAJA X 5 AMPOLLAS', 'CAJA', '5'],
        ]],
        ['MED-105', 'DIPIRONA 1 G / 2 ML AMPOLLA', 'METAMIZOL SODICO', 'AMP', true, false, '22.00', [
            ['CAJA X 5 AMPOLLAS', 'CAJA', '5'],
        ]],

        // ── Antibióticos ──────────────────────────────────────────────
        ['MED-201', 'AMOXICILINA 500 MG CAPSULA', 'AMOXICILINA', 'CAP', true, false, '6.00', [
            ['CAJA X 100 CAPSULAS', 'CAJA', '100'],
        ]],
        ['MED-202', 'CEFTRIAXONA 1 G VIAL', 'CEFTRIAXONA SODICA', 'VIAL', true, false, '95.00', [
            ['CAJA X 10 VIALES', 'CAJA', '10'],
        ]],
        ['MED-203', 'CIPROFLOXACINA 500 MG TABLETA', 'CIPROFLOXACINA', 'TAB', true, false, '9.00', [
            ['CAJA X 50 TABLETAS', 'CAJA', '50'],
        ]],
        ['MED-204', 'GENTAMICINA 80 MG / 2 ML AMPOLLA', 'GENTAMICINA SULFATO', 'AMP', true, false, '18.00', [
            ['CAJA X 10 AMPOLLAS', 'CAJA', '10'],
        ]],
        ['MED-205', 'METRONIDAZOL 500 MG / 100 ML BOLSA', 'METRONIDAZOL', 'BOLSA', true, false, '48.00', [
            ['CAJA X 12 BOLSAS', 'CAJA', '12'],
        ]],

        // ── Gastro ────────────────────────────────────────────────────
        ['MED-301', 'OMEPRAZOL 40 MG VIAL', 'OMEPRAZOL SODICO', 'VIAL', true, false, '70.00', [
            ['CAJA X 10 VIALES', 'CAJA', '10'],
        ]],
        ['MED-302', 'RANITIDINA 50 MG / 2 ML AMPOLLA', 'RANITIDINA CLORHIDRATO', 'AMP', true, false, '16.00', [
            ['CAJA X 25 AMPOLLAS', 'CAJA', '25'],
        ]],
        ['MED-303', 'METOCLOPRAMIDA 10 MG / 2 ML AMPOLLA', 'METOCLOPRAMIDA', 'AMP', true, false, '14.00', [
            ['CAJA X 25 AMPOLLAS', 'CAJA', '25'],
        ]],

        // ── Cardiovascular y metabólico ───────────────────────────────
        ['MED-401', 'ENALAPRIL 10 MG TABLETA', 'ENALAPRIL MALEATO', 'TAB', true, false, '3.50', [
            ['CAJA X 30 TABLETAS', 'CAJA', '30'],
        ]],
        ['MED-402', 'FUROSEMIDA 20 MG / 2 ML AMPOLLA', 'FUROSEMIDA', 'AMP', true, false, '15.00', [
            ['CAJA X 25 AMPOLLAS', 'CAJA', '25'],
        ]],
        ['MED-403', 'INSULINA HUMANA NPH 100 UI / ML FRASCO 10 ML', 'INSULINA HUMANA ISOFANICA', 'FRASCO', true, false, '380.00', [
            ['CAJA X 1 FRASCO', 'CAJA', '1'],
        ]],

        // ── Anestesia y sedación ──────────────────────────────────────
        ['MED-501', 'LIDOCAINA 2 % FRASCO 50 ML', 'LIDOCAINA CLORHIDRATO', 'FRASCO', true, false, '85.00', [
            ['CAJA X 25 FRASCOS', 'CAJA', '25'],
        ]],

        /*
         * ⚠️ CONTROLADOS. Llevan receta obligatoria y, por el §9.F11, el
         * conteo físico los mide pero NO los ajusta solo: una diferencia
         * en un controlado es un acta ante ARSA, no un asiento contable.
         */
        ['MED-502', 'TRAMADOL 100 MG / 2 ML AMPOLLA', 'TRAMADOL CLORHIDRATO', 'AMP', true, true, '45.00', [
            ['CAJA X 5 AMPOLLAS', 'CAJA', '5'],
        ]],
        ['MED-503', 'MIDAZOLAM 5 MG / 5 ML AMPOLLA', 'MIDAZOLAM', 'AMP', true, true, '60.00', [
            ['CAJA X 5 AMPOLLAS', 'CAJA', '5'],
        ]],

        // ── Respiratorio ──────────────────────────────────────────────
        ['MED-601', 'SALBUTAMOL 5 MG / ML SOLUCION FRASCO 20 ML', 'SALBUTAMOL SULFATO', 'FRASCO', true, false, '120.00', [
            ['CAJA X 1 FRASCO', 'CAJA', '1'],
        ]],
        ['MED-602', 'HIDROCORTISONA 100 MG VIAL', 'HIDROCORTISONA SUCCINATO', 'VIAL', true, false, '110.00', [
            ['CAJA X 10 VIALES', 'CAJA', '10'],
        ]],
    ];

    /**
     * Soluciones que se dispensan por frasco pero se administran por ml.
     * Son las que hacen falta para que «¿Se cobra por…?» ofrezca la
     * fracción y para probar la conversión de verdad.
     *
     * código, nombre, contenido en ml, precio de lista de arranque.
     *
     * @var list<array{0: string, 1: string, 2: numeric-string, 3: numeric-string}>
     */
    private const SOLUCIONES = [
        ['MED-701', 'SOLUCION SALINA 0.9 % BOLSA 500 ML', '500', '55.00'],
        ['MED-702', 'SOLUCION SALINA 0.9 % BOLSA 1000 ML', '1000', '75.00'],
        ['MED-703', 'DEXTROSA 5 % BOLSA 500 ML', '500', '60.00'],
        ['MED-704', 'LACTATO DE RINGER BOLSA 1000 ML', '1000', '80.00'],
    ];

    /**
     * Insumos: no llevan receta y muchos ni siquiera lote, pero SÍ salen
     * del kardex y hay que poder cargarlos a la cuenta.
     *
     * código, nombre, unidad, ¿lote?, precio, presentaciones.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: numeric-string, 5: list<array{0: string, 1: string, 2: numeric-string}>}>
     */
    private const INSUMOS = [
        ['INS-101', 'JERINGA DESCARTABLE 5 ML', 'UND', false, '5.00', [['CAJA X 100 JERINGAS', 'CAJA', '100']]],
        ['INS-102', 'JERINGA DESCARTABLE 10 ML', 'UND', false, '6.50', [['CAJA X 100 JERINGAS', 'CAJA', '100']]],
        ['INS-103', 'GASA ESTERIL 10 X 10 CM', 'UND', false, '4.00', [['PAQUETE X 100 GASAS', 'CAJA', '100']]],
        ['INS-104', 'GUANTE QUIRURGICO ESTERIL 7.5', 'PAR', false, '12.00', [['CAJA X 50 PARES', 'CAJA', '50']]],
        ['INS-105', 'CATETER INTRAVENOSO 22 G', 'UND', true, '22.00', [['CAJA X 50 CATETERES', 'CAJA', '50']]],
        ['INS-106', 'EQUIPO DE VENOCLISIS', 'UND', false, '28.00', [['CAJA X 50 EQUIPOS', 'CAJA', '50']]],
        ['INS-107', 'SONDA FOLEY 16 FR', 'UND', false, '65.00', [['CAJA X 10 SONDAS', 'CAJA', '10']]],
        ['INS-108', 'ALCOHOL GEL 500 ML', 'FRASCO', false, '45.00', [['CAJA X 12 FRASCOS', 'CAJA', '12']]],
    ];

    public function run(): void
    {
        $cuantos = 0;

        foreach (self::MEDICAMENTOS as [$codigo, $nombre, $principio, $unidad, $receta, $controlado, $precio, $presentaciones]) {
            $item = $this->item(
                codigo: $codigo,
                nombre: $nombre,
                tipo: TipoItem::Medicamento,
                unidad: $unidad,
                requiereLote: true,
                requiereReceta: $receta,
                esControlado: $controlado,
                principioActivo: $principio,
            );

            $this->presentaciones($item, $presentaciones);
            $this->precioDeLista($item, $precio);
            $cuantos++;
        }

        foreach (self::SOLUCIONES as [$codigo, $nombre, $mililitros, $precio]) {
            $item = $this->item(
                codigo: $codigo,
                nombre: $nombre,
                tipo: TipoItem::Medicamento,
                unidad: 'BOLSA',
                requiereLote: true,
                requiereReceta: true,
                esControlado: false,
                principioActivo: null,
            );

            /*
             * Fraccionable: la bolsa es la unidad de dispensación y el ml
             * es la fracción. Así el mostrador puede cobrar «250 ml» de
             * una bolsa de 500 sin inventar media unidad.
             */
            $item->forceFill([
                'fraccionable'          => true,
                'unidad_fraccion_id'    => $this->unidad('ML')->id,
                'fracciones_por_unidad' => $mililitros,
            ])->save();

            $this->presentaciones($item, [['CAJA X 20 BOLSAS', 'CAJA', '20']]);
            $this->precioDeLista($item, $precio);
            $cuantos++;
        }

        foreach (self::INSUMOS as [$codigo, $nombre, $unidad, $lote, $precio, $presentaciones]) {
            $item = $this->item(
                codigo: $codigo,
                nombre: $nombre,
                tipo: TipoItem::Insumo,
                unidad: $unidad,
                requiereLote: $lote,
                requiereReceta: false,
                esControlado: false,
                principioActivo: null,
            );

            $this->presentaciones($item, $presentaciones);
            $this->precioDeLista($item, $precio);
            $cuantos++;
        }

        $this->command?->info("✓ {$cuantos} medicamentos e insumos sembrados, con sus presentaciones.");
        $this->command?->warn('  🔴 Los precios son DE ARRANQUE, no del hospital. Reemplazalos antes de cobrar.');
        $this->command?->comment('  Los códigos de barras quedaron vacíos: se cargan escaneando el producto real desde el catálogo.');
    }

    private function item(
        string $codigo,
        string $nombre,
        TipoItem $tipo,
        string $unidad,
        bool $requiereLote,
        bool $requiereReceta,
        bool $esControlado,
        ?string $principioActivo,
    ): Item {
        /** @var Item $item */
        $item = Item::query()->updateOrCreate(
            ['codigo' => $codigo],
            [
                'nombre' => $nombre,
                'tipo'   => $tipo,

                /*
                 * Exentos por el Art. 15 inciso b de la Ley del ISV:
                 * productos farmacéuticos de uso humano, material de
                 * curación quirúrgico y jeringas.
                 */
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
                'unidad_dispensacion_id'    => $this->unidad($unidad)->id,
                'requiere_lote'             => $requiereLote,
                'requiere_receta'           => $requiereReceta,
                'es_controlado'             => $esControlado,
                'principio_activo'          => $principioActivo,
                'vigencia_desde'            => self::VIGENCIA_DESDE,
            ],
        );

        return $item;
    }

    /**
     * @param list<array{0: string, 1: string, 2: numeric-string}> $presentaciones
     */
    private function presentaciones(Item $item, array $presentaciones): void
    {
        foreach ($presentaciones as $indice => [$nombre, $envase, $contenido]) {
            ItemPresentacion::query()->updateOrCreate(
                [
                    'item_id' => $item->id,
                    'nombre'  => $nombre,
                ],
                [
                    'unidad_id'                 => $this->unidad($envase)->id,
                    'unidades_por_presentacion' => $contenido,
                    'es_predeterminada'         => $indice === 0,
                    'vigencia_desde'            => self::VIGENCIA_DESDE,
                ],
            );
        }
    }

    /**
     * Solo el precio de lista.
     *
     * Sin fila para PALIG a propósito: el tarifario firmado no incluye
     * medicamentos, así que inventarle un precio negociado sería afirmar
     * algo que nadie firmó. La escalera de `ResolutorDePrecio` hace lo
     * correcto sola — sin fila propia, el pagador paga la lista.
     *
     * @param numeric-string $precio
     */
    private function precioDeLista(Item $item, string $precio): void
    {
        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => null,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => bcmul($precio, '1', 4),
                'motivo' => 'Precio de arranque para poner el sistema en marcha. Reemplazar por el '
                    .'derivado del costo promedio cuando estén las compras reales.',
            ],
        );
    }

    private function unidad(string $codigo): Unidad
    {
        $unidad = Unidad::query()->where('codigo', $codigo)->first();

        if (! $unidad instanceof Unidad) {
            throw new RuntimeException(
                "Falta la unidad «{$codigo}». Corré UnidadesSeeder antes que este seeder."
            );
        }

        return $unidad;
    }
}
