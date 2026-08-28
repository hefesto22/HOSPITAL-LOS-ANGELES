<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\FacturaException;

/**
 * A nombre de quién sale la factura.
 *
 * ⚠️ NO es el paciente necesariamente. La factura puede ir a nombre de
 * la empresa que lo mandó, del seguro o del familiar que paga — y ese
 * nombre, con su RTN, es lo que el SAR mira. Por eso se pide aparte en
 * vez de copiarlo del expediente.
 */
final readonly class ClienteDeFactura
{
    public function __construct(
        public string $nombre,
        public ?string $rtn = null,
        public ?string $direccion = null,
    ) {
        if (mb_strlen(trim($nombre)) < 3) {
            throw FacturaException::faltaElNombreDelCliente();
        }

        /*
         * El RTN hondureño son 14 dígitos. Se acepta un rango un poco
         * más ancho a propósito —13 a 16— para no rechazar un documento
         * legítimo por una diferencia de formato que nadie confirmó
         * todavía con el SAR; lo que sí se rechaza es cualquier cosa que
         * no sean dígitos, porque un RTN con guiones no cuadra en
         * ninguna declaración.
         */
        if ($rtn !== null && trim($rtn) !== '' && preg_match('/^[0-9]{13,16}$/', trim($rtn)) !== 1) {
            throw FacturaException::elRtnNoTieneFormato();
        }
    }

    public static function consumidorFinal(): self
    {
        $nombre = config('sihla.facturacion.consumidor_final');

        return new self(is_string($nombre) && $nombre !== '' ? $nombre : 'CONSUMIDOR FINAL');
    }

    public function tieneRtn(): bool
    {
        return $this->rtn !== null && trim($this->rtn) !== '';
    }

    /**
     * @return array<string, string|null>
     */
    public function paraGuardar(): array
    {
        return [
            'cliente_nombre'    => trim($this->nombre),
            'cliente_rtn'       => $this->tieneRtn() ? trim((string) $this->rtn) : null,
            'cliente_direccion' => $this->direccion === null || trim($this->direccion) === ''
                ? null
                : trim($this->direccion),
        ];
    }
}
