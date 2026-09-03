<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide registrar o saldar un préstamo.
 *
 * Estas negativas existen para que el préstamo no se convierta en la
 * puerta trasera del inventario: una entrada sin factura, sin proveedor y
 * sin nadie a quien devolverle es exactamente lo que hay que impedir, y
 * es lo que pasaría si cualquiera de estos campos pudiera quedar a medias.
 */
final class PrestamoException extends SihlaException
{
    public static function sinCantidad(): self
    {
        return new self('La cantidad prestada tiene que ser mayor que cero.');
    }

    public static function faltaQuienPresto(): self
    {
        return new self(
            'Falta el nombre de quien prestó, con al menos tres caracteres. Sin ese nombre esto no '
            .'es un préstamo: es una entrada de inventario que cuadra el saldo y no se le puede '
            .'devolver a nadie.'
        );
    }

    public static function faltaElMonto(): self
    {
        return new self(
            'Se acordó pagarle a quien prestó, así que hace falta el monto. Sin él no hay forma de '
            .'saber cuánto se debe cuando llegue el momento de pagar.'
        );
    }

    public static function elMontoSobra(): self
    {
        return new self(
            'Este préstamo se salda devolviendo el producto, así que no lleva monto. Un monto acá '
            .'es una deuda fantasma: alguien la ve, la paga, y además devuelve la mercadería.'
        );
    }

    public static function yaEstaCerrado(string $estado): self
    {
        return new self("Este préstamo ya está «{$estado}» y no se le puede cargar nada más.");
    }

    public static function noSeDevuelveEnEspecie(): self
    {
        return new self(
            'Este préstamo se acordó pagar en efectivo, no devolver. Para cerrarlo se registra el '
            .'pago, no una devolución de producto.'
        );
    }

    public static function noSePagaEnEfectivo(): self
    {
        return new self(
            'Este préstamo se acordó devolver en producto. Para cerrarlo hay que devolver las '
            .'unidades, no registrar un pago.'
        );
    }

    public static function seDevuelveDeMas(string $pedida, string $pendiente): self
    {
        return new self(
            "Se están devolviendo {$pedida} y solo quedan {$pendiente} pendientes. Devolver de más "
            .'no salda una deuda: descuadra el inventario y deja al hospital regalando producto.'
        );
    }
}
