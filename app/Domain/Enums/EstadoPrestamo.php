<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué va el préstamo.
 *
 * `Parcial` existe porque las devoluciones reales son parciales: llegaron
 * 100 tabletas de las 200 que se debían y se le devolvieron 60. Sin este
 * estado habría que elegir entre marcarlo saldado —y perder las 140 que
 * faltan— o dejarlo pendiente como si no se hubiera devuelto nada.
 */
enum EstadoPrestamo: string
{
    case Pendiente = 'pendiente';

    case Parcial = 'parcial';

    case Saldado = 'saldado';

    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial   => 'Saldado en parte',
            self::Saldado   => 'Saldado',
            self::Anulado   => 'Anulado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'danger',
            self::Parcial   => 'warning',
            self::Saldado   => 'success',
            self::Anulado   => 'gray',
        };
    }

    /** ¿Sigue debiéndose algo? */
    public function sigueAbierto(): bool
    {
        return $this === self::Pendiente || $this === self::Parcial;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}
