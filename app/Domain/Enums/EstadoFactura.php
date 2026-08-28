<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Una factura emitida existe para siempre. Solo cambia si se anula.
 *
 * 🔴 NO HAY «BORRADOR». Un documento fiscal no se guarda a medias: en el
 * instante en que consume un número del rango autorizado, ese número
 * quedó usado ante el SAR aunque nadie lo imprima. Por eso emitir es un
 * acto único y atómico, no un formulario que se completa por partes.
 *
 * Y anular NO libera el número: el rango sigue consumido y la próxima
 * factura toma el siguiente. Un correlativo fiscal no se reutiliza jamás
 * —esa es la regla que hace que el SAR pueda auditar la secuencia—.
 */
enum EstadoFactura: string
{
    case Emitida = 'emitida';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emitida => 'Emitida',
            self::Anulada => 'Anulada',
        };
    }

    public function estaViva(): bool
    {
        return $this === self::Emitida;
    }

    public function color(): string
    {
        return match ($this) {
            self::Emitida => 'success',
            self::Anulada => 'danger',
        };
    }
}
