<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Enums\RegimenIsv;
use App\Models\Item;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Las líneas solo se pueden escribir mientras el presupuesto está en
 * `borrador`: un trigger de la base rechaza lo demás. Si un test las
 * necesita sobre uno emitido, tiene que crearlas ANTES de emitir.
 *
 * @extends Factory<PresupuestoLinea>
 */
class PresupuestoLineaFactory extends Factory
{
    protected $model = PresupuestoLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'presupuesto_id'  => Presupuesto::factory(),
            'orden'           => 0,
            'item_id'         => Item::factory(),
            'origen'          => OrigenLineaPresupuesto::Catalogo,
            'texto'           => 'SERVICIO DE PRUEBA',
            'cantidad'        => '1.0000',
            'precio_unitario' => '1000.0000',
            'descuento'       => '0.00',
            'regimen_isv'     => RegimenIsv::Exento,
            'tasa_isv'        => '0.0000',
            'bruto'           => '1000.00',
            'subtotal'        => '1000.00',
            'base_exenta'     => '1000.00',
            'base_gravada'    => '0.00',
            'isv'             => '0.00',
            'total'           => '1000.00',
        ];
    }

    /**
     * La holgura NUNCA lleva ítem — un CHECK de la base lo verifica.
     */
    public function holgura(string $monto): self
    {
        return $this->state(fn (): array => [
            'item_id'         => null,
            'origen'          => OrigenLineaPresupuesto::Holgura,
            'texto'           => 'HOLGURA DEL PRESUPUESTO',
            'cantidad'        => '1.0000',
            'precio_unitario' => $monto,
            'bruto'           => $monto,
            'subtotal'        => $monto,
            'base_exenta'     => $monto,
            'total'           => $monto,
        ]);
    }
}
