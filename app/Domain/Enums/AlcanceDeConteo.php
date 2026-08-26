<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué tanto del almacén abarca un conteo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES DECORACIÓN: CAMBIA QUÉ SE EXIGE PARA CERRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * En un conteo **total** toda existencia con saldo del almacén tiene que
 * quedar contada. Una línea sin contar bloquea el cierre, y declararla en
 * cero es un acto explícito de alguien, con su nombre. Lo contrario —dar
 * por cero lo que nadie contó— borra el estante entero de un producto
 * porque el que contaba se fue a almorzar.
 *
 * En un conteo **parcial** —el cíclico de todos los martes, los
 * controlados por turno, un solo producto que no cuadra— solo se asientan
 * las líneas que sí se contaron. Es la forma en que un hospital cuenta de
 * verdad: nadie para la farmacia un día entero (§9.G5).
 */
enum AlcanceDeConteo: string
{
    /** Todo lo que tiene saldo en el almacén. Exige contar hasta la última línea. */
    case Total = 'total';

    /** Un grupo elegido: cíclico, controlados, o un producto suelto. */
    case Parcial = 'parcial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Total   => 'Total del almacén',
            self::Parcial => 'Parcial o cíclico',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Total => 'Se cargan todas las existencias con saldo y hay que contarlas todas. '
                .'Nada queda en cero por omisión.',
            self::Parcial => 'Se cuentan solo los productos que se elijan. Lo que no se cuenta, '
                .'no se toca.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Total   => 'info',
            self::Parcial => 'gray',
        };
    }

    /**
     * ¿Cerrar exige que no quede ninguna línea sin contar?
     */
    public function exigeContarTodo(): bool
    {
        return $this === self::Total;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $alcance): string => $alcance->value, self::cases());
    }
}
