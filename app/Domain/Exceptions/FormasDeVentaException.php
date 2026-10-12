<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide guardar las formas de venta de un producto.
 *
 * Las dos negativas que importan cuidan el kardex: un envase por el que
 * ya entró mercadería no se borra ni cambia de tamaño. Si la CAJA X 100
 * pasara a ser CAJA X 50, cada lote que entró en esa caja se leería con
 * la mitad de lo que trae, y nadie lo notaría hasta el conteo (§9.G11).
 */
final class FormasDeVentaException extends SihlaException
{
    public static function sinEnvase(): self
    {
        return new self('A una de las formas de venta le falta el envase. Elegilo o quitá la fila.');
    }

    public static function sinContenido(string $envase): self
    {
        return new self("Falta escribir cuántas unidades trae «{$envase}».");
    }

    public static function repetida(string $envase): self
    {
        return new self("«{$envase}» está dos veces. Quitá una de las dos filas.");
    }

    public static function yaEntroMercaderia(string $nombre): self
    {
        return new self(
            "Ya entró mercadería en «{$nombre}», así que no se le puede cambiar el envase ni lo que "
            .'trae: los lotes que entraron así se leerían con otra cantidad. Si ahora viene distinta, '
            .'agregala como otra forma de venta.'
        );
    }

    public static function noSeQuita(string $nombre): self
    {
        return new self(
            "Ya entró mercadería en «{$nombre}», así que no se puede quitar: esos lotes siguen en el "
            .'estante y en el kardex con ese envase.'
        );
    }
}
