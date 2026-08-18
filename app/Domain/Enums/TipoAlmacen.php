<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipo de almacén — dónde vive físicamente el producto.
 *
 * ⚠️ Un almacén NO es lo mismo que un servicio o área clínica, aunque en
 * la pantalla se vean juntos. La jerarquía del §8.1 es:
 *
 *     sedes ──< servicios/áreas ──< almacenes
 *
 * Modelarlos como una sola entidad no puede representar los dos casos que
 * conviven en el hospital de verdad:
 *
 *   - El dispensario surte a emergencia → emergencia consume de un
 *     almacén que NO es suyo.
 *   - Emergencia tiene carro de paro (§1.5) → emergencia SÍ tiene un
 *     stock chico propio.
 *
 * Con una sola entidad no hay forma de decir "el dispensario surtió a
 * emergencia", porque emergencia *sería* el almacén.
 */
enum TipoAlmacen: string
{
    case BodegaCentral = 'bodega_central';
    case FarmaciaVenta = 'farmacia_venta';
    case FarmaciaInterna = 'farmacia_interna';
    case StockDeServicio = 'stock_de_servicio';

    /**
     * ¿Desde acá se dispensa directo a un paciente y se le cobra?
     *
     * La bodega central no dispensa: traslada. Confundirlo es cómo un
     * producto sale de bodega sin pasar por ninguna cuenta.
     */
    public function dispensaAPaciente(): bool
    {
        return match ($this) {
            self::BodegaCentral => false,
            default             => true,
        };
    }

    /**
     * ¿Su consumo se factura al paciente, o es gasto del servicio?
     *
     * La farmacia interna y el carro de paro alimentan la atención; lo que
     * sale de ahí puede terminar como cargo o como gasto según la
     * PoliticaCargo del ítem, nunca por el almacén de origen.
     */
    public function esConsumoInterno(): bool
    {
        return match ($this) {
            self::FarmaciaInterna, self::StockDeServicio => true,
            default                                      => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::BodegaCentral   => 'Bodega central',
            self::FarmaciaVenta   => 'Farmacia de venta',
            self::FarmaciaInterna => 'Farmacia interna / dispensario',
            self::StockDeServicio => 'Stock del servicio',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BodegaCentral   => 'gray',
            self::FarmaciaVenta   => 'success',
            self::FarmaciaInterna => 'info',
            self::StockDeServicio => 'warning',
        };
    }
}
