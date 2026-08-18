<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sede>
 */
class SedeFactory extends Factory
{
    protected $model = Sede::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'                 => mb_strtoupper($this->faker->unique()->bothify('S##')),
            'nombre'                 => 'Sede '.$this->faker->city(),
            'razon_social'           => $this->faker->company(),
            'rtn'                    => $this->faker->numerify('##############'),
            'codigo_establecimiento' => $this->faker->unique()->numerify('###'),
            'registro_sesal'         => $this->faker->bothify('SESAL-####'),
            'direccion'              => $this->faker->address(),
            'telefono'               => $this->faker->numerify('+504 ####-####'),
            'email'                  => $this->faker->companyEmail(),
            'vigencia_desde'         => now()->subYear()->toDateString(),
            'vigencia_hasta'         => null,
        ];
    }

    /**
     * Sede que ya cerró — para probar que lo histórico sigue siendo
     * consultable y que no aparece en los selectores de hoy.
     */
    public function cerrada(): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => now()->subYears(5)->toDateString(),
            'vigencia_hasta' => now()->subMonth()->toDateString(),
        ]);
    }
}
