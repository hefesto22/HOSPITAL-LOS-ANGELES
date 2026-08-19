<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No hay tanto de eso en ese almacén.
 *
 * ⚠️ Esta excepción NO nace de haber leído el saldo y comparado. Nace de
 * un `UPDATE ... WHERE cantidad >= :cantidad` que afectó cero filas.
 *
 * La diferencia importa: leer y después decidir deja pasar las dos
 * dispensaciones simultáneas del último frasco —las dos leen «hay 1», las
 * dos dicen que sí— y el estante queda vacío con el sistema diciendo que
 * hay uno. Con el `UPDATE` condicional, la segunda no afecta ninguna fila
 * y termina acá.
 */
final class ExistenciaInsuficienteException extends SihlaException
{
    public static function paraSalida(
        string $item,
        string $almacen,
        string $pedido,
        string $disponible,
    ): self {
        return new self(
            "No alcanza: se pidieron {$pedido} de {$item} en {$almacen} y hay {$disponible}. "
            .'Si el estante dice otra cosa, el ajuste se asienta con su motivo — no se fuerza '
            .'la salida.'
        );
    }

    public static function elLoteNoEsDelItem(string $item, string $lote): self
    {
        return new self(
            "El lote {$lote} no pertenece a {$item}. Un movimiento con el lote de otro producto "
            .'deja los dos kardex mal, y el error se descubre en el conteo físico meses después.'
        );
    }

    public static function faltaElLote(string $item): self
    {
        return new self(
            "{$item} exige lote en cada movimiento: sin él no hay forma de saber qué vence "
            .'cuándo, ni de aplicar FEFO al dispensar.'
        );
    }

    public static function laCantidadDebeSerPositiva(): self
    {
        return new self(
            'La cantidad de un movimiento se pasa siempre positiva: el signo lo pone el tipo. '
            .'Permitir negativos es cómo aparece una dispensación que suma existencias.'
        );
    }

    public static function faltaElMotivo(string $tipo): self
    {
        return new self(
            "Un movimiento de tipo «{$tipo}» exige motivo de al menos diez caracteres. Un ajuste "
            .'sin explicación es la forma más limpia de tapar un faltante: el número cuadra y '
            .'nadie sabe qué pasó.'
        );
    }
}
