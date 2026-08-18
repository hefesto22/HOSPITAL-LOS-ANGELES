<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoServicio;
use App\Models\Sede;
use App\Models\Servicio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Servicio>
 */
class ServicioFactory extends Factory
{
    protected $model = Servicio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'        => Sede::factory(),
            'codigo'         => mb_strtoupper($this->faker->unique()->bothify('SRV###')),
            'nombre'         => $this->faker->words(2, true),
            'tipo'           => $this->faker->randomElement(TipoServicio::cases()),
            'centro_costo'   => $this->faker->numerify('CC###'),
            'vigencia_desde' => now()->subYear()->toDateString(),
            'vigencia_hasta' => null,
        ];
    }

    public function de(TipoServicio $tipo): self
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }
}
