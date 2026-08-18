<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\MagnitudDeMedida;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidad>
 */
class UnidadFactory extends Factory
{
    protected $model = Unidad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'           => mb_strtoupper($this->faker->unique()->bothify('U###')),
            'nombre'           => $this->faker->words(2, true),
            'simbolo'          => null,
            'magnitud'         => MagnitudDeMedida::Conteo,
            'permite_fraccion' => false,
        ];
    }

    public function de(MagnitudDeMedida $magnitud): self
    {
        return $this->state(fn (): array => [
            'magnitud'         => $magnitud,
            'permite_fraccion' => $magnitud->admiteFraccionPorNaturaleza(),
        ]);
    }

    public function fraccionable(): self
    {
        return $this->state(fn (): array => ['permite_fraccion' => true]);
    }
}
