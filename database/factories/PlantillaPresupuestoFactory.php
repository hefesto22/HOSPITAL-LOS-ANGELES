<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlantillaPresupuesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantillaPresupuesto>
 */
class PlantillaPresupuestoFactory extends Factory
{
    protected $model = PlantillaPresupuesto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'           => 'PLT-'.$this->faker->unique()->numerify('###'),
            'nombre'           => 'APENDICECTOMIA',
            'dias_vigencia'    => 15,
            'holgura_fraccion' => '0.0000',
            'vigencia_desde'   => now()->subMonth()->toDateString(),
        ];
    }

    public function conHolgura(string $fraccion): self
    {
        return $this->state(fn (): array => ['holgura_fraccion' => $fraccion]);
    }

    /**
     * Una plantilla retirada. No se borra: se cierra con vigencia, y
     * sigue explicando los presupuestos que la usaron.
     */
    public function retirada(): self
    {
        return $this->state(fn (): array => [
            'vigencia_hasta' => now()->subDay()->toDateString(),
        ]);
    }
}
