<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Proveedor;
use App\Models\Recepcion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios de prueba.
 *
 * Una recepción REAL se crea con `RegistradorDeRecepcion`, que es lo
 * único que mueve el kardex y recalcula el costo promedio. Una fabricada
 * acá NO tiene movimientos detrás: sirve para probar candados, no para
 * simular inventario.
 *
 * @extends Factory<Recepcion>
 */
class RecepcionFactory extends Factory
{
    protected $model = Recepcion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'almacen_id'      => Almacen::factory(),
            'proveedor_id'    => Proveedor::factory(),
            'referencia'      => 'Factura '.$this->faker->unique()->numerify('000-001-01-########'),
            'fecha_recepcion' => '2026-08-17',
        ];
    }

    public function alAlmacen(Almacen $almacen): self
    {
        return $this->state(fn (): array => ['almacen_id' => $almacen->id]);
    }

    public function sinProveedor(): self
    {
        return $this->state(fn (): array => [
            'proveedor_id' => null,
            'referencia'   => 'Donación',
        ]);
    }
}
