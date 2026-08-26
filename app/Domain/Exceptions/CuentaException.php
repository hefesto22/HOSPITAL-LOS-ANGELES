<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class CuentaException extends SihlaException
{
    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN PACIENTE, UNA CUENTA VIVA (ADR-0007)
     * ─────────────────────────────────────────────────────────────────
     *
     * Dos cuentas abiertas del mismo paciente terminan en dos facturas, y
     * una atención asegurada se cubre por UNA sola: si se presentan dos
     * documentos, la aseguradora procesa uno y el otro se rechaza, o los
     * dos se pagan parcialmente. La diferencia la termina absorbiendo el
     * hospital, y aparece semanas después, cuando ya nadie recuerda por
     * qué se abrió la segunda.
     *
     * Con seguro externo pasa lo mismo, solo que lo sufre el paciente con
     * la factura del hospital en la mano.
     *
     * El mensaje lleva el número de la cuenta a propósito: quien esté en
     * el mostrador no necesita que le nieguen algo, necesita que le digan
     * dónde cargarlo.
     */
    public static function elPacienteYaTieneUnaAbierta(string $paciente, string $numero): self
    {
        return new self(
            "{$paciente} ya tiene la cuenta {$numero} abierta. Cargale ahí lo de esta atención: ".
            'dos cuentas del mismo paciente terminan en dos facturas, y el seguro cubre una sola.'
        );
    }

    public static function yaHayUnaAbierta(string $numero): self
    {
        return new self(
            "Este ingreso ya tiene la cuenta {$numero} abierta. "
            .'Para cambiar de pagador no se abre otra a mano: usá el cambio de pagador, '
            .'que cierra la actual y traslada los cargos pendientes dejando rastro.'
        );
    }

    public static function noAdmiteCargos(string $numero, string $estado): self
    {
        return new self(
            "La cuenta {$numero} está {$estado} y no admite cargos nuevos."
        );
    }

    public static function yaEstaCerrada(string $numero): self
    {
        return new self(
            "La cuenta {$numero} ya está cerrada. Un cargo posterior va a factura complementaria "
            .'o a la cuenta del encuentro siguiente, nunca reabriendo esta.'
        );
    }

    public static function convenioSinVigencia(string $convenio): self
    {
        return new self(
            "El convenio {$convenio} no está vigente hoy. "
            .'Elegí un pagador vigente o abrí la cuenta como CONTADO y corregila cuando llegue la póliza.'
        );
    }

    public static function saldoNoCuadra(string $numero): self
    {
        return new self(
            "Los totales de la cuenta {$numero} no cuadran contra sus cargos. "
            .'No se escribió nada. Reportalo: es un defecto del sistema, no un error de captura.'
        );
    }
}
