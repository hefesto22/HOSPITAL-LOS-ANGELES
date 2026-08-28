<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El turno de caja: abierto mientras alguien está recibiendo plata.
 *
 * Turno A, turno B, turno C. Lo abre y lo cierra la persona, a mano, y
 * mientras está abierto es el único lugar donde puede caer un abono.
 *
 * 🔴 UNO ABIERTO POR PERSONA. Un índice parcial de la base lo garantiza:
 * con dos turnos abiertos, la misma cajera repartiría sus recibos entre
 * los dos sin darse cuenta y ninguno de los dos arqueos cuadraría.
 */
enum EstadoTurnoDeCaja: string
{
    case Abierto = 'abierto';
    case Cerrado = 'cerrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::Cerrado => 'Cerrado',
        };
    }

    public function estaAbierto(): bool
    {
        return $this === self::Abierto;
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierto => 'warning',
            self::Cerrado => 'gray',
        };
    }
}
