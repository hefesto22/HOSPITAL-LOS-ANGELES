<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Models\DescuentoLegal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DescuentoLegal>
 */
class DescuentoLegalFactory extends Factory
{
    protected $model = DescuentoLegal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categoria_legal' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
            'rango_edad'      => RangoEdad::Tercera,
            'porcentaje'      => '0.2500',
            'fundamento'      => 'Art. 30, numeral 6, Decreto Legislativo 199-2006',
            'exige_receta'    => false,
            'vigencia_desde'  => '2007-07-21',
            'vigencia_hasta'  => null,
        ];
    }

    public function de(CategoriaLegalDeDescuento $categoria, RangoEdad $rango): self
    {
        return $this->state(fn (): array => [
            'categoria_legal' => $categoria,
            'rango_edad'      => $rango,
        ]);
    }

    /**
     * @param numeric-string $fraccion
     */
    public function del(string $fraccion): self
    {
        return $this->state(fn (): array => ['porcentaje' => $fraccion]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }

    public function conReceta(): self
    {
        return $this->state(fn (): array => ['exige_receta' => true]);
    }
}
