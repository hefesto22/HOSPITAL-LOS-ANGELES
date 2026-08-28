<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CajaException;

/**
 * Una parte de lo que se está recibiendo: cuánto y con qué.
 *
 * Dos de estos —L 3,000 en tarjeta y L 2,000 en efectivo— son el pago
 * mixto de un recibo de L 5,000.
 *
 * 🔴 VALIDA EN EL CONSTRUCTOR, no en la pantalla. La pantalla es una de
 * las formas de recibir plata; mañana hay otra —un portal, una interfaz
 * del banco— y las reglas de qué dato exige cada forma tienen que vivir
 * en un solo lugar. Un objeto que existe es un medio de pago conciliable.
 */
final readonly class MedioDePago
{
    public function __construct(
        public FormaDePago $forma,
        public Decimal $monto,
        public ?string $banco = null,
    ) {
        if ($monto->esCero() || $monto->esNegativo()) {
            throw CajaException::montoInvalido();
        }

        if ($forma->exigeBanco() && self::vacio($banco)) {
            throw CajaException::faltaElBanco();
        }

        /*
         * El banco es solo de la transferencia. Un «BAC» colgando de una
         * fila en efectivo no significa nada, y a los seis meses nadie
         * sabe si quiso decir algo.
         */
        if (! $forma->exigeBanco() && ! self::vacio($banco)) {
            throw CajaException::elBancoEsSoloDelDeposito();
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function paraGuardar(): array
    {
        return [
            'forma' => $this->forma->value,
            'monto' => $this->monto->redondeado(2),
            'banco' => self::limpio($this->banco),
        ];
    }

    private static function vacio(?string $valor): bool
    {
        return $valor === null || trim($valor) === '';
    }

    private static function limpio(?string $valor): ?string
    {
        return self::vacio($valor) ? null : trim((string) $valor);
    }
}
