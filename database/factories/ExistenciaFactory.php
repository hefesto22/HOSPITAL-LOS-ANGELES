<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar el escenario de una prueba. En la aplicación real
 * las existencias NUNCA se escriben directo: se mueven con
 * `RegistradorDeMovimiento`, que asienta el kardex en la misma
 * transacción. Una existencia creada así no tiene historia detrás.
 *
 * @extends Factory<Existencia>
 */
class ExistenciaFactory extends Factory
{
    protected $model = Existencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id'    => Item::factory(),
            'lote_id'    => null,
            'almacen_id' => Almacen::factory(),
            'cantidad'   => '0',
        ];
    }

    public function de(Item $item, ?Lote $lote, Almacen $almacen): self
    {
        return $this->state(fn (): array => [
            'item_id'    => $item->id,
            'lote_id'    => $lote?->id,
            'almacen_id' => $almacen->id,
        ]);
    }

    /**
     * @param numeric-string $cantidad
     */
    public function con(string $cantidad): self
    {
        return $this->state(fn (): array => ['cantidad' => $cantidad]);
    }
}
