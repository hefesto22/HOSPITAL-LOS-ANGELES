<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\RangoCai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. La factura real la emite
 * `EmisorDeFactura`, que toma el correlativo con candado y cierra la
 * cuenta en el mismo acto.
 *
 * ⚠️ El orden de las claves importa: `sede_id` y los datos del rango se
 * resuelven desde `rango_cai_id`, así que van después.
 *
 * @extends Factory<Factura>
 */
class FacturaFactory extends Factory
{
    protected $model = Factura::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rango_cai_id' => RangoCai::factory(),
            'cuenta_id'    => Cuenta::factory(),

            'sede_id' => function (array $atributos): mixed {
                $rango = RangoCai::query()->find($atributos['rango_cai_id']);

                return $rango->sede_id ?? null;
            },

            'tipo'   => TipoDocumentoDeVenta::Factura->value,
            'estado' => EstadoFactura::Emitida->value,

            'correlativo' => 1,
            'numero'      => '000-001-01-00000001',

            'cai' => function (array $atributos): mixed {
                $rango = RangoCai::query()->find($atributos['rango_cai_id']);

                return $rango->cai ?? null;
            },
            'fecha_limite_emision' => now()->addYear()->toDateString(),

            'cliente_nombre' => 'CONSUMIDOR FINAL',

            'emitida_en'      => now(),
            'fecha_operacion' => now()->toDateString(),

            'bruto'   => '1000.00',
            'exento'  => '1000.00',
            'gravado' => '0.00',
            'isv'     => '0.00',
            'total'   => '1000.00',
            'lineas'  => 1,
        ];
    }
}
