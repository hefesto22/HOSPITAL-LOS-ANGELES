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

    /**
     * La cuenta ya no admite que se le cambie la autorización.
     */
    public static function noAdmiteCambios(string $numero, string $estado): self
    {
        return new self(
            "La cuenta {$numero} está {$estado}: el reparto entre el paciente y el seguro ya salió "
            .'impreso en la factura. Para corregirlo hay que anular esa factura, no cambiar la '
            .'autorización por debajo.'
        );
    }

    /**
     * Porcentaje y monto juntos no es más preciso: es no saber cuánto
     * cubre el seguro.
     */
    public static function laAutorizacionEsUnaSolaForma(): self
    {
        return new self(
            'El seguro autoriza un PORCENTAJE o un MONTO, no los dos. Dejá vacío el que no aplique: '
            .'con los dos puestos, cuál manda lo decidiría el código y alguna vez elegiría mal.'
        );
    }

    public static function laCoberturaVaEntreCeroYUno(): self
    {
        return new self(
            'La cobertura se escribe en fracción: 0.30 es el 30 %. Cero significa que el paciente '
            .'paga todo, y uno que el seguro cubre la cuenta entera.'
        );
    }

    public static function elMontoAutorizadoNoEsNegativo(): self
    {
        return new self(
            'Un seguro no autoriza un monto negativo. Si no cubre nada, la autorización es cero o '
            .'se deja vacía para que mande lo que dice el convenio.'
        );
    }

    public static function descuentoEnUnaForma(string $rubro): self
    {
        return new self(
            "El descuento en {$rubro} va en porcentaje o en lempiras, uno de los dos. "
            .'«10 % y L 500» no dice cuánto se le rebajó.'
        );
    }

    public static function descuentoInvalido(string $rubro): self
    {
        return new self(
            "El descuento en {$rubro} tiene que ser mayor que cero, y en porcentaje no puede pasar de 100 %."
        );
    }

    public static function faltaElMotivoDelDescuento(): self
    {
        return new self(
            'Falta el motivo del descuento, de al menos diez letras. Un descuento sin explicación es uno que nadie va a poder defender.'
        );
    }

    public static function noHayNadaParaRepartir(string $rubro, string $monto): self
    {
        return new self(
            "En la cuenta no hay {$rubro} entre los que repartir L {$monto}. "
            .'El descuento en lempiras se reparte entre lo que ya está cargado: cargá primero y rebajá después.'
        );
    }

    public static function descuentoMayorQueElTope(string $rubro, string $monto, string $maximo): self
    {
        return new self(
            "En {$rubro} se puede rebajar hasta L {$maximo}, que es lo que suma lo cargado después del "
            ."descuento de ley, y se pidieron L {$monto}. Ningún renglón puede quedar en negativo."
        );
    }

    public static function porcentajeMayorQueElTope(string $rubro, string $pedido, string $tope): self
    {
        return new self(
            "En {$rubro}, el descuento en porcentaje llega hasta {$tope}, y se pidió {$pedido}. "
            .'En lempiras no tiene ese tope.'
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
