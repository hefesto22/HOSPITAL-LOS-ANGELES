<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * ¿Es el diagnóstico que explica la atención, o uno que acompaña?
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UNA ETIQUETA: ES LO QUE LA ASEGURADORA LEE PRIMERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El PRINCIPAL es el que, después de estudiar al paciente, resultó ser la
 * causa del ingreso. Es contra ese que la aseguradora evalúa si lo que se
 * cobró tiene sentido, y es el que va a la notificación epidemiológica.
 * Por eso hay uno solo por momento, y la base lo exige.
 *
 * Los SECUNDARIOS son las comorbilidades y lo que apareció en el camino.
 * No compiten con el principal: lo acompañan, y son los que explican por
 * qué una neumonía de un diabético cuesta el doble.
 */
enum TipoDiagnostico: string
{
    case Principal = 'principal';
    case Secundario = 'secundario';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Principal  => 'Principal',
            self::Secundario => 'Secundario',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Principal  => 'danger',
            self::Secundario => 'gray',
        };
    }
}
