<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Cómo se le paga a quien prestó.
 *
 * Se elige AL REGISTRAR el préstamo y no al saldarlo, porque las dos
 * formas dejan rastros distintos y el que las va a leer es otro:
 *
 *   · devolver el producto es un movimiento de inventario — sale del
 *     kardex y la deuda se mide en unidades;
 *   · pagarle es plata — la deuda se mide en lempiras y no toca el
 *     inventario: lo prestado ya entró y se queda.
 *
 * Preguntarlo después obliga a quien salda a reconstruir qué se había
 * acordado hace tres semanas, que es justo lo que nadie recuerda.
 */
enum FormaDeSaldo: string
{
    case DevolverProducto = 'devolver_producto';

    case Pagar = 'pagar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DevolverProducto => 'Devolverle el producto',
            self::Pagar            => 'Pagarle',
        };
    }

    public function ayuda(): string
    {
        return match ($this) {
            self::DevolverProducto => 'Se le repone la misma cantidad del mismo producto. La deuda se lleva en unidades.',
            self::Pagar            => 'Se le paga en lempiras. Lo prestado se queda en el inventario.',
        };
    }

    /** ¿Saldar esto mueve el inventario? */
    public function mueveInventario(): bool
    {
        return $this === self::DevolverProducto;
    }

    /** @return array<string, string> */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}
