<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * De dónde salió el precio de una línea del presupuesto (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ SE GUARDA EL ORIGEN Y NO SOLO EL NÚMERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque las tres líneas se ven iguales en el papel y se defienden
 * distinto. La del catálogo se sostiene con el tarifario vigente; la
 * escrita a mano la sostiene quien la escribió; y la holgura no es un
 * precio, es un colchón.
 *
 * Sin el origen, el reporte de presupuestado contra real no puede
 * separar «cotizamos mal» de «el cirujano cobró más de lo que dijo».
 */
enum OrigenLineaPresupuesto: string
{
    case Catalogo = 'catalogo';
    case Manual = 'manual';
    case Holgura = 'holgura';

    /**
     * ¿Exige un ítem del catálogo detrás?
     *
     * La holgura nunca lo tiene —no es nada que se dispense—. Una línea
     * manual PUEDE tenerlo: el honorario del cirujano es un ítem del
     * catálogo cuyo precio varía por médico.
     */
    public function exigeItem(): bool
    {
        return $this === self::Catalogo;
    }

    /**
     * ¿Se compara contra los cargos reales en el reporte de varianza?
     *
     * La holgura no: no es un consumo esperado, es margen de error. Si
     * entrara al cotejo, toda cirugía saldría «bajo presupuesto» por el
     * monto del colchón.
     */
    public function entraAlCotejo(): bool
    {
        return $this !== self::Holgura;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Catalogo => 'Precio del tarifario',
            self::Manual   => 'Precio acordado a mano',
            self::Holgura  => 'Holgura del presupuesto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Catalogo => 'success',
            self::Manual   => 'warning',
            self::Holgura  => 'info',
        };
    }
}
