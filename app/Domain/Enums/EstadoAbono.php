<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El abono existe o está anulado. No hay más.
 *
 * 🔴 UN ABONO NO SE EDITA NI SE BORRA. Se recibió plata y quedó un
 * recibo con un número; corregirlo es anularlo con motivo y recibir uno
 * nuevo. Un trigger de la base rechaza cualquier UPDATE que toque el
 * monto, la cuenta o el turno.
 *
 * ⚠️ Anular solo se puede con el turno ABIERTO. Cerrado el turno, el
 * efectivo ya se contó y se entregó: sacar plata de un arqueo cerrado
 * es una DEVOLUCIÓN, que es otro hecho, con su propio movimiento de
 * caja, y va en el bloque 7.
 */
enum EstadoAbono: string
{
    case Aplicado = 'aplicado';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Aplicado => 'Aplicado',
            self::Anulado  => 'Anulado',
        };
    }

    /**
     * ¿Este abono baja el saldo de la cuenta?
     */
    public function bajaElSaldo(): bool
    {
        return $this === self::Aplicado;
    }

    public function color(): string
    {
        return match ($this) {
            self::Aplicado => 'success',
            self::Anulado  => 'danger',
        };
    }
}
