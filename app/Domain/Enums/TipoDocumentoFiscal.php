<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué papel dio el proveedor — y de eso depende el ISV.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTE ENUM ES DEL MÓDULO FISCAL, NO DEL INVENTARIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Vive en `Compra`, que registra en qué se gastó la plata. La mercadería
 * que entra al estante se registra en `Recepcion`, que es otra cosa y no
 * sabe de impuestos.
 *
 * La diferencia entre los dos documentos es lo único que importa acá:
 *
 *   · **Factura**: su ISV se descuenta del ISV de las ventas y entra al
 *     Libro de Compras del SAR.
 *   · **Recibo de compra**: no. Sirve para el control interno del gasto,
 *     pero el impuesto no se acredita.
 *
 * Registrar un recibo como si fuera factura infla el crédito fiscal, y
 * eso es un hallazgo del SAR con multa — no un error de captura.
 */
enum TipoDocumentoFiscal: string
{
    case Factura = 'factura';
    case ReciboDeCompra = 'recibo_de_compra';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Factura        => 'Factura',
            self::ReciboDeCompra => 'Recibo de compra',
        };
    }

    public function explicacion(): string
    {
        return match ($this) {
            self::Factura => 'Su ISV se descuenta del ISV de tus ventas y entra al Libro '
                .'de Compras del SAR.',
            self::ReciboDeCompra => 'Sirve para saber en qué se gastó, pero su impuesto NO '
                .'se acredita ante el SAR.',
        };
    }

    /**
     * ¿El impuesto de este documento se puede acreditar?
     */
    public function acreditaIsv(): bool
    {
        return $this === self::Factura;
    }

    public function color(): string
    {
        return match ($this) {
            self::Factura        => 'success',
            self::ReciboDeCompra => 'warning',
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
