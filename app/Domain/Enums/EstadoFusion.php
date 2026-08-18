<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué punto va una fusión de duplicados.
 *
 * ⚠️ `Propuesta` NO significa "todavía no la aplicamos por prolijidad".
 * Significa que la fusión **no ha ocurrido**: las dos personas siguen
 * separadas y atendiéndose por su cuenta. Recién al aprobarla se escribe
 * el puntero. Esa demora es el punto del control de cuatro ojos del
 * §9.D4: unir dos expedientes clínicos que en realidad son de dos
 * personas distintas mezcla alergias y medicación, y eso se descubre
 * tarde.
 */
enum EstadoFusion: string
{
    case Propuesta = 'propuesta';
    case Aplicada = 'aplicada';
    case Rechazada = 'rechazada';
    case Deshecha = 'deshecha';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propuesta => 'Esperando aprobación',
            self::Aplicada  => 'Aplicada',
            self::Rechazada => 'Rechazada',
            self::Deshecha  => 'Deshecha',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Propuesta => 'warning',
            self::Aplicada  => 'success',
            self::Rechazada => 'gray',
            self::Deshecha  => 'info',
        };
    }

    /**
     * ¿Las dos personas están unidas AHORA mismo?
     */
    public function estaUnida(): bool
    {
        return $this === self::Aplicada;
    }

    /**
     * ¿Falta que alguien la resuelva?
     */
    public function esperaDecision(): bool
    {
        return $this === self::Propuesta;
    }
}
