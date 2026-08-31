<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Models\Convenio;
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
            'convenio_id'    => null,
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

    /**
     * El precio que ese médico le cobra a ESE pagador.
     */
    public function paraElPagador(Convenio $convenio): self
    {
        return $this->state(fn (): array => [
            'convenio_id' => $convenio->getKey(),
        ]);
    }
}
