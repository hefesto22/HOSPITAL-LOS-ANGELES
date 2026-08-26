<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoPresupuesto;
use App\Models\Convenio;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Presupuesto;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ⚠️ Solo para armar escenarios. El presupuesto real lo arma
 * `CotizadorDePresupuesto`, que resuelve los precios con el tarifario.
 *
 * ⚠️ El orden de las claves importa: `expandAttributes()` resuelve los
 * closures en orden de aparición y a cada uno le pasa solo las claves ya
 * resueltas. `persona_id` depende de `expediente_id`, así que va DESPUÉS
 * — al revés, el closure recibe la instancia de la factory en vez de un
 * id y PDO revienta bindeando un objeto.
 *
 * @extends Factory<Presupuesto>
 */
class PresupuestoFactory extends Factory
{
    protected $model = Presupuesto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id'       => Sede::factory(),
            'expediente_id' => Expediente::factory(),
            'persona_id'    => function (array $atributos): mixed {
                $expediente = Expediente::query()->find($atributos['expediente_id']);

                return $expediente->persona_id ?? Persona::factory();
            },
            'numero'      => 'PRE-'.$this->faker->unique()->numerify('######'),
            'convenio_id' => Convenio::factory()->contado(),
            'titulo'      => 'APENDICECTOMIA',
            'estado'      => EstadoPresupuesto::Borrador,
            'created_by'  => User::factory(),
        ];
    }

    /**
     * Amarra el presupuesto a un ingreso ya abierto, copiando sede,
     * expediente y persona del encuentro para que el escenario sea
     * coherente y no tres pacientes distintos.
     */
    public function delEncuentro(Encuentro $encuentro): self
    {
        return $this->state(fn (): array => [
            'encuentro_id'  => $encuentro->id,
            'sede_id'       => $encuentro->sede_id,
            'expediente_id' => $encuentro->expediente_id,
            'persona_id'    => $encuentro->persona_id,
        ]);
    }

    public function conPagador(Convenio $convenio): self
    {
        return $this->state(fn (): array => ['convenio_id' => $convenio->id]);
    }

    /**
     * Agregado a la cuenta y midiendo. Los totales se arman todos
     * exentos porque es
     * lo normal en un hospital (Art. 15 de la Ley del ISV) y porque así
     * cuadran solos contra el CHECK `presupuestos_totales_cuadran`.
     */
    public function agregado(string $total = '40000.00'): self
    {
        return $this->state(fn (): array => [
            'estado'       => EstadoPresupuesto::Agregado,
            'emitido_en'   => now(),
            'vence_el'     => now()->addDays(15)->toDateString(),
            'total_bruto'  => $total,
            'total_exento' => $total,
            'total'        => $total,
            'lineas'       => 1,
        ]);
    }

    public function vencido(string $total = '40000.00'): self
    {
        return $this->agregado($total)->state(fn (): array => [
            'vence_el' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * ⚠️ Parte de `emitido()` a propósito: el CHECK
     * `presupuestos_emision_completa` exige `emitido_en` y `vence_el`
     * en todo estado que no sea borrador o anulado — y un sustituido
     * que nunca se emitió no existe en la vida real tampoco.
     */
    public function sustituidoPor(Presupuesto $nuevo): self
    {
        return $this->agregado()->state(fn (): array => [
            'estado'          => EstadoPresupuesto::Sustituido,
            'motivo_revision' => 'La cirugía se complicó y se recotizó.',
        ])->afterCreating(function (Presupuesto $viejo) use ($nuevo): void {
            $nuevo->update(['presupuesto_anterior_id' => $viejo->id]);
        });
    }
}
