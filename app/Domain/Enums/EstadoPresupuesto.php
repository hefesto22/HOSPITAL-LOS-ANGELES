<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El ciclo de vida del presupuesto (ADR-0008).
 *
 *   borrador → agregado → sustituido   (se revisó: la cirugía se complicó)
 *                      → vencido      (pasó su fecha sin usarse)
 *                      → cerrado      (la cuenta cerró; queda de histórico)
 *            → anulado
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EMITIDO NO SE EDITA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque en ese momento se imprimió y la familia lo leyó. Editarlo
 * después es cambiarle el número al papel que alguien tiene en la mano
 * —y es exactamente la queja con la que empieza un reclamo—. Corregir es
 * emitir uno nuevo que apunte al anterior, igual que la reversa de un
 * cargo apunta al original (§9.0.3).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO UNO MIDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se pueden cotizar dos opciones a la misma persona; las dos viven en
 * `borrador`. Pero la barra de la cuenta necesita UN denominador, así
 * que solo el `emitido` alimenta el medidor, y la base garantiza que
 * haya como mucho uno por encuentro.
 */
enum EstadoPresupuesto: string
{
    case Borrador = 'borrador';
    case Agregado = 'agregado';
    case Sustituido = 'sustituido';
    case Vencido = 'vencido';
    case Cerrado = 'cerrado';
    case Anulado = 'anulado';

    /**
     * ¿Se le pueden tocar las líneas?
     *
     * Borrador y agregado: mientras el paciente está internado los
     * renglones se siguen tocando, porque es lo que pasa de verdad
     * (ADR-0009). Un trigger de la base lo verifica además de esto:
     * si el código se equivoca, PostgreSQL rechaza la escritura.
     */
    public function esEditable(): bool
    {
        return $this === self::Borrador || $this === self::Agregado;
    }

    /**
     * ¿Este presupuesto es el que alimenta el medidor de la cuenta?
     */
    public function mide(): bool
    {
        return $this === self::Agregado;
    }

    /**
     * ¿Ya terminó su vida útil? Los cerrados sirven para el reporte de
     * presupuestado contra real, que es la razón de guardarlos.
     */
    public function esHistorico(): bool
    {
        return match ($this) {
            self::Sustituido, self::Vencido, self::Cerrado, self::Anulado => true,
            self::Borrador, self::Agregado                                => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador   => 'En elaboración',
            self::Agregado   => 'Agregado a la cuenta',
            self::Sustituido => 'Sustituido por otro',
            self::Vencido    => 'Vencido',
            self::Cerrado    => 'Cerrado',
            self::Anulado    => 'Anulado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador   => 'gray',
            self::Agregado   => 'success',
            self::Sustituido => 'warning',
            self::Vencido    => 'warning',
            self::Cerrado    => 'info',
            self::Anulado    => 'danger',
        };
    }
}
