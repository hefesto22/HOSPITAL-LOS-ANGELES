<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\TipoEncuentro;
use Carbon\CarbonInterface;

/**
 * Una atención cerrada del paciente, contada para su expediente.
 *
 * La arma `AtencionesDelPaciente` y la pinta la ficha. Sin montos: el
 * expediente lo lee el médico, y el médico no ve costos (§1.4).
 */
final readonly class AtencionDelPaciente
{
    /**
     * @param list<array{codigo: string, descripcion: string, principal: bool}> $diagnosticos
     * @param list<string> $medicos
     * @param list<array{titulo: string, icono: string, items: list<string>, mas: int}> $grupos
     */
    public function __construct(
        public string $encuentro,
        public TipoEncuentro $tipo,
        public string $expediente,
        public string $sede,
        public CarbonInterface $ingreso,
        public CarbonInterface $egreso,
        public string $estancia,
        public ?string $motivo,
        public array $diagnosticos,
        public array $medicos,
        public array $grupos,
        public string $descripcion,
    ) {}
}
