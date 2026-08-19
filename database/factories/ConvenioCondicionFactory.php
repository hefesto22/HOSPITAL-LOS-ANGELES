<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConvenioCondicion>
 */
class ConvenioCondicionFactory extends Factory
{
    protected $model = ConvenioCondicion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'convenio_id'        => Convenio::factory(),
            'factor_sobre_lista' => '0.8500',
            'motivo'             => 'Condición pactada para esta prueba: lista menos 15 %.',
            'vigencia_desde'     => '2026-01-01',
            'vigencia_hasta'     => null,
        ];
    }

    public function delConvenio(Convenio $convenio): self
    {
        return $this->state(fn (): array => ['convenio_id' => $convenio->id]);
    }

    /**
     * @param numeric-string $factor
     */
    public function conFactor(string $factor): self
    {
        return $this->state(fn (): array => ['factor_sobre_lista' => $factor]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
