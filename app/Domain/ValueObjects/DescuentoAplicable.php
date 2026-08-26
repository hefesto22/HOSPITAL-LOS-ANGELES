<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\RangoEdad;
use App\Models\Descuento;
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
 *
 * Viene de dos lados y el objeto es el mismo:
 *
 *   · de `descuentos_legales`, indexado por numeral del Art. 30;
 *   · de `descuentos`, la lista con nombres que arma el hospital.
 *
 * Por eso hay un `$nombre` opcional: lo de la ley no tiene nombre —su
 * nombre es el numeral—, y lo del hospital sí, y ese nombre es lo que
 * el paciente espera ver en la factura.
 */
final readonly class DescuentoAplicable
{
    private function __construct(
        public Decimal $fraccion,
        public ?string $fundamento,
        public bool $exigeReceta,
        public ?RangoEdad $rango,
        public ?string $nombre = null,
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

    /**
     * Un descuento del catálogo del hospital, aplicado a un rango de edad.
     *
     * ⚠️ El rango se pasa aparte y no se saca de `$fila->aplica_a`: lo
     * que hay que guardar en el cargo es la edad que TENÍA EL PACIENTE,
     * no la que el descuento apunta. Un paciente de la cuarta edad al que
     * le toca el descuento de la tercera —porque la cuarta no tiene fila
     * propia— es de la cuarta edad igual, y así tiene que quedar escrito.
     */
    public static function desdeElCatalogo(Descuento $fila, RangoEdad $rango): self
    {
        /*
         * Sin fundamento: los descuentos del hospital no llevan uno. El
         * numeral del Art. 30 lo trae `desdeLaLey()`, que es de donde
         * sale cuando la ley es la que manda.
         */
        return new self(
            $fila->fraccion(),
            null,
            $fila->exige_receta,
            $rango,
            $fila->nombre,
        );
    }

    public function aplica(): bool
    {
        return $this->rango instanceof RangoEdad && ! $this->fraccion->esCero();
    }

    /**
     * De los dos, el que más le conviene al paciente.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LA LEY ES PISO, NUNCA TECHO
     * ─────────────────────────────────────────────────────────────────
     *
     * El hospital puede dar más que la ley —es su plata— pero no menos.
     * Por eso lo de la lista del hospital y lo del Artículo 30 no se
     * reemplazan: se comparan, y gana el mayor.
     *
     * Empatados gana el receptor. Quien llama pone primero el de la ley
     * —`$deLaLey->oElMejorDe($delCatalogo)`— porque es el que trae el
     * numeral: en dinero da igual, en un reclamo no.
     */
    public function oElMejorDe(self $otro): self
    {
        return $otro->fraccion->mayorQue($this->fraccion) ? $otro : $this;
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

        if ($this->nombre !== null) {
            $texto = $this->nombre.': '.$texto;
        }

        if ($this->fundamento !== null) {
            $texto .= ' · '.$this->fundamento;
        }

        if ($this->exigeReceta) {
            $texto .= ' · Exige receta original firmada y sellada';
        }

        return $texto;
    }
}
