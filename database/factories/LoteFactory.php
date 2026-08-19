<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    protected $model = Lote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id'           => Item::factory()->medicamento(),
            'numero'            => mb_strtoupper($this->faker->unique()->bothify('L-####')),
            'fecha_vencimiento' => '2026-12-31',
            'fecha_fabricacion' => null,
            'proveedor'         => null,
        ];
    }

    public function delItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }

    public function queVence(string $fecha): self
    {
        return $this->state(fn (): array => ['fecha_vencimiento' => $fecha]);
    }

    /**
     * Material que se rastrea por lote pero no caduca.
     */
    public function sinVencimiento(): self
    {
        return $this->state(fn (): array => ['fecha_vencimiento' => null]);
    }
}
