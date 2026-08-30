<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Ese traslado no se puede hacer.
 *
 * Lo que NO está acá es el «no alcanza»: eso lo tira
 * `ExistenciaInsuficienteException` desde el propio movimiento, que es
 * quien de verdad lo sabe —por un `UPDATE` condicional, no por haber
 * leído el saldo—. Duplicar esa verificación acá daría un segundo lugar
 * donde el número se puede leer viejo.
 */
final class TrasladoException extends SihlaException
{
    public static function elMismoAlmacen(string $almacen): self
    {
        return new self(
            "El origen y el destino son el mismo estante: {$almacen}. Un traslado a sí mismo "
            .'deja dos líneas en el kardex que se anulan entre sí y ensucian la historia sin '
            .'mover nada.'
        );
    }

    public static function elDestinoEstaCerrado(string $almacen, string $desde): self
    {
        return new self(
            "{$almacen} dejó de estar vigente el {$desde}: no puede recibir mercadería. Un "
            .'almacén cerrado se consulta, no se carga.'
        );
    }

    public static function noSeTrasladaEntreSedes(string $origen, string $destino): self
    {
        return new self(
            "«{$origen}» y «{$destino}» son de sedes distintas. Entre sedes la mercadería sale "
            .'de una y entra a la otra con su propio documento, porque el costo y el kardex son '
            .'de cada sede — un traslado directo los mezclaría.'
        );
    }

    public static function laCantidadDebeSerPositiva(): self
    {
        return new self(
            'No se puede trasladar una cantidad de cero o negativa. Si lo que se quiere es '
            .'devolver, el traslado va al revés: se elige el otro estante como origen.'
        );
    }
}
