<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\AmbitoCatalogo;
use App\Models\CategoriaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaItem>
 */
class CategoriaItemFactory extends Factory
{
    protected $model = CategoriaItem::class;

    /**
     * El default es una categoría de SERVICIOS, igual que `ItemFactory`
     * crea un servicio: es el lado del catálogo que no necesita unidad
     * de dispensación ni lote para existir.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array<int, string> $palabras */
        $palabras = $this->faker->words(2);

        return [
            'codigo'         => mb_strtoupper($this->faker->unique()->bothify('CAT###')),
            'nombre'         => mb_strtoupper(implode(' ', $palabras)),
            'ambito'         => AmbitoCatalogo::Servicios,
            'descripcion'    => null,
            'orden'          => 100,
            'vigencia_desde' => now()->subYear()->toDateString(),
            'vigencia_hasta' => null,
        ];
    }

    public function deProductos(): self
    {
        return $this->state(fn (): array => ['ambito' => AmbitoCatalogo::Productos]);
    }

    public function delAmbito(AmbitoCatalogo $ambito): self
    {
        return $this->state(fn (): array => ['ambito' => $ambito]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
