<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\Convenio;

/**
 * Cuánto de esta línea le toca al pagador — resuelto ANTES de calcular,
 * no al cerrar la cuenta (§8.6.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL TOPE VIAJA COMO «DISPONIBLE» Y NO COMO «TOPE»
 * ─────────────────────────────────────────────────────────────────────
 *
 * El tope por evento de las pólizas hondureñas verificadas es del orden
 * de L 30,000, y el exceso lo paga el paciente al 100 %. Ese techo se
 * agota a lo largo de la cuenta, así que lo que la línea número cuarenta
 * necesita saber no es el tope sino **cuánto queda**.
 *
 * Calcularlo así —restando lo que la aseguradora ya lleva, bajo el mismo
 * candado de la cuenta— permite que cada cargo nazca ya dividido y que
 * ninguno tenga que editarse después. Un cargo que hubiera que corregir
 * al cerrar sería un cargo mutable, y eso el §9.0.3 no lo permite.
 *
 * ⚠️ El DEDUCIBLE no está acá y no es un olvido: es un saldo acumulado
 * por persona y por año póliza que cruza encuentros (§9.H9), y vive en
 * la tabla de pólizas del bloque 4b. Modelarlo como un porcentaje más
 * produce cobros dobles o coberturas regaladas.
 */
final readonly class CoberturaAplicada
{
    public function __construct(
        public bool $elegible,
        public Decimal $fraccion,
        public ?Monto $disponibleDelTope,
        public string $explicacion,
    ) {}

    /**
     * Nadie cubre nada: contado, o el pagador excluyó este ítem.
     */
    public static function ninguna(string $porQue): self
    {
        return new self(
            elegible: false,
            fraccion: Decimal::cero(),
            disponibleDelTope: null,
            explicacion: $porQue,
        );
    }

    public static function segunElConvenio(
        Convenio $convenio,
        Decimal $fraccion,
        ?Monto $disponibleDelTope,
    ): self {
        return new self(
            elegible: true,
            fraccion: $fraccion,
            disponibleDelTope: $disponibleDelTope,
            explicacion: sprintf(
                '%s cubre el %s de esta línea%s.',
                $convenio->nombre,
                $fraccion->comoPorcentaje(),
                $disponibleDelTope instanceof Monto
                    ? ', con '.$disponibleDelTope->formateado().' disponibles del tope del evento'
                    : '',
            ),
        );
    }

    /**
     * Cuánto pone la aseguradora sobre un total de línea, ya con el tope
     * aplicado. El residuo del redondeo queda del lado del paciente
     * porque quien resta es el otro lado — y así el CHECK de la base
     * (`porcion_paciente + porcion_aseguradora = total`) se cumple
     * siempre, sin depender de que dos redondeos coincidan.
     */
    public function porcionDelPagador(Monto $total): Monto
    {
        if (! $this->elegible || $this->fraccion->esCero() || $total->esCero()) {
            return Monto::cero();
        }

        $porcion = Monto::de($total->cantidad()->por($this->fraccion)->redondeado(2));

        if ($this->disponibleDelTope instanceof Monto && $porcion->mayorQue($this->disponibleDelTope)) {
            return $this->disponibleDelTope;
        }

        return $porcion;
    }
}
