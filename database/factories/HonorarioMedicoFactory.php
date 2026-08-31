<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HonorarioMedico>
 */
class HonorarioMedicoFactory extends Factory
{
    protected $model = HonorarioMedico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medico_id'      => Medico::factory(),
            'item_id'        => Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral),
            'precio'         => '500.0000',
            'vigencia_desde' => now()->subMonth()->toDateString(),
            'vigencia_hasta' => null,
        ];
    }

    public function cerrado(): self
    {
        return $this->state(fn (): array => [
            'vigencia_hasta' => now()->subDay()->toDateString(),
        ]);
    }
}
