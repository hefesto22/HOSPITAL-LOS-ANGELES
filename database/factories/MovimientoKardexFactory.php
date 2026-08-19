<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoMovimiento;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\MovimientoKardex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Igual que la de existencias: sirve para preparar un escenario, no
 * para mover inventario. Un movimiento creado así no actualiza ningún
 * saldo, y el saldo queda divergido de su propio kardex — que es
 * exactamente lo que el sistema existe para impedir.
 *
 * @extends Factory<MovimientoKardex>
 */
class MovimientoKardexFactory extends Factory
{
    protected $model = MovimientoKardex::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id'       => Item::factory(),
            'lote_id'       => null,
            'almacen_id'    => Almacen::factory(),
            'tipo'          => TipoMovimiento::EntradaPorCompra,
            'cantidad'      => '100.0000',
            'saldo_despues' => '100.0000',
            'motivo'        => null,
            'referencia'    => null,
            'ocurrido_en'   => now(),
        ];
    }
}
