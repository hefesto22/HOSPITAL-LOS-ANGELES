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
 * `$presentacion->unidades_por_presentacion`. La pantalla de hoy ya no
 * deja corregirlo —lo pone la presentación, y si el laboratorio cambió el
 * envase se carga otra presentación—, pero un import del sistema viejo
 * trae el contenido escrito en su propio papel, y ese manda.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL COSTO ES OPCIONAL (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: la recepción registra lo que entró, no lo que costó.
 * `null` es «se recibió sin costo» y NO es cero: cero es una donación,
 * que entra al promedio y lo baja; `null` no lo toca. Ver
 * `RegistradorDeRecepcion::asentar()`.
 */
final readonly class LineaRecibida
{
    /**
     * @param Decimal|null $costoPorPresentacion lo que costó la caja, IMPUESTO INCLUIDO; null si se recibió sin costo
     *
     * @throws RecepcionException
     */
    public function __construct(
        public Item $item,
        public ?ItemPresentacion $presentacion,
        public Decimal $cantidadPresentacion,
        public Decimal $unidadesPorPresentacion,
        public ?Decimal $costoPorPresentacion,
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
     * ¿Vino con costo? Sin costo, la línea entra al kardex sin tocar el
     * promedio y sin proponer precio.
     */
    public function traeCosto(): bool
    {
        return $this->costoPorPresentacion instanceof Decimal;
    }

    /**
     * Lo que costó la línea entera: 100 cajas × L 1.000 = L 100.000.
     * Null si se recibió sin costo.
     */
    public function costoDeLaLinea(): ?Decimal
    {
        return $this->costoPorPresentacion instanceof Decimal
            ? $this->cantidadPresentacion->por($this->costoPorPresentacion)
            : null;
    }

    /**
     * Lo que costó UNA unidad de dispensación: L 1.000 ÷ 100 = L 10.
     *
     * Se divide el costo de la presentación entre su contenido y no el
     * costo de la línea entre las unidades de la línea: da lo mismo, pero
     * así la cuenta se puede leer contra la factura sin sacar la
     * calculadora. Null si se recibió sin costo.
     */
    public function costoUnitario(): ?Decimal
    {
        return $this->costoPorPresentacion instanceof Decimal
            ? $this->costoPorPresentacion->entre($this->unidadesPorPresentacion)
            : null;
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

        /*
         * Mientras tenga stock infinito no lleva existencia: recibirle
         * algo sería empezar a contar a medias (13-oct-2026).
         */
        if ($this->item->tieneStockInfinito()) {
            throw RecepcionException::tieneStockInfinito($this->item->etiqueta());
        }

        if ($this->cantidadPresentacion->esCero() || $this->cantidadPresentacion->esNegativo()) {
            throw RecepcionException::laCantidadDebeSerPositiva($this->item->etiqueta());
        }

        if ($this->unidadesPorPresentacion->esCero() || $this->unidadesPorPresentacion->esNegativo()) {
            throw RecepcionException::elContenidoDebeSerPositivo($this->item->etiqueta());
        }

        if ($this->costoPorPresentacion instanceof Decimal && $this->costoPorPresentacion->esNegativo()) {
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
                $this->presentacion->etiqueta(),
            );
        }
    }
}
