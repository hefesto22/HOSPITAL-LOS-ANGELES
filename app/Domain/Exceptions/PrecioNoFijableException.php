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
    /**
     * ─────────────────────────────────────────────────────────────────
     * A UN SEGURO NO SE LE PACTA PRECIO DE FARMACIA
     * ─────────────────────────────────────────────────────────────────
     *
     * El precio de un medicamento sale del costo por el margen, y el
     * costo se mueve con cada compra. Un precio pactado con la
     * aseguradora se queda quieto: el día que el proveedor sube, el
     * hospital sigue cobrándole el número viejo y pone la diferencia de
     * su bolsillo, sin que aparezca en ningún reporte hasta el cierre.
     *
     * Los seguros pagan farmacia al precio de lista. Lo que se pacta son
     * los servicios, que sí tienen precio fijado por dirección.
     */
    public static function esDeFarmacia(string $item, string $convenio): self
    {
        return new self(
            "«{$item}» es de farmacia y no lleva precio pactado. {$convenio} lo paga al precio de lista, ".
            'que se recalcula solo con cada compra. Lo que se pacta con un seguro son los servicios.'
        );
    }

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
