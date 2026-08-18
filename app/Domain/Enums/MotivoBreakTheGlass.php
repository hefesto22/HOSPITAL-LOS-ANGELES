<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Motivo tipificado de un acceso de emergencia (break-the-glass).
 *
 * §9.L7 y ADR-0004. El principio no es negociable:
 *
 *   **Nunca se bloquea la atención por permisos.** Un sistema que niega el
 *   expediente a las 3 de la mañana mata pacientes. Pero uno que lo abre
 *   sin dejar rastro destruye la confianza y expone al hospital.
 *
 * La salida es permitir siempre y auditar siempre, con caducidad: el
 * acceso sirve para ESE episodio, no para el resto del año.
 *
 * El motivo se elige de esta lista Y se acompaña de texto libre
 * obligatorio. Una lista sola se vuelve "emergencia" para todo en dos
 * semanas; el texto es lo que el oficial de privacidad realmente lee.
 */
enum MotivoBreakTheGlass: string
{
    case EmergenciaVital = 'emergencia_vital';
    case PacienteInconsciente = 'paciente_inconsciente';
    case CoberturaDeTurno = 'cobertura_de_turno';
    case InterconsultaUrgente = 'interconsulta_urgente';
    case ContinuidadDeAtencion = 'continuidad_de_atencion';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EmergenciaVital       => 'Emergencia con riesgo vital',
            self::PacienteInconsciente  => 'Paciente inconsciente o sin acompañante',
            self::CoberturaDeTurno      => 'Cobertura de turno de otro profesional',
            self::InterconsultaUrgente  => 'Interconsulta urgente solicitada',
            self::ContinuidadDeAtencion => 'Continuidad de atención',
            self::Otro                  => 'Otro (explicar en detalle)',
        };
    }

    /**
     * Los que exigen más detalle en la revisión posterior.
     */
    public function requiereRevisionPrioritaria(): bool
    {
        return $this === self::Otro;
    }
}
