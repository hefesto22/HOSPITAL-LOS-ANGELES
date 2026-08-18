<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué tan fuerte es la sospecha de que este paciente ya está registrado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO TODAS LAS COINCIDENCIAS PESAN IGUAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * "Juan Pérez" hay veinte en cualquier hospital de Honduras. Bloquear el
 * registro cada vez que un nombre se parece a otro convierte la admisión
 * en un trámite, y lo que pasa entonces —siempre— es que la persona de
 * admisión aprende a ignorar la advertencia. Una alerta que salta siempre
 * no alerta de nada.
 *
 * El DNI es distinto: es una PRUEBA, no un parecido. Si el número exacto
 * ya está en el sistema, o es el mismo paciente (y hay que abrir su
 * expediente, no crear otro) o alguien digitó mal (y hay que corregirlo).
 * Ninguna de las dos se arregla creando una persona nueva.
 *
 * De ahí la regla: **solo el documento bloquea**. El resto avisa, muestra
 * los candidatos y deja seguir.
 */
enum NivelDeCoincidencia: string
{
    case Documento = 'documento';
    case Alta      = 'alta';
    case Media     = 'media';

    /**
     * ¿Esta coincidencia impide crear una persona nueva?
     */
    public function bloqueaElRegistro(): bool
    {
        return $this === self::Documento;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Documento => 'Mismo documento de identidad',
            self::Alta      => 'Mismo nombre y misma fecha de nacimiento',
            self::Media     => 'Nombre parecido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Documento => 'danger',
            self::Alta      => 'warning',
            self::Media     => 'info',
        };
    }
}
