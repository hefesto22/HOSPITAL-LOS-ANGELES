<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'primer_nombre'    => $this->faker->firstName(),
            'segundo_nombre'   => $this->faker->optional()->firstName(),
            'primer_apellido'  => $this->faker->lastName(),
            'segundo_apellido' => $this->faker->lastName(),
            'apellido_casada'  => null,
            'sexo_biologico'   => $this->faker->randomElement([
                SexoBiologico::Masculino,
                SexoBiologico::Femenino,
            ]),
            'genero'                     => null,
            'fecha_nacimiento'           => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'precision_fecha_nacimiento' => PrecisionFechaNacimiento::Exacta,
            'fecha_defuncion'            => null,
            'es_nn'                      => false,
            'nacionalidad'               => 'HN',
            'departamento'               => 'Francisco Morazán',
            'municipio'                  => 'Distrito Central',
            'direccion'                  => $this->faker->address(),
            'telefono'                   => $this->faker->numerify('+504 ####-####'),
            'email'                      => $this->faker->safeEmail(),
        ];
    }

    /**
     * Nombre exacto — para las pruebas de búsqueda y de normalización,
     * donde el nombre aleatorio del faker no sirve.
     */
    public function llamada(
        string $primerNombre,
        ?string $segundoNombre = null,
        ?string $primerApellido = null,
        ?string $segundoApellido = null,
    ): self {
        return $this->state(fn (): array => [
            'primer_nombre'    => $primerNombre,
            'segundo_nombre'   => $segundoNombre,
            'primer_apellido'  => $primerApellido,
            'segundo_apellido' => $segundoApellido,
        ]);
    }

    /**
     * Nacida hace exactamente $anios años y un día, para que la edad
     * cumplida sea inequívoca sin importar a qué hora corra la prueba.
     */
    public function deEdad(int $anios): self
    {
        return $this->state(fn (): array => [
            'fecha_nacimiento'           => now()->subYears($anios)->subDay()->toDateString(),
            'precision_fecha_nacimiento' => PrecisionFechaNacimiento::Exacta,
        ]);
    }

    /**
     * El NN de emergencia: sin apellido, sin fecha de nacimiento confiable
     * y con el sexo sin evaluar.
     */
    public function nn(): self
    {
        return $this->state(fn (): array => [
            'primer_nombre'              => 'NN',
            'segundo_nombre'             => null,
            'primer_apellido'            => null,
            'segundo_apellido'           => null,
            'es_nn'                      => true,
            'sexo_biologico'             => SexoBiologico::Desconocido,
            'genero'                     => null,
            'fecha_nacimiento'           => null,
            'precision_fecha_nacimiento' => PrecisionFechaNacimiento::Estimada,
            'nota_identificacion'        => 'Ingresó inconsciente sin documentos.',
            'telefono'                   => null,
            'email'                      => null,
        ]);
    }

    /**
     * Edad estimada a ojo: hay fecha, pero no es confiable.
     */
    public function conEdadEstimada(int $aniosAproximados): self
    {
        return $this->state(fn (): array => [
            'fecha_nacimiento'           => now()->subYears($aniosAproximados)->startOfYear()->toDateString(),
            'precision_fecha_nacimiento' => PrecisionFechaNacimiento::Estimada,
        ]);
    }

    public function fallecida(): self
    {
        return $this->state(fn (): array => [
            'fecha_defuncion' => now()->subMonth()->toDateString(),
        ]);
    }

    public function conGenero(Genero $genero): self
    {
        return $this->state(fn (): array => [
            'genero' => $genero,
        ]);
    }
}
