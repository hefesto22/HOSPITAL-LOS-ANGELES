<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\RangoCai;
use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para pruebas. Un rango real se carga a mano desde la
 * resolución que entrega el SAR: el CAI, los tres segmentos del número,
 * el rango y la fecha límite salen de ese papel, no de un generador.
 *
 * @extends Factory<RangoCai>
 */
class RangoCaiFactory extends Factory
{
    protected $model = RangoCai::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id' => Sede::factory(),
            'tipo'    => TipoDocumentoDeVenta::Factura->value,

            /* 32 hexadecimales en seis grupos, como los entrega el SAR. */
            'cai' => mb_strtoupper($this->faker->regexify('[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{2}')),

            'establecimiento' => '000',
            'punto_emision'   => '001',
            'tipo_codigo'     => '01',

            'desde'     => 1,
            'hasta'     => 5000,
            'siguiente' => 1,

            'fecha_limite_emision' => now()->addYear()->toDateString(),
            'activo'               => true,
        ];
    }

    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'fecha_limite_emision' => now()->subDay()->toDateString(),
        ]);
    }

    public function agotado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'siguiente' => (is_int($atributos['hasta'] ?? null) ? $atributos['hasta'] : 5000) + 1,
        ]);
    }
}
