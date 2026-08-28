<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\FormaDePago;
use App\Models\Abono;
use App\Models\AbonoMedio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbonoMedio>
 */
class AbonoMedioFactory extends Factory
{
    protected $model = AbonoMedio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'abono_id' => Abono::factory(),
            'forma'    => FormaDePago::Efectivo->value,
            'monto'    => '1000.00',
        ];
    }

    public function tarjeta(string $monto = '1000.00'): static
    {
        return $this->state(fn (): array => [
            'forma' => FormaDePago::Tarjeta->value,
            'monto' => $monto,
        ]);
    }

    public function transferencia(string $monto = '1000.00'): static
    {
        return $this->state(fn (): array => [
            'forma' => FormaDePago::Transferencia->value,
            'monto' => $monto,
            'banco' => 'Banpaís',
        ]);
    }
}
