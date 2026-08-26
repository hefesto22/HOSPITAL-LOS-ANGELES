<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\PlantillaLinea;
use App\Models\PlantillaPresupuesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantillaLinea>
 */
class PlantillaLineaFactory extends Factory
{
    protected $model = PlantillaLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plantilla_id' => PlantillaPresupuesto::factory(),
            'item_id'      => Item::factory(),
            'cantidad'     => '1.0000',
            'orden'        => 0,
            'opcional'     => false,
        ];
    }

    public function delItem(Item $item, string $cantidad = '1.0000'): self
    {
        return $this->state(fn (): array => [
            'item_id'  => $item->id,
            'cantidad' => $cantidad,
        ]);
    }

    public function opcional(): self
    {
        return $this->state(fn (): array => ['opcional' => true]);
    }
}
