<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\TipoDeMovimiento;
use Carbon\CarbonInterface;

/**
 * Un renglón del historial de la cuenta, ya contado como pasó.
 *
 * Lo arma `HistorialDeLaCuenta` y lo pinta la vista: acá no hay lógica,
 * solo lo que se va a leer.
 *
 *   · `yaNoCuenta` — el monto ya no está en la cuenta (se quitó, se
 *     cambió o se trasladó después). Se tacha, no se esconde.
 *   · `nota` — lo que le pasó después a este renglón, o un dato de
 *     estado que vale la pena ver («Facturado», «Pasó a otra cuenta»).
 *   · `orden` — desempate para dos cosas en el mismo instante.
 */
final readonly class MovimientoDelHistorial
{
    public function __construct(
        public CarbonInterface $cuando,
        public TipoDeMovimiento $tipo,
        public string $titulo,
        public string $quien,
        public ?string $detalle = null,
        public ?string $motivo = null,
        public ?string $monto = null,
        public bool $yaNoCuenta = false,
        public ?string $nota = null,
        public int $orden = 0,
    ) {}
}
