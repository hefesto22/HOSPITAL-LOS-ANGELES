<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use DomainException;

final class PosologiaException extends DomainException
{
    public static function dosisEnCero(): self
    {
        return new self('La dosis tiene que ser mayor que cero.');
    }

    /**
     * ⚠️ «Cada 0 horas» no es un error de tipeo raro: es lo que queda
     * cuando alguien borra el campo y envía. Sin esta guarda, la división
     * revienta con un error de PHP en vez de decir qué falta.
     */
    public static function frecuenciaInvalida(): self
    {
        return new self('Escribí cada cuántas horas se da: 4, 6, 8, 12 o 24 son las normales.');
    }

    public static function duracionInvalida(): self
    {
        return new self('Escribí por cuántos días. Para una sola vez, poné 1 día cada 24 horas.');
    }
}
