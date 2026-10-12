<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Todos los precios de un producto, salidos de UN número: el mínimo de
 * su envase principal.
 *
 *   CAJA X 100    mínimo L 120.00 → lista L 200.00  ★ principal
 *   BLISTER X 10  mínimo  L 13.20 → lista  L 22.00  (+10 %)
 *   TABLETA       mínimo   L 1.50 → lista   L 2.50  (+25 %)
 *
 * Es lo que la pantalla muestra antes de guardar y lo que el fijador
 * escribe, así que las dos ven exactamente los mismos números.
 */
final readonly class PreciosPorForma
{
    /**
     * @param list<PrecioDeForma> $formas de la más chica a la más grande
     * @param Decimal $descuentoMaximo el peor descuento de ley del ítem, como fracción
     */
    public function __construct(
        public array $formas,
        public Decimal $recargoEnvase,
        public Decimal $recargoSuelta,
        public Decimal $descuentoMaximo,
    ) {}

    public function laPrincipal(): ?PrecioDeForma
    {
        foreach ($this->formas as $forma) {
            if ($forma->esLaPrincipal) {
                return $forma;
            }
        }

        return null;
    }
}
