<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Este ítem no tiene precio ese día, y eso no se improvisa.
 *
 * La tentación es devolver cero, o el último precio conocido, o el costo.
 * Las tres cobran mal: cero regala el producto, el último precio factura
 * con una tarifa vencida y el costo vende sin margen. Un ítem sin precio
 * es un ítem que no se puede cobrar hasta que alguien le fije uno.
 */
final class PrecioNoDefinidoException extends SihlaException
{
    public static function paraElItem(string $codigo, string $nombre, string $fecha): self
    {
        return new self(
            "El ítem {$codigo} — {$nombre} no tiene precio vigente al {$fecha}. Hay que fijarle "
            .'uno antes de poder cobrarlo: ni el costo ni el último precio conocido sirven de '
            .'reemplazo.'
        );
    }
}
