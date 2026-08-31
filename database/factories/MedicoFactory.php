<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Especialidad;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medico>
 */
class MedicoFactory extends Factory
{
    protected $model = Medico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'          => mb_strtoupper($this->faker->name()),
            'especialidad_id' => Especialidad::factory(),
            'identidad'       => $this->faker->unique()->numerify('####-####-#####'),
            'colegiacion'     => mb_strtoupper($this->faker->unique()->bothify('CMH####')),
            'telefono'        => null,
            'user_id'         => null,
            'vigencia_desde'  => now()->subYear()->toDateString(),
            'vigencia_hasta'  => null,
        ];
    }

    public function cerrado(): self
    {
        return $this->state(fn (): array => [
            'vigencia_hasta' => now()->subDay()->toDateString(),
        ]);
    }
}
