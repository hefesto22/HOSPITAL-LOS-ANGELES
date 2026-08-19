<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El precio de este ítem no se puede calcular desde el costo.
 *
 * No es un error del sistema: es la respuesta correcta para la Ruta B
 * (§4.1). Una habitación, un hemograma o el honorario de un cirujano no
 * tienen costo de compra — su precio se fija en el tarifario y punto.
 *
 * Los mensajes están escritos para quien está cargando el catálogo, no
 * para el log.
 */
final class PrecioNoDerivableException extends SihlaException
{
    public static function elTipoNoSeCompra(string $tipo): self
    {
        return new self(
            "Un ítem de tipo «{$tipo}» no tiene costo de compra, así que su precio no se "
            .'deriva: se fija a mano en el tarifario.'
        );
    }

    public static function elCostoEsCero(): self
    {
        return new self(
            'El costo es cero, y un margen sobre cero sigue siendo cero: el producto quedaría '
            .'gratis. Si de verdad entró sin costo —una donación, una muestra médica— el precio '
            .'se fija a mano en el tarifario.'
        );
    }

    public static function noHayMargenDefinido(string $tipo, string $fecha): self
    {
        return new self(
            "No hay margen objetivo vigente al {$fecha} para «{$tipo}» ni un default de la "
            .'instalación. Sin margen no hay precio que calcular.'
        );
    }
}
