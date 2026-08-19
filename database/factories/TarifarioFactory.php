<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Convenio;
use App\Models\Item;
use App\Models\Sede;
use App\Models\Tarifario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tarifario>
 */
class TarifarioFactory extends Factory
{
    protected $model = Tarifario::class;

    /**
     * Por defecto un precio de LISTA —sin convenio y sin sede—, que es la
     * fila que siempre tiene que existir para que el resolutor nunca se
     * quede sin respuesta.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id'        => Item::factory(),
            'convenio_id'    => null,
            'sede_id'        => null,
            'precio'         => '29.3300',
            'motivo'         => 'Precio fijado para esta prueba.',
            'vigencia_desde' => '2026-01-01',
            'vigencia_hasta' => null,
        ];
    }

    public function delItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }

    public function paraElConvenio(Convenio $convenio): self
    {
        return $this->state(fn (): array => ['convenio_id' => $convenio->id]);
    }

    public function enLaSede(Sede $sede): self
    {
        return $this->state(fn (): array => ['sede_id' => $sede->id]);
    }

    /**
     * @param numeric-string $precio
     */
    public function a(string $precio): self
    {
        return $this->state(fn (): array => ['precio' => $precio]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
