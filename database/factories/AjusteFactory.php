<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoDeAjuste;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios de prueba.
 *
 * Un ajuste REAL se asienta con `RegistradorDeAjuste`, que mueve el
 * kardex, sincroniza la cantidad base del costo y congela el valor. Uno
 * fabricado acá es un documento sin movimientos detrás: sirve para probar
 * candados y policies, no para simular inventario.
 *
 * @extends Factory<Ajuste>
 */
class AjusteFactory extends Factory
{
    protected $model = Ajuste::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'almacen_id'      => Almacen::factory(),
            'conteo_id'       => null,
            'tipo'            => TipoDeAjuste::Merma,
            'referencia'      => null,
            'fecha_operacion' => now()->toDateString(),
            'ocurrido_en'     => now(),

            /*
             * Diez caracteres como mínimo: lo exige el CHECK de la tabla
             * y también el del kardex. Un motivo corto en la factory
             * haría fallar tests por una razón que no es la que se está
             * probando.
             */
            'motivo'         => 'Se cayó la bandeja en el turno de la noche',
            'valor_absoluto' => '0.00',
            'created_by'     => User::factory(),
        ];
    }

    public function enElAlmacen(Almacen $almacen): self
    {
        return $this->state(fn (): array => ['almacen_id' => $almacen->id]);
    }

    public function delTipo(TipoDeAjuste $tipo): self
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }
}
