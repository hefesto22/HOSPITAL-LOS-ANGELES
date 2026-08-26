<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;

/**
 * El snapshot completo de una línea, antes de escribirla (§8.5-5).
 *
 * Todo lo que hizo falta para llegar al número está acá: el precio y de
 * dónde salió, el descuento y bajo qué criterio, el régimen de ISV, la
 * cobertura y el costo. Cuando en 2029 una aseguradora objete un
 * reclamo, la respuesta se lee de una fila y no se reconstruye.
 *
 * Es `readonly` y se arma completo o no se arma: una calculadora que
 * devolviera un objeto a medio llenar dejaría que la mitad de un cálculo
 * llegue a la base.
 *
 * Los montos ya vienen redondeados a dos decimales y **cuadran entre
 * sí**: `total = base_exenta + base_gravada + isv` y
 * `porcion_paciente + porcion_aseguradora = total`. No es una promesa
 * del código: los dos CHECK de la tabla lo verifican en cada escritura.
 */
final readonly class CargoCalculado
{
    public function __construct(
        public Monto $precioUnitario,
        public Decimal $cantidad,
        public OrigenDelPrecio $origen,
        public ?int $tarifarioId,
        public ?int $condicionId,
        public ?Decimal $factorConvenio,
        public ?RangoEdad $categoriaLegal,
        public Decimal $descuentoLegalFraccion,
        public BaseDelDescuentoLegal $baseDescuentoLegal,
        public Monto $descuentoLegal,
        public Monto $descuentoComercial,
        public RegimenIsv $regimen,
        public Decimal $tasaIsv,
        public Monto $bruto,
        public Monto $subtotal,
        public Monto $baseExenta,
        public Monto $baseGravada,
        public Monto $isv,
        public Monto $total,
        public CoberturaAplicada $cobertura,
        public Monto $porcionPaciente,
        public Monto $porcionAseguradora,
        public PoliticaCargo $politica,
        public string $explicacionDelPrecio,
    ) {}

    public function hayDescuento(): bool
    {
        return ! $this->descuentoLegal->esCero() || ! $this->descuentoComercial->esCero();
    }

    public function descuentoTotal(): Monto
    {
        return $this->descuentoLegal->sumar($this->descuentoComercial);
    }

    /**
     * La frase que la pantalla le muestra a quien pregunta por qué se
     * cobra eso. Sale del dato y no de una plantilla que alguien tenga
     * que mantener sincronizada.
     */
    public function explicacion(): string
    {
        $partes = [$this->explicacionDelPrecio];

        if (! $this->descuentoLegal->esCero() && $this->categoriaLegal instanceof RangoEdad) {
            $partes[] = sprintf(
                'Descuento de ley (%s): %s.',
                $this->categoriaLegal->etiqueta(),
                $this->descuentoLegal->formateado(),
            );
        }

        if (! $this->descuentoComercial->esCero()) {
            $partes[] = 'Descuento autorizado: '.$this->descuentoComercial->formateado().'.';
        }

        $partes[] = $this->regimen->etiqueta().'.';
        $partes[] = $this->cobertura->explicacion;

        return implode(' ', $partes);
    }

    /**
     * Las columnas de dinero, listas para el `create()`. Están acá y no
     * en el Service para que el snapshot se arme en un solo lugar: si
     * mañana entra una columna nueva, se agrega una vez.
     *
     * @return array<string, mixed>
     */
    public function comoColumnas(): array
    {
        return [
            'origen_precio'            => $this->origen->value,
            'precio_unitario'          => $this->precioUnitario->cantidad()->redondeado(4),
            'tarifario_id'             => $this->tarifarioId,
            'condicion_id'             => $this->condicionId,
            'factor_convenio'          => $this->factorConvenio?->redondeado(4),
            'categoria_legal'          => $this->categoriaLegal?->value,
            'descuento_legal_fraccion' => $this->descuentoLegalFraccion->redondeado(4),
            'base_descuento_legal'     => $this->baseDescuentoLegal->value,
            'descuento_legal'          => $this->descuentoLegal->valor(),
            'descuento_comercial'      => $this->descuentoComercial->valor(),
            'regimen_isv'              => $this->regimen->value,
            'tasa_isv'                 => $this->tasaIsv->redondeado(4),
            'bruto'                    => $this->bruto->valor(),
            'subtotal'                 => $this->subtotal->valor(),
            'base_exenta'              => $this->baseExenta->valor(),
            'base_gravada'             => $this->baseGravada->valor(),
            'isv'                      => $this->isv->valor(),
            'total'                    => $this->total->valor(),
            'cobertura_fraccion'       => $this->cobertura->fraccion->redondeado(4),
            'elegible'                 => $this->cobertura->elegible,
            'porcion_paciente'         => $this->porcionPaciente->valor(),
            'porcion_aseguradora'      => $this->porcionAseguradora->valor(),
            'politica_cargo'           => $this->politica->value,
        ];
    }
}
