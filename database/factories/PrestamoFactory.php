<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Prestamo;
use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. El préstamo real lo registra
 * `RegistradorDePrestamo`, que además asienta la entrada al kardex — sin
 * eso el documento existe y la existencia no se movió.
 *
 * @extends Factory<Prestamo>
 */
class PrestamoFactory extends Factory
{
    protected $model = Prestamo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'          => Sede::factory(),
            'item_id'          => Item::factory(),
            'almacen_id'       => Almacen::factory(),
            'cantidad'         => '20.0000',
            'cantidad_saldada' => '0.0000',
            'presta_tipo'      => QuienPresta::Farmacia,
            'presta_nombre'    => 'FARMACIA SAN JOSE',
            'forma_de_saldo'   => FormaDeSaldo::DevolverProducto,
            'monto_acordado'   => null,
            'estado'           => EstadoPrestamo::Pendiente,
            'ocurrido_en'      => now(),
            'registrado_en'    => now(),
            'fecha_operacion'  => now()->toDateString(),
        ];
    }

    /** Lo que trajo la familia: se registra, pero no se debe. */
    public function delPaciente(): static
    {
        return $this->state(fn (): array => [
            'presta_tipo'   => QuienPresta::MedicoOFamiliar,
            'presta_nombre' => 'FAMILIAR DEL PACIENTE',
        ]);
    }

    /** @param numeric-string $monto */
    public function aPagar(string $monto = '450.0000'): static
    {
        return $this->state(fn (): array => [
            'forma_de_saldo' => FormaDeSaldo::Pagar,
            'monto_acordado' => $monto,
        ]);
    }

    public function saldado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'cantidad_saldada' => $atributos['cantidad'] ?? '20.0000',
            'estado'           => EstadoPrestamo::Saldado,
            'saldado_en'       => now(),
        ]);
    }
}
