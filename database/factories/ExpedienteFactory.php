<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoExpediente;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expediente>
 */
class ExpedienteFactory extends Factory
{
    protected $model = Expediente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'            => Sede::factory(),
            'persona_id'         => Persona::factory(),
            'numero'             => 'EXP-TST-'.$this->faker->unique()->numerify('########'),
            'abierto_el'         => now()->subYear()->toDateString(),
            'estado'             => EstadoExpediente::Activo,
            'ultima_atencion_el' => now()->subMonth(),
            'ubicacion_fisica'   => null,
        ];
    }

    /**
     * Carpeta que lleva años en el archivo. Sirve para probar que un
     * paciente que vuelve la saca del pasivo.
     */
    public function pasivo(int $aniosSinAtencion = 8): self
    {
        return $this->state(fn (): array => [
            'abierto_el'         => now()->subYears($aniosSinAtencion + 2)->toDateString(),
            'ultima_atencion_el' => now()->subYears($aniosSinAtencion),
            'estado'             => EstadoExpediente::Pasivo,
        ]);
    }

    /**
     * Cumplió el plazo legal de conservación.
     */
    public function depurable(int $anios = 25): self
    {
        return $this->state(fn (): array => [
            'abierto_el'         => now()->subYears($anios + 1)->toDateString(),
            'ultima_atencion_el' => now()->subYears($anios),
            'estado'             => EstadoExpediente::Depurable,
        ]);
    }
}
