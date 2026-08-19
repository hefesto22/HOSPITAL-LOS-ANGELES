<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\Recepcion;
use App\Models\RecepcionLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios de prueba.
 *
 * `cantidad_dispensacion` NO se pone acá y no se puede: la genera
 * PostgreSQL. El default es el caso de Mauricio: 100 cajas de 100
 * tabletas a L 1.000 la caja, o sea L 10,00 la tableta.
 *
 * @extends Factory<RecepcionLinea>
 */
class RecepcionLineaFactory extends Factory
{
    protected $model = RecepcionLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recepcion_id'              => Recepcion::factory(),
            'item_id'                   => Item::factory()->medicamento(),
            'item_presentacion_id'      => null,
            'lote_id'                   => null,
            'cantidad_presentacion'     => '100',
            'unidades_por_presentacion' => '100',
            'costo_por_presentacion'    => '1000',
            'costo_unitario'            => '10.000000',
            'numero_lote'               => mb_strtoupper($this->faker->unique()->bothify('L-####')),
            'fecha_vencimiento'         => '2027-06-30',
        ];
    }

    public function deLaRecepcion(Recepcion $recepcion): self
    {
        return $this->state(fn (): array => ['recepcion_id' => $recepcion->id]);
    }

    public function delItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }

    /**
     * @param numeric-string $cajas
     * @param numeric-string $porCaja
     * @param numeric-string $costoPorCaja
     */
    public function de(string $cajas, string $porCaja, string $costoPorCaja): self
    {
        return $this->state(fn (): array => [
            'cantidad_presentacion'     => $cajas,
            'unidades_por_presentacion' => $porCaja,
            'costo_por_presentacion'    => $costoPorCaja,
            'costo_unitario'            => bcdiv($costoPorCaja, $porCaja, 6),
        ]);
    }
}
