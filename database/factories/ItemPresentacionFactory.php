<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemPresentacion>
 */
class ItemPresentacionFactory extends Factory
{
    protected $model = ItemPresentacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id'                   => Item::factory()->medicamento(),
            'unidad_id'                 => Unidad::factory(),
            'nombre'                    => 'CAJA X 100',
            'unidades_por_presentacion' => '100.0000',
            'codigo_barras'             => null,
            'es_predeterminada'         => false,
            'vigencia_desde'            => now()->subYear()->toDateString(),
            'vigencia_hasta'            => null,
        ];
    }

    public function predeterminada(): self
    {
        return $this->state(fn (): array => ['es_predeterminada' => true]);
    }

    public function conContenido(string $unidades, string $nombre): self
    {
        return $this->state(fn (): array => [
            'unidades_por_presentacion' => $unidades,
            'nombre'                    => $nombre,
        ]);
    }
}
