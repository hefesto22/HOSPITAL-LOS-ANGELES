<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Una fusión de duplicados que no se puede proponer, aprobar o deshacer.
 *
 * Cada caso tiene su constructor con nombre porque el mensaje es lo que
 * ve quien está en el mostrador: "no se puede fusionar" no le dice qué
 * hacer, "esa persona ya fue fusionada en EXP-HLA-00000042" sí.
 */
final class FusionInvalidaException extends SihlaException
{
    public static function esLaMismaPersona(): self
    {
        return new self('No se puede fusionar una persona consigo misma.');
    }

    public static function laDuplicadaYaEstaFusionada(string $sobreviviente): self
    {
        return new self(
            "Esa persona ya fue fusionada en {$sobreviviente}. Si la fusión anterior estaba mal, "
            .'primero hay que deshacerla.'
        );
    }

    public static function laSobrevivienteEstaFusionada(string $raiz): self
    {
        return new self(
            "La persona que se eligió como sobreviviente ya fue fusionada en {$raiz}. "
            .'Proponé la fusión contra esa última, que es la que queda vigente.'
        );
    }

    public static function yaHayUnaPropuestaAbierta(): self
    {
        return new self(
            'Esa persona ya tiene una fusión esperando aprobación. Resolvé la que está antes de '
            .'proponer otra.'
        );
    }

    public static function quienProponeNoPuedeAprobar(): self
    {
        return new self(
            'La fusión la tiene que aprobar una persona distinta de la que la propuso. Ese es el '
            .'control de cuatro ojos del §9.D4: unir dos expedientes que en realidad son de dos '
            .'pacientes distintos mezcla alergias y medicación.'
        );
    }

    public static function noEstaEsperandoDecision(string $estado): self
    {
        return new self("Esta fusión ya está {$estado}: no se puede volver a resolver.");
    }

    public static function noEstaAplicada(): self
    {
        return new self('Solo se puede deshacer una fusión que esté aplicada.');
    }

    public static function haceFaltaUnUsuario(): self
    {
        return new self(
            'Una fusión necesita quedar atribuida a una persona, y no hay usuario autenticado. '
            .'Esta operación no se puede hacer desde consola.'
        );
    }
}
