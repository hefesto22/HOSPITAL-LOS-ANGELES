<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Con qué se pagó cada parte de un abono.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN ABONO PUEDE TENER VARIAS FORMAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Una parte en tarjeta y otra en efectivo» no es una forma de pago
 * llamada «mixto»: son DOS medios dentro del mismo recibo. Por eso esto
 * vive en `abono_medios` y no en `abonos` — un enum «mixto» obligaría a
 * guardar en otro lado cuánto fue de cada cosa, y el día del arqueo
 * nadie sabría cuánto efectivo tiene que haber en la gaveta.
 *
 * 🔴 EL ARQUEO SOLO CUENTA EFECTIVO. Lo de tarjeta llega por el POS y lo
 * de transferencia por el banco: contarlos en la gaveta daría un
 * sobrante que no existe todas las noches.
 *
 * ⚠️ No hay CHEQUE a propósito. Un cheque no es plata hasta que se
 * cobra, así que exige distinguir «recibido» de «acreditado» y manejar
 * el rebote. Cuando el hospital lo necesite se agrega con ese estado
 * propio, no como una forma más de esta lista.
 */
enum FormaDePago: string
{
    case Efectivo = 'efectivo';
    case Tarjeta = 'tarjeta';
    case Transferencia = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo      => 'Efectivo',
            self::Tarjeta       => 'Tarjeta',
            self::Transferencia => 'Transferencia o depósito',
        };
    }

    /**
     * ¿Esto entra a la gaveta y hay que contarlo al cerrar el turno?
     */
    public function seCuentaEnElArqueo(): bool
    {
        return $this === self::Efectivo;
    }

    /**
     * ¿Exige decir a qué banco entró?
     *
     * Solo la transferencia. Es el único dato que el mostrador teclea
     * además del monto, y sin él nadie sabe en qué estado de cuenta
     * buscar el depósito.
     *
     * ⚠️ La tarjeta NO pide nada: decisión del hospital. El voucher se
     * queda en el papel del POS, que se archiva, y así el sistema no
     * guarda ningún dato de la tarjeta de nadie.
     */
    public function exigeBanco(): bool
    {
        return $this === self::Transferencia;
    }

    /**
     * @return array<string, string>
     */
    public static function paraSelector(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }
}
