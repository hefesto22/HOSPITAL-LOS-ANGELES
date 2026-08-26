<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El ciclo de vida del cargo (§8.6.3).
 *
 *   pendiente → facturado
 *             → anulado
 *             → trasladado (a otra cuenta, por cambio de pagador)
 *
 * Y `anulacion`, que no es un estado del cargo original sino **el cargo
 * de reversa**: la fila con los montos en negativo que apunta al que se
 * anuló. Las dos filas suman cero y la historia queda entera, igual que
 * en el kardex.
 *
 * Las transiciones legales están además escritas en un trigger de la
 * base: si el código se equivoca, PostgreSQL lo rechaza.
 */
enum EstadoCargo: string
{
    case Pendiente = 'pendiente';
    case Facturado = 'facturado';
    case Anulado = 'anulado';
    case Anulacion = 'anulacion';
    case Trasladado = 'trasladado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente  => 'Pendiente de facturar',
            self::Facturado  => 'Facturado',
            self::Anulado    => 'Anulado',
            self::Anulacion  => 'Reversa',
            self::Trasladado => 'Trasladado a otra cuenta',
        };
    }

    /**
     * ¿Suma al saldo de la cuenta?
     *
     * `anulado` y `anulacion` suman los dos, y se cancelan entre sí: el
     * original queda con su monto positivo y la reversa con el negativo.
     * Restar el original al anularlo obligaría a editarlo, que es lo que
     * el §9.0.3 prohíbe.
     *
     * `trasladado` no suma acá porque su monto ya está sumando en la
     * cuenta nueva.
     */
    public function cuentaEnElSaldo(): bool
    {
        return $this !== self::Trasladado;
    }

    /**
     * ¿Se puede anular todavía sin pasar por una nota de crédito?
     *
     * Un cargo ya facturado no se anula así: se corrige con nota de
     * crédito, que consume su propio CAI (§8.6.4). Eso es del bloque 7.
     */
    public function admiteAnulacionDirecta(): bool
    {
        return $this === self::Pendiente;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pendiente  => 'warning',
            self::Facturado  => 'success',
            self::Anulado    => 'danger',
            self::Anulacion  => 'danger',
            self::Trasladado => 'gray',
        };
    }
}
