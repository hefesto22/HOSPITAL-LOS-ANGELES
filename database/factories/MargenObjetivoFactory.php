<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoItem;
use App\Models\MargenObjetivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MargenObjetivo>
 */
class MargenObjetivoFactory extends Factory
{
    protected $model = MargenObjetivo::class;

    /**
     * Por defecto crea el DEFAULT de la instalación —`tipo_item` nulo—
     * porque es el que siempre tiene que existir para que el resolutor
     * nunca se quede sin respuesta.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_item'      => null,
            'porcentaje'     => '1.2000',
            'motivo'         => 'Default de la instalación para esta prueba',
            'vigencia_desde' => '2026-01-01',
            'vigencia_hasta' => null,
        ];
    }

    public function para(TipoItem $tipo): self
    {
        return $this->state(fn (): array => ['tipo_item' => $tipo]);
    }

    /**
     * @param numeric-string $fraccion
     */
    public function del(string $fraccion): self
    {
        return $this->state(fn (): array => ['porcentaje' => $fraccion]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
