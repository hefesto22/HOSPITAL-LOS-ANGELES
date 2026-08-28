<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. El turno real lo abre
 * `AbridorDeTurnoDeCaja`, que asigna el correlativo y garantiza que
 * haya uno solo abierto por persona.
 *
 * @extends Factory<TurnoDeCaja>
 */
class TurnoDeCajaFactory extends Factory
{
    protected $model = TurnoDeCaja::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'    => Sede::factory(),
            'numero'     => 'CAJ-TEST-'.$this->faker->unique()->numberBetween(1, 999999),
            'nombre'     => 'Turno '.$this->faker->randomElement(['A', 'B', 'C']),
            'usuario_id' => User::factory(),
            'estado'     => EstadoTurnoDeCaja::Abierto->value,

            'fondo_inicial'   => '500.00',
            'abierto_en'      => now(),
            'fecha_operacion' => now()->toDateString(),
        ];
    }

    public function cerrado(string $contado = '500.00'): static
    {
        return $this->state(fn (array $atributos): array => [
            'estado'            => EstadoTurnoDeCaja::Cerrado->value,
            'cerrado_en'        => now(),
            'cerrado_por'       => $atributos['usuario_id'] ?? null,
            'efectivo_esperado' => $contado,
            'efectivo_contado'  => $contado,
            'diferencia'        => '0.00',
        ]);
    }
}
