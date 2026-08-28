<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoAbono;
use App\Models\Abono;
use App\Models\Cuenta;
use App\Models\TurnoDeCaja;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. El abono real lo recibe
 * `ReceptorDeAbono`, que exige turno abierto y escribe los medios de
 * pago en la misma transacción.
 *
 * ⚠️ El orden de las claves importa: `sede_id` se resuelve desde la
 * cuenta y por eso va después de `cuenta_id`.
 *
 * @extends Factory<Abono>
 */
class AbonoFactory extends Factory
{
    protected $model = Abono::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cuenta_id' => Cuenta::factory(),
            'sede_id'   => function (array $atributos): mixed {
                $cuenta = Cuenta::query()->find($atributos['cuenta_id']);

                return $cuenta->sede_id ?? null;
            },
            'turno_id' => TurnoDeCaja::factory(),
            'numero'   => 'REC-TEST-'.$this->faker->unique()->numberBetween(1, 999999),
            'estado'   => EstadoAbono::Aplicado->value,
            'total'    => '1000.00',

            'recibido_en'     => now(),
            'fecha_operacion' => now()->toDateString(),
            'recibido_por'    => User::factory(),
        ];
    }
}
