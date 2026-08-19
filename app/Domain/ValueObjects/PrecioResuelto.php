<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\OrigenDelPrecio;
use App\Models\ConvenioCondicion;
use App\Models\Tarifario;

/**
 * Cuánto cuesta, y por qué ese número y no otro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEVUELVE LAS FILAS, NO SOLO EL MONTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un resolutor que devolviera `L 24.93` a secas dejaría sin respuesta la
 * única pregunta que importa cuando alguien reclama: **de dónde salió**.
 * Acá viajan las filas que lo produjeron —la del tarifario, y la
 * condición del convenio si el precio se derivó— así que la factura puede
 * guardar sus `id` y el reclamo de dentro de dos años se contesta leyendo
 * dos registros en vez de reconstruyendo la historia a mano.
 *
 * Esto importa especialmente en el precio derivado: `L 24.93` no está
 * escrito en ninguna fila. Sin la condición al lado, dentro de dos años
 * nadie podría rehacer la multiplicación con el factor que regía ese día.
 */
final readonly class PrecioResuelto
{
    public function __construct(
        public Monto $precio,
        public OrigenDelPrecio $origen,
        public Tarifario $fila,
        public ?ConvenioCondicion $condicion = null,
    ) {}

    /**
     * El precio tal cual está escrito en una fila de tarifario.
     */
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

    /**
     * La lista multiplicada por el porcentaje pactado con el pagador.
     */
    public static function conFactor(Tarifario $lista, ConvenioCondicion $condicion): self
    {
        return new self(
            precio: $condicion->aplicarA($lista->monto()),
            origen: OrigenDelPrecio::PorcentajePactado,
            fila: $lista,
            condicion: $condicion,
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

        if ($this->condicion instanceof ConvenioCondicion) {
            return sprintf(
                'Precio de lista %s vigente desde el %s %s, por lo pactado con el pagador: %s. '
                .'Resultado: %s. %s',
                $this->fila->monto()->formateado(),
                $desde,
                $alcance,
                $this->condicion->resumen(),
                $this->precio->formateado(),
                $this->condicion->motivo,
            );
        }

        $quien = $this->esNegociado()
            ? 'precio firmado con el pagador'
            : 'precio de lista';

        return ucfirst($quien).", vigente desde el {$desde} {$alcance}: {$this->fila->motivo}";
    }
}
