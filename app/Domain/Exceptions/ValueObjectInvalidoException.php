<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lanzada cuando un Value Object recibe un valor que viola sus invariantes
 * (RTN con menos de 14 dígitos, Monto negativo, DNI con longitud
 * equivocada, CAI con formato inválido).
 *
 * Es la única excepción que pueden lanzar los constructores de Value
 * Objects, lo que mantiene el contrato predecible (§7.5, §7.7).
 *
 * ⚠️ Hereda de `SihlaException`, no de `GrupoOlympoException`.
 *
 * Cuando se renombró la excepción raíz en la Etapa 0 esta clase se quedó
 * colgando del padre viejo, y eso rompía en silencio el contrato del §11:
 * un `catch (SihlaException $e)` —que es como se atrapa cualquier error de
 * negocio— NO atrapaba un valor inválido. El síntoma habría sido un 500 en
 * producción por un RTN mal digitado.
 */
final class ValueObjectInvalidoException extends SihlaException
{
    public static function paraCampo(string $campo, string $valor, string $razon): self
    {
        return new self("Valor inválido para {$campo}: '{$valor}'. {$razon}");
    }
}
