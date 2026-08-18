<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Item;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * El default es un SERVICIO, no un medicamento.
     *
     * Un medicamento necesita unidad de dispensación —la base lo exige—
     * y crear una unidad de más en cada test que solo quería "un ítem"
     * ensucia la suite. Quien quiere un medicamento lo pide con
     * `->medicamento()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * `words(3, true)` devuelve `array|string` según un booleano, y
         * eso el analizador no lo puede resolver. Se pide el array y se
         * une acá, que además deja explícito el separador.
         */
        /** @var array<int, string> $palabras */
        $palabras = $this->faker->words(3);

        return [
            'codigo'                    => mb_strtoupper($this->faker->unique()->bothify('SRV-####')),
            'nombre'                    => mb_strtoupper(implode(' ', $palabras)),
            'descripcion'               => null,
            'tipo'                      => TipoItem::Servicio,
            'regimen_isv'               => RegimenIsv::Exento,
            'politica_cargo'            => PoliticaCargo::Cobrable,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario,
            'unidad_dispensacion_id'    => null,
            'fraccionable'              => false,
            'requiere_lote'             => false,
            'requiere_receta'           => false,
            'es_controlado'             => false,
            'vigencia_desde'            => now()->subYear()->toDateString(),
            'vigencia_hasta'            => null,
        ];
    }

    public function medicamento(): self
    {
        return $this->state(fn (): array => [
            'codigo'                    => mb_strtoupper($this->faker->unique()->bothify('MED-####')),
            'tipo'                      => TipoItem::Medicamento,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
            'unidad_dispensacion_id'    => Unidad::factory(),
            'requiere_lote'             => true,
            'principio_activo'          => mb_strtoupper($this->faker->word()),
        ]);
    }

    public function controlado(): self
    {
        return $this->medicamento()->state(fn (): array => [
            'es_controlado'   => true,
            'requiere_receta' => true,
        ]);
    }

    public function fraccionable(): self
    {
        return $this->medicamento()->state(fn (): array => [
            'fraccionable'                  => true,
            'unidad_fraccion_id'            => Unidad::factory()->de(MagnitudDeMedida::Volumen),
            'fracciones_por_unidad'         => '2.0000',
            'horas_caducidad_post_apertura' => 48,
        ]);
    }

    public function de(TipoItem $tipo, CategoriaLegalDeDescuento $categoria): self
    {
        return $this->state(fn (): array => [
            'tipo'                      => $tipo,
            'categoria_legal_descuento' => $categoria,
            'unidad_dispensacion_id'    => $tipo->mueveInventario() ? Unidad::factory() : null,
        ]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
