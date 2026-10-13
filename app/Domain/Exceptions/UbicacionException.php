<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide decir en qué almacén está un producto con stock infinito.
 */
final class UbicacionException extends SihlaException
{
    public static function noTieneStockInfinito(string $item): self
    {
        return new self(
            "{$item} lleva existencia real: dónde está lo dice lo que hay en cada estante, y se "
            .'mueve con su cantidad desde Existencias.'
        );
    }

    public static function esElUnicoLugar(string $item, string $almacen): self
    {
        return new self(
            "{$almacen} es el único lugar donde está {$item}. Para sacarlo de ahí, muévalo primero "
            .'a otro almacén: si no, al cargarlo a una cuenta nadie sabría de dónde sale.'
        );
    }

    public static function mismoAlmacen(string $almacen): self
    {
        return new self("Sale de {$almacen} y entra a {$almacen}: elija otro almacén de destino.");
    }

    public static function noHayEseEnvase(string $item, string $envase, string $almacen): self
    {
        return new self(
            "En {$almacen} no hay {$envase} de {$item}: no se puede llevar de ahí lo que no hay. "
            .'Elija lo que sí hay, o corrija lo que hay en «Lugares».'
        );
    }

    public static function otraSede(string $almacen): self
    {
        return new self(
            "{$almacen} es de otra sede. Cada sede lleva sus propios estantes: el producto se ubica "
            .'en los de la sede donde se va a despachar.'
        );
    }
}
