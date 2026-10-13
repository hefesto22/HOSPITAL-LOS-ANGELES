<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Lo que cambió al guardar las formas de venta de un producto
 * (13-oct-2026).
 *
 * `cambios` va al historial de precios —es el porqué del ajuste
 * automático— y `avisos` a quien guardó la ficha.
 *
 *   cambios: «BLISTER X 10 TAB PASÓ A BLISTER X 12 TAB»,
 *            «SE AGREGÓ SOBRE X 4 TAB», «LA PRINCIPAL PASÓ DE … A …»
 *   avisos:  «Lo que ya está en el estante como BLISTER X 10 TAB…»
 */
final readonly class CambiosDeFormasDeVenta
{
    /**
     * @param list<string> $cambios en mayúsculas, como van al motivo
     * @param list<string> $avisos en palabras de pantalla
     */
    public function __construct(
        public array $cambios = [],
        public array $avisos = [],
    ) {}

    public function hayCambios(): bool
    {
        return $this->cambios !== [];
    }

    /**
     * El porqué del ajuste, sin el prefijo «AJUSTE AUTOMÁTICO»: eso lo
     * pone quien ajusta.
     */
    public function porQue(): string
    {
        return implode('; ', $this->cambios);
    }
}
