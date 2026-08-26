<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El diagnóstico no se edita: se corrige con otro, o se retracta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ADR-0004 — CORREGIR ES ENMENDAR, NO EDITAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un `UPDATE diagnosticos SET codigo = ...` borra para siempre que
 * alguien creyó otra cosa, y eso es exactamente lo que un perito busca:
 * no el diagnóstico final, sino qué se pensó y cuándo se cambió de idea.
 * Cambiar el texto original de una anotación clínica es alteración de
 * evidencia, no corrección de dato.
 *
 * `corregido` es «esto quedó reemplazado por otro»; `retractado` es «esto
 * no debió escribirse nunca». Los dos siguen legibles, tachados, con
 * motivo y autor. Ninguno desaparece.
 */
enum EstadoDiagnostico: string
{
    case Vigente = 'vigente';
    case Corregido = 'corregido';
    case Retractado = 'retractado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente    => 'Vigente',
            self::Corregido  => 'Corregido',
            self::Retractado => 'Retractado',
        };
    }

    public function esVigente(): bool
    {
        return $this === self::Vigente;
    }

    /**
     * Los dos que no están vigentes se muestran, pero tachados: el
     * expediente tiene que poder contar que alguien cambió de idea.
     */
    public function color(): string
    {
        return match ($this) {
            self::Vigente    => 'success',
            self::Corregido  => 'warning',
            self::Retractado => 'danger',
        };
    }
}
