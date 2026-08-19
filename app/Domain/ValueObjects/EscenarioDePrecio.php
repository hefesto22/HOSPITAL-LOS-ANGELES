<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\ValueObjectInvalidoException;

/**
 * Qué pasa con este precio para un paciente de cierta edad.
 *
 * Una fila de la tabla que la pantalla muestra antes de confirmar:
 *
 *   Normal (< 60)      L 29.33   —        193 %
 *   Tercera edad (60+) L 29.33   25 %     120 %  ← el piso
 *
 * El margen resultante se calcula sobre lo que el paciente PAGA, no
 * sobre el precio de lista. Es la única cifra que responde la pregunta
 * de verdad: cuánto le queda al hospital cuando atiende a esta persona.
 */
final readonly class EscenarioDePrecio
{
    public function __construct(
        public RangoEdad $rango,
        public DescuentoAplicable $descuento,
        public Monto $paga,
        public Decimal $margenResultante,
    ) {}

    /**
     * Arma el escenario a partir del precio de lista y el costo.
     *
     * @throws ValueObjectInvalidoException si el costo es cero
     */
    public static function calcular(
        RangoEdad $rango,
        DescuentoAplicable $descuento,
        Monto $lista,
        Monto $costo,
    ): self {
        $paga = $descuento->netoDe($lista);

        /*
         * margen = (lo que paga − lo que costó) / lo que costó
         *
         * Se usa el valor REDONDEADO de lo que paga, que es lo que se
         * cobra de verdad. Calcularlo sobre el exacto daría un margen que
         * no corresponde a ninguna transacción real.
         */
        $margen = Decimal::de($paga->valor())
            ->restar($costo->valor())
            ->entre($costo->valor());

        return new self($rango, $descuento, $paga, $margen);
    }

    public function margenComoPorcentaje(): string
    {
        return self::comoPorcentaje($this->margenResultante);
    }

    public function cumple(Decimal $piso): bool
    {
        return ! $this->margenResultante->menorQue($piso);
    }

    /**
     * Una fracción a porcentaje legible: 1.2 → "120 %".
     *
     * Delega en `Decimal` a propósito. El margen objetivo, el margen
     * resultante y el descuento legal son el mismo tipo de número y
     * tienen que verse igual en toda la pantalla; dos implementaciones
     * del mismo formato es como empiezan a diferir.
     */
    public static function comoPorcentaje(Decimal $fraccion): string
    {
        return $fraccion->comoPorcentaje();
    }
}
