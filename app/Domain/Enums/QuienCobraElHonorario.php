<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * A quién va un honorario: a un médico, o a un turno o área del personal.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «También hay honorarios, ejemplo de enfermería … si no se registran
 * las enfermeras, solo los médicos … que salga así: en el reporte
 * pertenece a X turno, sin enfermeras.»
 *
 * Los honorarios de enfermería (TURNO A, B y C, labor y parto,
 * recuperación), el circulante y el instrumentista no son de una persona
 * registrada: son del turno o del área que dice el NOMBRE DEL ÍTEM. Al
 * cargarlos no se elige a nadie, y en «Honorarios por médico» se juntan
 * por ítem. El de un médico, en cambio, exige elegir al médico: es plata
 * que se le debe a alguien, y sin decir a quién no se le puede liquidar.
 *
 * Es un dato del catálogo y no una lista de códigos en el código: la
 * clínica siguiente marca los suyos desde la ficha del servicio (§1.1).
 * Solo tiene sentido en los honorarios; en todo lo demás vale `Medico`
 * y no se lee (lo asegura `Item::booted()` y un CHECK en la base).
 */
enum QuienCobraElHonorario: string
{
    case Medico = 'medico';
    case Personal = 'personal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Medico   => 'Un médico',
            self::Personal => 'Enfermería o personal',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Medico   => 'Al cargarlo se elige al médico, y cuenta en su reporte.',
            self::Personal => 'No se elige a nadie: cuenta para el turno o el área que dice el nombre.',
        };
    }

    public function icono(): string
    {
        return match ($this) {
            self::Medico   => 'heroicon-o-user',
            self::Personal => 'heroicon-o-user-group',
        };
    }
}
