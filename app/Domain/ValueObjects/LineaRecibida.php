<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\RecepcionException;
use App\Models\Item;
use App\Models\ItemPresentacion;
use Carbon\CarbonInterface;

/**
 * Una línea de lo que trajo el camión, ya validada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN OBJETO Y NO EL ARREGLO DEL FORMULARIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El registrador recibe estos objetos y no el `array` que arma Filament.
 * Así la conversión de cajas a unidades y la división del costo ocurren
 * en UN solo lugar, tipado, con bcmath y con tests — en vez de repetirse
 * en cada pantalla que reciba mercadería. Cuando exista la app del
 * celular o un import del sistema viejo, van a construir esto mismo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CONTENIDO SE PASA, NO SE LEE DE LA PRESENTACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * `unidadesPorPresentacion` viaja como dato y no se saca de
 * `$presentacion->unidades_por_presentacion`. Es a propósito: el
 * formulario lo propone desde el catálogo pero quien recibe lo puede
 * corregir —el laboratorio cambió el envase y el catálogo todavía no—, y
 * lo que llegó de verdad manda sobre lo que dice el catálogo.
 */
final readonly class LineaRecibida
{
    /**
     * @param Decimal $costoPorPresentacion lo que costó la caja, IMPUESTO INCLUIDO
     *
     * @throws RecepcionException
     */
    public function __construct(
        public Item $item,
        public ?ItemPresentacion $presentacion,
        public Decimal $cantidadPresentacion,
        public Decimal $unidadesPorPresentacion,
        public Decimal $costoPorPresentacion,
        public ?string $numeroLote = null,
        public ?CarbonInterface $vencimiento = null,
        public ?string $notas = null,
    ) {
        $this->verificar();
    }

    /**
     * Lo que entra al kardex: 100 cajas × 100 tabletas = 10.000 tabletas.
     */
    public function cantidadEnUnidades(): Decimal
    {
        return $this->cantidadPresentacion->por($this->unidadesPorPresentacion);
    }

    /**
     * Lo que costó la línea entera: 100 cajas × L 1.000 = L 100.000.
     */
    public function costoDeLaLinea(): Decimal
    {
        return $this->cantidadPresentacion->por($this->costoPorPresentacion);
    }

    /**
     * Lo que costó UNA unidad de dispensación: L 1.000 ÷ 100 = L 10.
     *
     * Se divide el costo de la presentación entre su contenido y no el
     * costo de la línea entre las unidades de la línea: da lo mismo, pero
     * así la cuenta se puede leer contra la factura sin sacar la
     * calculadora.
     */
    public function costoUnitario(): Decimal
    {
        return $this->costoPorPresentacion->entre($this->unidadesPorPresentacion);
    }

    public function tieneLote(): bool
    {
        return trim($this->numeroLote ?? '') !== '';
    }

    public function numeroDeLote(): ?string
    {
        return $this->tieneLote() ? mb_strtoupper(trim((string) $this->numeroLote)) : null;
    }

    /**
     * Lo que la base no puede verificar sola, con el mensaje puesto.
     *
     * @throws RecepcionException
     */
    private function verificar(): void
    {
        if (! $this->item->mueveInventario()) {
            throw RecepcionException::elItemNoMueveInventario($this->item->etiqueta());
        }

        if ($this->cantidadPresentacion->esCero() || $this->cantidadPresentacion->esNegativo()) {
            throw RecepcionException::laCantidadDebeSerPositiva($this->item->etiqueta());
        }

        if ($this->unidadesPorPresentacion->esCero() || $this->unidadesPorPresentacion->esNegativo()) {
            throw RecepcionException::elContenidoDebeSerPositivo($this->item->etiqueta());
        }

        if ($this->costoPorPresentacion->esNegativo()) {
            throw RecepcionException::elCostoNoPuedeSerNegativo($this->item->etiqueta());
        }

        if ($this->item->requiere_lote && ! $this->tieneLote()) {
            throw RecepcionException::faltaElNumeroDeLote($this->item->etiqueta());
        }

        if ($this->vencimiento instanceof CarbonInterface && ! $this->tieneLote()) {
            throw RecepcionException::vencimientoSinLote($this->item->etiqueta());
        }

        if ($this->presentacion instanceof ItemPresentacion
            && $this->presentacion->item_id !== $this->item->id) {
            throw RecepcionException::laPresentacionEsDeOtroItem(
                $this->item->etiqueta(),
                $this->presentacion->nombre,
            );
        }
    }
}
