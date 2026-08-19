<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Models\Convenio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Convenio>
 */
class ConvenioFactory extends Factory
{
    protected $model = Convenio::class;

    /**
     * Por defecto una aseguradora privada, que es el caso con más
     * condiciones activas —autorización previa, crédito, tarifario— y
     * por lo tanto el que más cosas rompe si algo está mal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'               => mb_strtoupper($this->faker->unique()->bothify('SEG####')),
            'nombre'               => mb_strtoupper($this->faker->company()),
            'tipo'                 => TipoConvenio::AseguradoraPrivada,
            'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
            'fundamento_descuento' => 'Fundamento de prueba: el descuento cae sobre el deducible '
                .'que desembolsa el paciente.',
            'rtn'                   => null,
            'contacto'              => null,
            'telefono'              => null,
            'correo'                => null,
            'requiere_autorizacion' => true,
            'dias_credito'          => 30,
            'notas'                 => null,
            'vigencia_desde'        => '2026-01-01',
            'vigencia_hasta'        => null,
        ];
    }

    public function contado(): self
    {
        return $this->state(fn (): array => [
            'codigo'               => Convenio::CODIGO_CONTADO,
            'nombre'               => 'PACIENTE PARTICULAR',
            'tipo'                 => TipoConvenio::Contado,
            'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
            'fundamento_descuento' => 'El paciente paga de su bolsillo: lo que paga es el total '
                .'facturado y no hay tercero que discutir.',
            'requiere_autorizacion' => false,
            'dias_credito'          => null,
        ]);
    }

    public function de(TipoConvenio $tipo): self
    {
        return $this->state(fn (): array => [
            'tipo'         => $tipo,
            'dias_credito' => $tipo->admiteCredito() ? 30 : null,
        ]);
    }

    public function conBase(BaseDelDescuentoLegal $base): self
    {
        return $this->state(fn (): array => ['base_descuento_legal' => $base]);
    }

    public function vigenteEntre(string $desde, ?string $hasta = null): self
    {
        return $this->state(fn (): array => [
            'vigencia_desde' => $desde,
            'vigencia_hasta' => $hasta,
        ]);
    }
}
