<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\RangoEdad;
use App\Models\ItemPresentacion;

/**
 * El precio de UNA forma de venta: la tableta suelta, el blíster, la caja.
 *
 * Todo en el envase ENTERO —L 22.00 el blíster, no L 2.20 la tableta—,
 * que es lo que se lee en el mostrador y lo que se cobra cuando sale sin
 * abrir. El unitario se deriva (`listaPorUnidad()`).
 */
final readonly class PrecioDeForma
{
    /**
     * @param string $clave la de `FormasDeCobro`: «dispensacion» o «presentacion:7»
     * @param ItemPresentacion|null $presentacion nula = la unidad suelta
     * @param Decimal $contenido en unidades del kardex: 100 la caja, 1 la suelta
     * @param Decimal $recargo fracción sobre el precio por unidad de la principal
     * @param Monto $minimo lo menos que se cobra: lo que paga la cuarta edad
     * @param Monto $lista lo que paga cualquiera, ya redondeado hacia arriba
     * @param list<array{rango: RangoEdad, paga: Monto}> $porEdad lo que paga cada rango con descuento de ley
     */
    public function __construct(
        public string $clave,
        public ?ItemPresentacion $presentacion,
        public string $rotulo,
        public Decimal $contenido,
        public Decimal $recargo,
        public bool $esLaPrincipal,
        public Monto $minimo,
        public Monto $lista,
        public array $porEdad,
    ) {}

    public function esLaSuelta(): bool
    {
        return ! $this->presentacion instanceof ItemPresentacion;
    }

    /**
     * Lo que vale cada unidad del kardex a precio de lista: L 2.20 la
     * tableta del blíster de L 22.00. Es lo que se guarda en la columna
     * `precio` del tarifario.
     */
    public function listaPorUnidad(): Decimal
    {
        return Decimal::de($this->lista->valor())->entre($this->contenido);
    }
}
