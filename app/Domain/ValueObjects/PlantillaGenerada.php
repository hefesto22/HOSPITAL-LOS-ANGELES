<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\PlantillaPresupuesto;

/**
 * El resultado de guardar un presupuesto como plantilla (ADR-0008).
 *
 * ⚠️ `omitidos` NO es decoración: son los renglones que NO entraron
 * porque no tienen ítem del catálogo detrás —el honorario escrito a
 * mano, la holgura—. Si eso no se le dice a quien guardó, la próxima
 * cirugía se cotiza sin ellos y nadie se entera hasta el egreso.
 */
final readonly class PlantillaGenerada
{
    /**
     * @param list<string> $omitidos
     */
    public function __construct(
        public PlantillaPresupuesto $plantilla,
        public int $copiados,
        public array $omitidos,
        public bool $reemplazo,
    ) {}

    public function hayOmitidos(): bool
    {
        return $this->omitidos !== [];
    }
}
