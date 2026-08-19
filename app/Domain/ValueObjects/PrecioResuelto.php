<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\OrigenDelPrecio;
use App\Models\Tarifario;

/**
 * Cuánto cuesta, y por qué ese número y no otro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEVUELVE LA FILA, NO SOLO EL MONTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un resolutor que devolviera `L 29.33` a secas dejaría sin respuesta la
 * única pregunta que importa cuando alguien reclama: **de dónde salió**.
 * Acá viaja la fila del tarifario que ganó, así que la factura puede
 * guardar su `id` y el reclamo de dentro de dos años se contesta leyendo
 * un registro en vez de reconstruyendo la historia a mano.
 */
final readonly class PrecioResuelto
{
    public function __construct(
        public Monto $precio,
        public OrigenDelPrecio $origen,
        public Tarifario $fila,
    ) {}

    public static function desde(Tarifario $fila): self
    {
        return new self(
            precio: $fila->monto(),
            origen: $fila->esPrecioDeLista()
                ? OrigenDelPrecio::PrecioDeLista
                : OrigenDelPrecio::PrecioNegociado,
            fila: $fila,
        );
    }

    public function esNegociado(): bool
    {
        return $this->origen === OrigenDelPrecio::PrecioNegociado;
    }

    /**
     * La frase que se le muestra a quien pregunta. Sale del dato, no de
     * una plantilla que alguien tenga que mantener sincronizada.
     */
    public function explicacion(): string
    {
        $desde = $this->fila->vigencia_desde->format('d/m/Y');

        $alcance = $this->fila->valeParaTodaSede()
            ? 'en todas las sedes'
            : 'solo en esta sede';

        $quien = $this->esNegociado()
            ? 'precio firmado con el pagador'
            : 'precio de lista';

        return ucfirst($quien).", vigente desde el {$desde} {$alcance}: {$this->fila->motivo}";
    }
}
