<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\RangoEdad;
use App\Models\DescuentoLegal;

/**
 * Qué descuento le toca a una línea, y por qué.
 *
 * Es lo que devuelve el resolutor y lo que se copia al snapshot del
 * cargo. Lleva el porcentaje Y su fundamento juntos a propósito: un
 * descuento que no puede citar el numeral que lo sustenta es un
 * descuento que no se puede defender ante Protección al Consumidor.
 *
 * El porcentaje viaja como FRACCIÓN —0.25 es 25 %— porque es como se
 * guarda y como se opera. `comoPorcentaje()` existe solo para la
 * pantalla.
 */
final readonly class DescuentoAplicable
{
    private function __construct(
        public Decimal $fraccion,
        public ?string $fundamento,
        public bool $exigeReceta,
        public ?RangoEdad $rango,
    ) {}

    /**
     * No hay derecho a descuento: el paciente no llega a la edad, o el
     * ítem está fuera del Artículo 30.
     *
     * ⚠️ No es lo mismo que un descuento del 0 %. Esa diferencia importa
     * en la factura: "no aplica" y "aplica y da cero" se explican
     * distinto.
     */
    public static function ninguno(): self
    {
        return new self(Decimal::cero(), null, false, null);
    }

    public static function desdeLaLey(DescuentoLegal $fila): self
    {
        return new self(
            $fila->fraccion(),
            $fila->fundamento,
            $fila->exige_receta,
            $fila->rango_edad,
        );
    }

    public function aplica(): bool
    {
        return $this->rango instanceof RangoEdad && ! $this->fraccion->esCero();
    }

    /**
     * El monto que se le descuenta al precio de lista.
     */
    public function sobre(Monto $lista): Monto
    {
        return $lista->multiplicarPor($this->fraccion);
    }

    /**
     * Lo que termina pagando el paciente.
     *
     * ⚠️ Le resta el descuento YA REDONDEADO, no el exacto. Es la
     * diferencia entre una factura que cierra y una que no:
     *
     *     lista 29.33 − descuento 7.33 = neto 22.00
     *
     * Con el descuento exacto —7.3325— el neto daría 21.9975, que
     * redondea al mismo 22.00 por casualidad. En otros números no
     * coincide, y entonces la factura muestra tres cifras que no suman.
     * El paciente hace la resta con el celular y tiene razón.
     *
     * Por eso el §4.5 exige que el snapshot del cargo guarde los TRES
     * valores: lista, descuento y neto. Recalcular cualquiera después da
     * centavos que en una auditoría hay que explicar.
     */
    public function netoDe(Monto $lista): Monto
    {
        $descuento = Monto::de($this->sobre($lista)->valor(), $lista->moneda);

        return $lista->restar($descuento);
    }

    /**
     * "25 %" para pantalla. Sin decimales cuando no hacen falta, porque
     * "25.00 %" en una factura invita a preguntar qué son esos ceros.
     */
    public function comoPorcentaje(): string
    {
        $entero = $this->fraccion->por('100');

        return rtrim(rtrim($entero->redondeado(2), '0'), '.').' %';
    }

    /**
     * Una línea que explica el descuento entero. Va en el detalle de la
     * cuenta y en la pantalla del catálogo.
     */
    public function explicacion(): string
    {
        if (! $this->aplica()) {
            return 'Sin descuento de adulto mayor.';
        }

        $texto = $this->comoPorcentaje();

        if ($this->fundamento !== null) {
            $texto .= ' · '.$this->fundamento;
        }

        if ($this->exigeReceta) {
            $texto .= ' · Exige receta original firmada y sellada';
        }

        return $texto;
    }
}
