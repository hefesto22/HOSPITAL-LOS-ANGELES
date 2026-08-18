<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\NivelDeCoincidencia;
use App\Models\Persona;

/**
 * Un candidato a que el paciente que se está registrando ya exista.
 *
 * Lleva la RAZÓN en texto además del nivel, y eso no es adorno: la persona
 * de admisión tiene que poder decidir en tres segundos con el paciente
 * enfrente. "Se parece" no le sirve; "mismo DNI 0801-****-2345" y "mismo
 * nombre y misma fecha de nacimiento" sí.
 */
final readonly class Coincidencia
{
    public function __construct(
        public Persona $persona,
        public NivelDeCoincidencia $nivel,
        public string $razon,
    ) {}

    public function bloquea(): bool
    {
        return $this->nivel->bloqueaElRegistro();
    }

    /**
     * Línea lista para mostrar en la lista de candidatos.
     */
    public function resumen(): string
    {
        $nacimiento = $this->persona->fecha_nacimiento?->format('d/m/Y') ?? 'sin fecha de nacimiento';

        return "{$this->persona->nombreParaListado()} ({$nacimiento}) — {$this->razon}";
    }
}
