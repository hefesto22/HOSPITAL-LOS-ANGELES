<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide emitir un documento fiscal.
 *
 * Todos los mensajes dicen qué hacer, porque quien los va a leer tiene a
 * la familia esperando el papel para irse.
 */
final class FacturaException extends SihlaException
{
    public static function laCuentaNoEstaViva(string $numero, string $estado): self
    {
        return new self("La cuenta {$numero} está {$estado}: ya no se factura desde acá.");
    }

    public static function noHayNadaQueFacturar(string $numero): self
    {
        return new self(
            "La cuenta {$numero} no tiene renglones cobrables pendientes. "
            .'Lo incluido en un paquete presupuestado no se factura aparte: ya está adentro del renglón de la cirugía.'
        );
    }

    /**
     * 🔴 La regla del hospital: primero se salda, después se factura.
     */
    public static function laCuentaTieneSaldo(string $numero, string $saldo): self
    {
        return new self(
            "La cuenta {$numero} debe L {$saldo}. Recibí el abono que falta y volvé a facturar: "
            .'acá la factura se emite con la cuenta saldada.'
        );
    }

    public static function faltaElRtn(string $umbral): self
    {
        return new self(
            "Arriba de L {$umbral} el SAR exige los datos del cliente: no se puede facturar a CONSUMIDOR FINAL. "
            .'Pedí el RTN y el nombre tal como lo tiene registrado.'
        );
    }

    public static function elRtnNoTieneFormato(): self
    {
        return new self('El RTN son 14 dígitos, sin guiones ni espacios. Copialo del documento del cliente.');
    }

    public static function noHayCaiVigente(string $tipo): self
    {
        return new self(
            "No hay ningún rango de CAI activo para {$tipo} en esta sede. "
            .'Cargá la resolución del SAR en «Rangos de CAI» antes de facturar.'
        );
    }

    /**
     * 🔴 Emitir con el CAI vencido es emitir un documento que no vale.
     *
     * No es una advertencia: la factura no le sirve al cliente para nada
     * y al hospital le cuesta una multa.
     */
    public static function elCaiVencio(string $cai, string $fecha): self
    {
        return new self(
            "El CAI {$cai} venció el {$fecha}. Ningún documento emitido con él tiene validez. "
            .'Cargá el rango nuevo que autorizó el SAR.'
        );
    }

    public static function elRangoSeAgoto(string $cai, string $hasta): self
    {
        return new self(
            "El rango del CAI {$cai} llegó a su último número ({$hasta}). "
            .'Cargá el rango siguiente: los números no se reutilizan.'
        );
    }

    public static function faltaElNombreDelCliente(): self
    {
        return new self('Escribí a nombre de quién va la factura.');
    }

    public static function faltaElMotivo(): self
    {
        return new self('Escribí por qué se anula, con al menos diez caracteres. Una factura anulada sin motivo es lo primero que pregunta una auditoría.');
    }

    public static function laFacturaYaEstaAnulada(string $numero): self
    {
        return new self("La factura {$numero} ya está anulada.");
    }
}
