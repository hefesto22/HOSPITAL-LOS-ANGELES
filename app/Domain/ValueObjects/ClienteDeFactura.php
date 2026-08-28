<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\FacturaException;

/**
 * A nombre de quién sale la factura, y con qué documento.
 *
 * ⚠️ NO es el paciente necesariamente. La factura puede ir a nombre de
 * la empresa que lo mandó, del seguro o del familiar que paga — y ese
 * nombre, con su documento, es lo que el SAR mira.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL DOCUMENTO NO ES SIEMPRE UN RTN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mucha gente nunca sacó RTN, y arriba del umbral igual hay que
 * identificar al cliente. Se acepta RTN, identidad o pasaporte, y el
 * papel imprime la etiqueta que corresponde.
 *
 * ⚠️ Si el contador del hospital confirma que el SAR exige RTN y solo
 * RTN arriba del umbral, el cambio es en `EmisorDeFactura`: pedir que el
 * tipo sea `Rtn` en lugar de pedir que haya documento. Acá no hay que
 * tocar nada.
 */
final readonly class ClienteDeFactura
{
    public function __construct(
        public string $nombre,
        public ?string $documento = null,
        public ?TipoIdentificador $tipoDocumento = null,
        public ?string $direccion = null,
        public ?string $telefono = null,

        /*
         * Los cuatro del cliente EXONERADO. Van vacíos casi siempre;
         * cuando el que paga es una institución con exoneración, sin
         * ellos la factura no le sirve para nada.
         */
        public ?string $codigo = null,
        public ?string $ordenExenta = null,
        public ?string $constanciaExonerado = null,
        public ?string $registroSag = null,
    ) {
        if (mb_strlen(trim($nombre)) < 3) {
            throw FacturaException::faltaElNombreDelCliente();
        }

        if (! $this->tieneDocumento()) {
            return;
        }

        if (! $tipoDocumento instanceof TipoIdentificador) {
            throw FacturaException::faltaElTipoDeDocumento();
        }

        if (! in_array($tipoDocumento, self::tiposAceptados(), true)) {
            throw FacturaException::eseDocumentoNoIdentificaAlCliente($tipoDocumento->etiqueta());
        }

        $numero = trim((string) $documento);

        /*
         * El RTN y la identidad son solo dígitos: uno con guiones no
         * cuadra en ninguna declaración. El pasaporte sí lleva letras.
         *
         * Los largos van holgados a propósito —13 a 16— para no rechazar
         * un documento legítimo por una diferencia de formato que nadie
         * confirmó todavía con el SAR.
         */
        $formato = $tipoDocumento === TipoIdentificador::Pasaporte
            ? '/^[A-Za-z0-9]{5,20}$/'
            : '/^[0-9]{13,16}$/';

        if (preg_match($formato, $numero) !== 1) {
            throw FacturaException::elDocumentoNoTieneFormato($tipoDocumento->etiqueta());
        }
    }

    public static function consumidorFinal(): self
    {
        $nombre = config('sihla.facturacion.consumidor_final');

        return new self(is_string($nombre) && $nombre !== '' ? $nombre : 'CONSUMIDOR FINAL');
    }

    /**
     * Los que identifican a alguien ante el SAR. El carnet del IHSS o el
     * expediente externo identifican al paciente, no al contribuyente.
     *
     * @return list<TipoIdentificador>
     */
    public static function tiposAceptados(): array
    {
        return [TipoIdentificador::Rtn, TipoIdentificador::Dni, TipoIdentificador::Pasaporte];
    }

    public function tieneDocumento(): bool
    {
        return $this->documento !== null && trim($this->documento) !== '';
    }

    /**
     * @return array<string, string|null>
     */
    public function paraGuardar(): array
    {
        return [
            'cliente_nombre'         => trim($this->nombre),
            'cliente_documento'      => $this->tieneDocumento() ? trim((string) $this->documento) : null,
            'cliente_documento_tipo' => $this->tieneDocumento() ? $this->tipoDocumento?->value : null,
            'cliente_direccion'      => self::limpio($this->direccion),
            'cliente_telefono'       => self::limpio($this->telefono),

            'cliente_codigo'               => self::limpio($this->codigo),
            'cliente_orden_exenta'         => self::limpio($this->ordenExenta),
            'cliente_constancia_exonerado' => self::limpio($this->constanciaExonerado),
            'cliente_registro_sag'         => self::limpio($this->registroSag),
        ];
    }

    private static function limpio(?string $valor): ?string
    {
        return $valor === null || trim($valor) === '' ? null : trim($valor);
    }
}
