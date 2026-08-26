<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Models\Almacen;
use App\Models\Conteo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios de prueba.
 *
 * Un conteo REAL se abre con `AbridorDeConteo`, que además carga las
 * líneas del almacén y verifica que no haya otro abierto. Uno fabricado
 * acá nace vacío: sirve para probar candados, no para simular un conteo.
 *
 * @extends Factory<Conteo>
 */
class ConteoFactory extends Factory
{
    protected $model = Conteo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'almacen_id'  => Almacen::factory(),
            'estado'      => EstadoConteo::Abierto,
            'alcance'     => AlcanceDeConteo::Parcial,
            'descripcion' => 'Conteo cíclico de prueba',

            /*
             * ⚠️ Tolerancia AMPLIA a propósito, y distinta del default de
             * producción (`sihla.inventario.tolerancia_recuento_por_defecto`,
             * que es cero).
             *
             * Con tolerancia cero, cualquier diferencia exige un segundo
             * conteo antes de poder cerrar — que es lo correcto en el
             * hospital y lo que hace el `AbridorDeConteo`. Pero en un test
             * que quiere probar OTRA cosa —que el cierre asienta la
             * diferencia, que los controlados no se ajustan— eso hace
             * fallar por una razón que no es la que se está probando.
             *
             * Los tests que sí prueban el recuento fijan la tolerancia
             * explícitamente con `conTolerancia()`, y así se lee en el
             * test cuál es la regla bajo prueba.
             */
            'tolerancia_recuento' => '999999',

            'abierto_en' => now(),

            /*
             * Explícito y no por el trait de auditoría: los tests corren
             * sin usuario autenticado en muchos casos, y el CHECK de
             * cuatro ojos compara contra esta columna.
             */
            'created_by' => User::factory(),
        ];
    }

    public function enElAlmacen(Almacen $almacen): self
    {
        return $this->state(fn (): array => ['almacen_id' => $almacen->id]);
    }

    public function total(): self
    {
        return $this->state(fn (): array => ['alcance' => AlcanceDeConteo::Total]);
    }

    public function abiertoPor(User $usuario): self
    {
        return $this->state(fn (): array => ['created_by' => $usuario->id]);
    }

    /**
     * @param numeric-string $unidades
     */
    public function conTolerancia(string $unidades): self
    {
        return $this->state(fn (): array => ['tolerancia_recuento' => $unidades]);
    }
}
