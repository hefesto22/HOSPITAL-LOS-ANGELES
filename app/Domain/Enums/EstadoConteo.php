<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué punto va un conteo físico.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES ESTADOS Y NINGUNO SE LLAMA «BORRADO»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un conteo que se abrió por error no se borra: se **anula** con motivo.
 * Borrarlo dejaría sin explicación la tarde que dos personas estuvieron
 * contando el estante, y esa es exactamente la evidencia que se pide
 * cuando después aparece un faltante.
 *
 * `abierto` es el único estado en el que las líneas se pueden tocar. Un
 * trigger de PostgreSQL lo hace cumplir, no la aplicación: cerrado el
 * conteo, sus líneas son la foto de lo que se contó y ya explican
 * movimientos del kardex que nadie puede editar.
 */
enum EstadoConteo: string
{
    /** Se está contando. Es el único estado que admite escritura. */
    case Abierto = 'abierto';

    /** Ya se asentaron las diferencias. La foto quedó congelada. */
    case Cerrado = 'cerrado';

    /** Se abrió por error o se abandonó. No movió nada y no se borra. */
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::Cerrado => 'Cerrado',
            self::Anulado => 'Anulado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierto => 'warning',
            self::Cerrado => 'success',
            self::Anulado => 'gray',
        };
    }

    public function admiteEscritura(): bool
    {
        return $this === self::Abierto;
    }

    /**
     * Los valores que la base acepta, para el CHECK de la migración.
     *
     * Salen del enum y no de una lista escrita a mano: agregar un estado
     * sin actualizar el CHECK produciría una fila que la base rechaza con
     * un mensaje que no dice nada.
     *
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }
}
