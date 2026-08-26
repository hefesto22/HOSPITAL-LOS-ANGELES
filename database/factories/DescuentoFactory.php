<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Models\Descuento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Descuento>
 */
class DescuentoFactory extends Factory
{
    protected $model = Descuento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'         => 'Tercera edad',
            'porcentaje'     => '0.2500',
            'aplica_a'       => AplicacionDeDescuento::Tercera,
            'exige_receta'   => false,
            'nota'           => null,
            'vigencia_desde' => '2007-07-21',
            'vigencia_hasta' => null,
        ];
    }

    public function llamado(string $nombre): self
    {
        return $this->state(fn (): array => ['nombre' => $nombre]);
    }

    /**
     * @param numeric-string $fraccion
     */
    public function del(string $fraccion): self
    {
        return $this->state(fn (): array => ['porcentaje' => $fraccion]);
    }

    public function que(AplicacionDeDescuento $aplicacion): self
    {
        return $this->state(fn (): array => ['aplica_a' => $aplicacion]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }

    public function conReceta(): self
    {
        return $this->state(fn (): array => ['exige_receta' => true]);
    }
}
