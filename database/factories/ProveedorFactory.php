<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'   => mb_strtoupper($this->faker->unique()->bothify('PROV-####')),
            'nombre'   => mb_strtoupper($this->faker->company()),
            'rtn'      => null,
            'contacto' => null,
            'telefono' => null,
            'correo'   => null,
            'activo'   => true,
        ];
    }

    /**
     * El RTN se arma con dígitos y no con `numerify`, que puede empezar
     * en cero y sigue siendo válido — pero también puede devolver menos
     * de catorce si la plantilla cambia. Acá es fijo por construcción.
     */
    public function conRtn(?string $rtn = null): self
    {
        return $this->state(fn (): array => [
            'rtn' => $rtn ?? (string) $this->faker->unique()->numberBetween(10000000000000, 99999999999999),
        ]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
