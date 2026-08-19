<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se puede fijar el margen con esa fecha.
 *
 * La base tiene una restricción de exclusión que impide dos márgenes
 * vigentes a la vez para el mismo tipo. Esta excepción existe para que el
 * usuario lea una frase en castellano en vez del texto crudo de
 * PostgreSQL — que además llegaría como error 500, no como aviso.
 */
final class MargenNoFijableException extends SihlaException
{
    public static function yaHayUnoPosterior(string $tipo, string $desde): self
    {
        return new self(
            "Ya hay un margen para «{$tipo}» que arranca el {$desde} o después. Un margen nuevo "
            .'solo se puede fijar a partir de una fecha posterior a todos los que ya existen: '
            .'reescribir el pasado dejaría precios viejos que la historia ya no explica.'
        );
    }
}
