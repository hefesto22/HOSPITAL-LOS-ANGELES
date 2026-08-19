<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se puede escribir ese precio con esa fecha.
 *
 * La base tiene una restricción de exclusión que impide dos precios
 * vigentes a la vez para la misma combinación de ítem, pagador y sede.
 * Esta excepción existe para que quien carga el catálogo lea una frase en
 * castellano en vez del texto crudo de PostgreSQL — que además llegaría
 * como error 500 y no como aviso.
 */
final class PrecioNoFijableException extends SihlaException
{
    public static function yaHayUnoPosterior(string $item, string $paraQuien, string $desde): self
    {
        return new self(
            "Ya hay un precio de {$item} para {$paraQuien} que arranca el {$desde} o después. Un "
            .'precio nuevo solo se puede fijar desde una fecha posterior a todos los que ya '
            .'existen: meter una fila en medio del historial haría que una venta ya cobrada pase '
            .'a explicarse con una tarifa que ese día no existía.'
        );
    }

    public static function yaHayCondicionPosterior(string $convenio, string $desde): self
    {
        return new self(
            "Ya hay una condición pactada con {$convenio} que arranca el {$desde} o después. Una "
            .'condición nueva solo se puede pactar desde una fecha posterior a todas las que ya '
            .'existen: si no, las facturas de esa renovación pasarían a calcularse con un '
            .'porcentaje que ese día todavía no se había firmado.'
        );
    }
}
