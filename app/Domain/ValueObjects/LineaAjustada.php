<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\AjusteException;
use App\Models\Item;
use App\Models\Lote;

/**
 * Un producto que se ajusta, ya validado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA CANTIDAD ES POSITIVA; EL SIGNO LO PONE EL MOTIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Misma regla que en el kardex (§ `TipoMovimiento`): a quien construye
 * esto se le pide siempre un número positivo y una dirección explícita.
 * Dejar que el signo viaje en el número es cómo aparece una rotura que
 * suma existencias porque alguien tecleó el menos de más.
 *
 * Y no cualquier motivo admite cualquier dirección: `Rotura` solo resta,
 * `SobranteDeConteo` solo suma, y `ErrorDeRegistro` es el único que va en
 * las dos —se recibieron 100 y se cargaron 1.000, o al revés—.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ SE PARA EL AJUSTE DE UN CONTROLADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.F11, y es una regla 🔴: la existencia de un estupefaciente o de un
 * psicotrópico NO se ajusta directamente. Se para en el value object y no
 * en la pantalla, para que valga igual si el ajuste viene de un import,
 * de un comando o de una pantalla que todavía no existe.
 */
final readonly class LineaAjustada
{
    /**
     * @param Decimal $cantidad SIEMPRE positiva: el signo lo pone la dirección
     * @param bool $esEntrada true suma existencia, false la resta
     *
     * @throws AjusteException
     */
    public function __construct(
        public Item $item,
        public ?Lote $lote,
        public MotivoDeAjuste $motivo,
        public Decimal $cantidad,
        public bool $esEntrada,
        public ?string $texto = null,
        public ?int $conteoLineaId = null,
    ) {
        $this->verificar();
    }

    /**
     * La cantidad tal como va al kardex y a la línea: con signo.
     */
    public function cantidadFirmada(): Decimal
    {
        return $this->esEntrada ? $this->cantidad : $this->cantidad->por('-1');
    }

    /**
     * Con qué tipo de movimiento se asienta.
     */
    public function movimiento(): TipoMovimiento
    {
        return $this->motivo->movimiento($this->esEntrada);
    }

    /**
     * Cuánto vale lo que se ajusta, al costo promedio que se le pase.
     *
     * En valor absoluto: un sobrante y un faltante del mismo tamaño pesan
     * igual contra el tope de autorización. Lo que interesa para decidir
     * si alguien tiene que mirar esto es cuánta plata se mueve, no en qué
     * dirección.
     */
    public function valorAl(Decimal $costoUnitario): Decimal
    {
        return $this->cantidad->por($costoUnitario);
    }

    /**
     * El motivo tipificado y el caso concreto, en una sola línea, para el
     * campo `motivo` del kardex.
     *
     * El CHECK del kardex exige diez caracteres mínimo en ajustes y
     * mermas; la etiqueta sola no siempre llega, y por eso el texto libre
     * también es obligatorio arriba, en el documento.
     */
    public function motivoParaElKardex(): string
    {
        $texto = trim($this->texto ?? '');

        return $texto === ''
            ? $this->motivo->etiqueta()
            : $this->motivo->etiqueta().' · '.$texto;
    }

    /**
     * @throws AjusteException
     */
    private function verificar(): void
    {
        if ($this->cantidad->esCero() || $this->cantidad->esNegativo()) {
            throw AjusteException::laCantidadDebeSerPositiva($this->item->etiqueta());
        }

        /*
         * 🔴 §9.F11 — prohibición absoluta. Va antes que cualquier otra
         * verificación para que el mensaje que ve la persona sea este y
         * no un reproche sobre el lote.
         */
        if ($this->item->es_controlado) {
            throw AjusteException::esUnControlado($this->item->etiqueta());
        }

        if ($this->esEntrada && ! $this->motivo->admiteEntrada()) {
            throw AjusteException::elMotivoNoAdmiteEsaDireccion($this->motivo->etiqueta(), true);
        }

        if (! $this->esEntrada && ! $this->motivo->admiteSalida()) {
            throw AjusteException::elMotivoNoAdmiteEsaDireccion($this->motivo->etiqueta(), false);
        }

        if ($this->item->requiere_lote && ! $this->lote instanceof Lote) {
            throw AjusteException::faltaElLote($this->item->etiqueta());
        }

        if ($this->lote instanceof Lote && $this->lote->item_id !== $this->item->id) {
            throw AjusteException::elLoteNoEsDelItem($this->item->etiqueta(), $this->lote->numero);
        }
    }
}
