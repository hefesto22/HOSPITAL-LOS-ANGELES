<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Producto;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lo que se guarda en el estante.
 *
 * No hereda de `ItemFactory` a propósito: los genéricos de una factory
 * ya parametrizada (`Factory<Item>`) no se reabren para el hijo, y el
 * default de `ItemFactory` es un SERVICIO —sin unidad de dispensación—
 * que el CHECK `items_unidad_obligatoria_si_se_almacena` rechazaría de
 * entrada. Acá el default ya es algo que se puede guardar.
 *
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * `words(3, true)` devuelve `array|string` según un booleano, y
         * eso el analizador no lo puede resolver. Se pide el array y se
         * une acá, igual que en `ItemFactory`.
         */
        /** @var array<int, string> $palabras */
        $palabras = $this->faker->words(3);

        return [
            'codigo'                    => mb_strtoupper($this->faker->unique()->bothify('MED-####')),
            'nombre'                    => mb_strtoupper(implode(' ', $palabras)),
            'descripcion'               => null,
            'tipo'                      => TipoItem::Medicamento,
            'se_almacena'               => true,
            'regimen_isv'               => RegimenIsv::Exento,
            'politica_cargo'            => PoliticaCargo::Cobrable,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
            'unidad_dispensacion_id'    => Unidad::factory(),
            'fraccionable'              => false,
            'requiere_lote'             => true,
            'requiere_receta'           => false,
            'es_controlado'             => false,
            'principio_activo'          => mb_strtoupper($this->faker->word()),
            'vigencia_desde'            => now()->subYear()->toDateString(),
            'vigencia_hasta'            => null,
        ];
    }

    public function insumo(): self
    {
        return $this->state(fn (): array => [
            'codigo'           => mb_strtoupper($this->faker->unique()->bothify('INS-####')),
            'tipo'             => TipoItem::Insumo,
            'requiere_lote'    => false,
            'principio_activo' => null,
        ]);
    }

    public function controlado(): self
    {
        return $this->state(fn (): array => [
            'es_controlado'   => true,
            'requiere_receta' => true,
        ]);
    }
}
