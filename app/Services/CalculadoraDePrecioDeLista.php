<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\PrecioNoDerivableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\EscenarioDePrecio;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioSugerido;
use App\Models\Item;
use Carbon\CarbonInterface;

/**
 * De cuánto costó a cuánto se vende. La Ruta A del §4.1.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA FÓRMULA, Y POR QUÉ DIVIDE
 * ─────────────────────────────────────────────────────────────────────
 *
 *                    costo × (1 + margen_objetivo)
 *     precio_lista = ───────────────────────────────
 *                       1 − descuento_máximo
 *
 * Lo natural sería `costo × (1 + margen)` y listo. Con eso, un producto
 * de L 10.00 y margen del 120 % se vendería a L 22.00 — y al adulto
 * mayor, con su 25 % de ley, a L 16.50. **El margen real caería a 65 %.**
 *
 * Dividir por el peor descuento posible es lo que convierte el 120 % en
 * PISO GARANTIZADO y no en objetivo que se incumple con cada paciente
 * mayor. El resultado: L 29.33 de lista, y el adulto mayor paga
 * exactamente los L 22.00 que dejan el 120 %.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN SOLO PRECIO PARA TODOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Nadie recibe un precio de lista distinto por su edad. El descuento cae
 * sobre el mismo precio que ve cualquiera, así que el adulto mayor SÍ
 * paga menos que el paciente que va detrás en la fila.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO PROPONE, NO GUARDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La calculadora devuelve un precio sugerido con toda la cuenta a la
 * vista. Escribirlo en el tarifario es otra operación y otra decisión —
 * y el tarifario es el que manda, siempre (§4.1: "la Ruta A no reemplaza
 * al tarifario: lo alimenta").
 */
final class CalculadoraDePrecioDeLista
{
    public function __construct(
        private readonly ResolutorDeMargenObjetivo $margenes,
        private readonly ResolutorDeDescuentoLegal $descuentos,
    ) {}

    /**
     * @throws PrecioNoDerivableException
     */
    public function para(Item $item, Monto $costoPromedio, CarbonInterface $fecha): PrecioSugerido
    {
        if (! $item->tipo->precioDerivadoDelCosto()) {
            throw PrecioNoDerivableException::elTipoNoSeCompra($item->tipo->etiqueta());
        }

        if ($costoPromedio->esCero()) {
            throw PrecioNoDerivableException::elCostoEsCero();
        }

        $margen = $this->margenes->fraccionPara($item->tipo, $fecha);

        if (! $margen instanceof Decimal) {
            throw PrecioNoDerivableException::noHayMargenDefinido(
                $item->tipo->etiqueta(),
                $fecha->format('d/m/Y'),
            );
        }

        $descuentoMaximo = $this->descuentos->maximoPara($item->categoria_legal_descuento, $fecha);

        $lista = $this->listaDesde($costoPromedio, $margen, $descuentoMaximo->fraccion);

        return new PrecioSugerido(
            costo: $costoPromedio,
            margenObjetivo: $margen,
            descuentoMaximo: $descuentoMaximo,
            lista: $lista,
            escenarios: $this->escenarios($item, $lista, $costoPromedio, $fecha),
        );
    }

    /**
     * El precio de lista, redondeado UNA sola vez al final.
     *
     * §4.5: se redondea sobre la lista, y el descuento se aplica sobre la
     * lista ya redondeada. Redondear antes —el objetivo, el divisor— iría
     * acumulando centavos que después no se pueden explicar.
     */
    private function listaDesde(Monto $costo, Decimal $margen, Decimal $descuentoMaximo): Monto
    {
        $objetivo = $costo->cantidad()->por($margen->sumar('1'));

        $divisor = Decimal::de('1')->restar($descuentoMaximo);

        /*
         * Un descuento del 100 % dejaría el divisor en cero. Hoy el techo
         * legal es 30 %, pero el dato viene de una tabla que alguien
         * puede editar: si el divisor se anula, `Decimal::entre()` lo
         * rechaza con un mensaje claro en vez de dividir entre cero.
         */
        return Monto::de($objetivo->entre($divisor), $costo->moneda);
    }

    /**
     * Qué paga y cuánto deja cada rango de edad, con el precio ya fijado.
     *
     * @return list<EscenarioDePrecio>
     */
    private function escenarios(Item $item, Monto $lista, Monto $costo, CarbonInterface $fecha): array
    {
        $escenarios = [];

        foreach (RangoEdad::cases() as $rango) {
            $descuento = $this->descuentos->para($item, $rango, $fecha);

            $escenarios[] = EscenarioDePrecio::calcular($rango, $descuento, $lista, $costo);
        }

        return $escenarios;
    }
}
