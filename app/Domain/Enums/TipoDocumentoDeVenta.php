<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Los papeles que el hospital EMITE, no los que recibe.
 *
 * ⚠️ No confundir con `TipoDocumentoFiscal`, que es del lado de las
 * compras —factura o recibo del proveedor— y decide si el ISV se
 * acredita. Este es el lado de las ventas y decide qué rango de CAI se
 * consume.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL CÓDIGO DE DOS DÍGITOS NO ESTÁ ACÁ, Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El número fiscal es `NNN-NNN-NN-NNNNNNNN`: establecimiento, punto de
 * emisión, TIPO DE DOCUMENTO y correlativo. Ese tercer segmento lo
 * asigna el SAR en la resolución que autoriza el rango, y el sistema lo
 * COPIA de ahí —vive en `rangos_cai.tipo_codigo`—.
 *
 * Ponerlo acá como una constante sería adivinarlo. Un dígito equivocado
 * en el tercer segmento son facturas emitidas con un número que no
 * corresponde al rango autorizado: eso no se corrige, se anula todo y se
 * vuelve a emitir, con el hallazgo del SAR incluido (pregunta #1 de
 * `docs/dominio.md`, todavía sin contestar).
 */
enum TipoDocumentoDeVenta: string
{
    case Factura = 'factura';
    case NotaDeCredito = 'nota_de_credito';
    case NotaDeDebito = 'nota_de_debito';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Factura       => 'Factura',
            self::NotaDeCredito => 'Nota de crédito',
            self::NotaDeDebito  => 'Nota de débito',
        };
    }

    /**
     * ¿Este documento resta de lo facturado?
     *
     * La nota de crédito es la ÚNICA forma de deshacer una factura
     * emitida: el papel fiscal no se borra ni se edita.
     */
    public function resta(): bool
    {
        return $this === self::NotaDeCredito;
    }

    public function color(): string
    {
        return match ($this) {
            self::Factura       => 'success',
            self::NotaDeCredito => 'danger',
            self::NotaDeDebito  => 'warning',
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }
}
