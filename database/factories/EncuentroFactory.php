<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEgreso;
use App\Domain\Enums\TipoEncuentro;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios.
 *
 * Un encuentro REAL se abre con `AbridorDeEncuentro`, que además asigna
 * los dos correlativos bajo candado y crea la cuenta en la misma
 * transacción. Uno fabricado acá nace sin cuenta: sirve para probar
 * candados y permisos, no para simular una admisión.
 *
 * @extends Factory<Encuentro>
 */
class EncuentroFactory extends Factory
{
    protected $model = Encuentro::class;

    /**
     * Ambulatorio por defecto y no hospitalización, a propósito: el
     * índice único `encuentros_una_hospitalizacion_abierta` haría fallar
     * cualquier test que necesite dos encuentros de la misma persona por
     * una razón que no es la que se está probando.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $persona = Persona::factory();

        return [
            'sede_id'       => Sede::factory(),
            'persona_id'    => $persona,
            'expediente_id' => Expediente::factory(),
            'numero'        => 'ENC-'.$this->faker->unique()->numerify('######'),
            'tipo'          => TipoEncuentro::Ambulatorio,
            'estado'        => EstadoEncuentro::Abierto,
            'abierto_en'    => now(),
            'created_by'    => User::factory(),
        ];
    }

    public function de(Persona $persona, Expediente $expediente): self
    {
        return $this->state(fn (): array => [
            'persona_id'    => $persona->id,
            'expediente_id' => $expediente->id,
            'sede_id'       => $expediente->sede_id,
        ]);
    }

    public function enLaSede(Sede $sede): self
    {
        return $this->state(fn (): array => ['sede_id' => $sede->id]);
    }

    public function internado(): self
    {
        return $this->state(fn (): array => ['tipo' => TipoEncuentro::Hospitalizacion]);
    }

    public function deEmergencia(): self
    {
        return $this->state(fn (): array => ['tipo' => TipoEncuentro::Emergencia]);
    }

    public function cerrado(): self
    {
        return $this->state(fn (): array => [
            'estado'                 => EstadoEncuentro::Cerrado,
            'alta_medica_en'         => now(),
            'alta_administrativa_en' => now(),
            'salida_fisica_en'       => now(),
            'tipo_egreso'            => TipoEgreso::Domicilio,
            'cerrado_en'             => now(),
        ]);
    }
}
