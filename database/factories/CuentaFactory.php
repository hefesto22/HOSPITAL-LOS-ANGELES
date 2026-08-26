<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoCuenta;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. La cuenta real la abre
 * `AbridorDeEncuentro` junto con su encuentro.
 *
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
{
    protected $model = Cuenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'      => Sede::factory(),
            'encuentro_id' => Encuentro::factory(),
            'numero'       => 'CTA-'.$this->faker->unique()->numerify('######'),
            'convenio_id'  => Convenio::factory()->contado(),
            'estado'       => EstadoCuenta::Abierta,
            'abierta_en'   => now(),
            'created_by'   => User::factory(),
        ];
    }

    public function delEncuentro(Encuentro $encuentro): self
    {
        return $this->state(fn (): array => [
            'encuentro_id' => $encuentro->id,
            'sede_id'      => $encuentro->sede_id,
        ]);
    }

    public function conPagador(Convenio $convenio): self
    {
        return $this->state(fn (): array => ['convenio_id' => $convenio->id]);
    }

    public function congelada(): self
    {
        return $this->state(fn (): array => [
            'estado'       => EstadoCuenta::Congelada,
            'congelada_en' => now(),
        ]);
    }

    public function cerrada(): self
    {
        return $this->state(fn (): array => [
            'estado'     => EstadoCuenta::Cerrada,
            'cerrada_en' => now(),
        ]);
    }
}
