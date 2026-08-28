<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\RegimenIsv;
use App\Models\Factura;
use App\Models\FacturaLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturaLinea>
 */
class FacturaLineaFactory extends Factory
{
    protected $model = FacturaLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'factura_id'      => Factura::factory(),
            'orden'           => 1,
            'descripcion'     => 'CONSULTA GENERAL',
            'cantidad'        => '1.0000',
            'precio_unitario' => '1000.0000',
            'bruto'           => '1000.00',
            'regimen_isv'     => RegimenIsv::Exento->value,
            'tasa_isv'        => '0.0000',
            'exento'          => '1000.00',
            'gravado'         => '0.00',
            'isv'             => '0.00',
            'total'           => '1000.00',
        ];
    }
}
