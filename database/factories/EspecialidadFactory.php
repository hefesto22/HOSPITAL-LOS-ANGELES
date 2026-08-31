<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Especialidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Especialidad>
 */
class EspecialidadFactory extends Factory
{
    protected $model = Especialidad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array<int, string> $palabras */
        $palabras = $this->faker->words(2);
        $nombre = mb_strtoupper(implode(' ', $palabras));

        return [
            'nombre'         => $nombre,
            'codigo'         => mb_strtoupper($this->faker->unique()->bothify('ESP###')),
            'vigencia_desde' => now()->subYear()->toDateString(),
            'vigencia_hasta' => null,
        ];
    }

    public function cerrada(): self
    {
        return $this->state(fn (): array => [
            'vigencia_hasta' => now()->subDay()->toDateString(),
        ]);
    }
}
