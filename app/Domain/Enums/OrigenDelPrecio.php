<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * De dónde salió el precio que se cobró.
 *
 * No es metadato: es la respuesta a «¿por qué esta línea de la factura
 * dice L 29.33?». Sin esto, contestar exige reconstruir a mano qué filas
 * existían ese día — y para entonces alguien ya editó alguna.
 */
enum OrigenDelPrecio: string
{
    /** Una fila del tarifario firmada con ese pagador para ese ítem. */
    case PrecioNegociado = 'precio_negociado';

    /** La fila sin convenio: el precio que ve cualquiera. */
    case PrecioDeLista = 'precio_de_lista';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PrecioNegociado => 'Precio negociado',
            self::PrecioDeLista   => 'Precio de lista',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PrecioNegociado => 'info',
            self::PrecioDeLista   => 'gray',
        };
    }
}
