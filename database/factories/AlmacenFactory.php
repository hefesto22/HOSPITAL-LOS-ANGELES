<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoAlmacen;
use App\Models\Almacen;
use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Almacen>
 */
class AlmacenFactory extends Factory
{
    protected $model = Almacen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'            => Sede::factory(),
            'servicio_id'        => null,
            'codigo'             => mb_strtoupper($this->faker->unique()->bothify('ALM###')),
            'nombre'             => $this->faker->words(2, true),
            'tipo'               => TipoAlmacen::BodegaCentral,
            'maneja_controlados' => false,
            'vigencia_desde'     => now()->subYear()->toDateString(),
            'vigencia_hasta'     => null,
        ];
    }

    /**
     * El almacén único del hospital, que es como nace en producción
     * mientras `sihla.inventario.modo_almacen_unico` esté encendido.
     */
    public function unico(): self
    {
        return $this->state(fn (): array => [
            'tipo'               => TipoAlmacen::AlmacenUnico,
            'servicio_id'        => null,
            'maneja_controlados' => true,
        ]);
    }

    public function de(TipoAlmacen $tipo): self
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }

    public function deControlados(): self
    {
        return $this->state(fn (): array => ['maneja_controlados' => true]);
    }
}
