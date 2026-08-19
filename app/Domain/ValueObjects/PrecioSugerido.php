<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\RangoEdad;
use LogicException;

/**
 * El precio de lista propuesto, con la cuenta completa a la vista.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ DEVUELVE TODOS LOS ESCENARIOS Y NO SOLO EL PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * §4.5, regla de implementación: **antes de confirmar un precio, la
 * pantalla muestra el margen resultante en CADA rango de edad, ya con el
 * descuento aplicado. La decisión se toma con los números a la vista, no
 * a ojo.**
 *
 * Un servicio que devolviera solo `L 29.33` obligaría a la pantalla a
 * rehacer la cuenta para mostrar qué gana el hospital con un paciente de
 * 65 años — y ahí ya hay dos implementaciones de la misma fórmula, que
 * es como empiezan a diferir.
 */
final readonly class PrecioSugerido
{
    /**
     * @param list<EscenarioDePrecio> $escenarios uno por rango de edad
     */
    public function __construct(
        public Monto $costo,
        public Decimal $margenObjetivo,
        public DescuentoAplicable $descuentoMaximo,
        public Monto $lista,
        public array $escenarios,
    ) {}

    /**
     * El escenario donde el hospital gana menos: el del descuento más
     * alto.
     *
     * Es el que decide si la política se cumple. Mirar el margen del
     * paciente sin descuento —el más alto— es mirar el número que nunca
     * está en riesgo.
     *
     * @throws LogicException si se armó un precio sin escenarios, que no
     *                        es un dato posible sino un bug: la
     *                        calculadora arma uno por cada rango de edad
     *                        y los rangos son un enum con casos.
     */
    public function peorEscenario(): EscenarioDePrecio
    {
        $peor = null;

        foreach ($this->escenarios as $escenario) {
            if (! $peor instanceof EscenarioDePrecio
                || $escenario->margenResultante->menorQue($peor->margenResultante)) {
                $peor = $escenario;
            }
        }

        if (! $peor instanceof EscenarioDePrecio) {
            throw new LogicException(
                'Se armó un PrecioSugerido sin ningún escenario de edad. '
                .'Sin escenarios no hay forma de saber si el margen se sostiene.'
            );
        }

        return $peor;
    }

    public function escenarioDe(RangoEdad $rango): ?EscenarioDePrecio
    {
        foreach ($this->escenarios as $escenario) {
            if ($escenario->rango === $rango) {
                return $escenario;
            }
        }

        return null;
    }

    /**
     * ¿El margen se sostiene incluso en el peor caso?
     *
     * Debería dar siempre verdadero: la fórmula parte del peor descuento
     * justamente para eso. Que exista este método es la comprobación de
     * que la fórmula hizo lo que dice — y avisa si alguien carga un
     * descuento nuevo sin recalcular el catálogo.
     */
    public function cumpleElPiso(): bool
    {
        return ! $this->peorEscenario()->margenResultante->menorQue($this->margenObjetivo);
    }

    /**
     * "120 %" para pantalla.
     */
    public function margenObjetivoComoPorcentaje(): string
    {
        return EscenarioDePrecio::comoPorcentaje($this->margenObjetivo);
    }
}
