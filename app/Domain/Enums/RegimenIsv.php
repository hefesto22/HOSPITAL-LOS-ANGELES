<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Régimen de ISV de un ítem facturable.
 *
 * ⚠️ LA DECISIÓN MÁS IMPORTANTE DEL MÓDULO DE DINERO (§8.6.1):
 * el ISV se determina POR LÍNEA de ítem, nunca por factura ni por empresa.
 *
 * Una sola cuenta de hospitalización mezcla, de forma perfectamente
 * normal: estancia EXENTA, laboratorio EXENTO, un tratamiento estético
 * GRAVADO al 15 % y la cafetería GRAVADA. Un booleano `es_gravado` a
 * nivel de factura no puede representar eso, y una factura mal armada es
 * un hallazgo del SAR.
 *
 * Base legal — Ley del ISV de Honduras, Art. 15:
 *   inciso (b): exentos los productos farmacéuticos de uso humano,
 *               material de curación quirúrgico y jeringas.
 *   inciso (d): exentos hospitalización, ambulancia, laboratorio clínico,
 *               servicios radiológicos y demás servicios médicos, de
 *               diagnóstico y quirúrgicos — EXCEPTUANDO los tratamientos
 *               de belleza estética, que sí van gravados.
 *
 * "Exento" y "exonerado" son ejes INDEPENDIENTES y se confunden seguido:
 *
 *   - EXENTO    → propiedad del BIEN o servicio. Un medicamento es exento
 *                 se lo venda a quien se lo venda.
 *   - EXONERADO → propiedad del SUJETO que compra, y requiere constancia
 *                 vigente. Un ítem gravado se le factura sin ISV a un
 *                 sujeto exonerado, y hay que guardar el número de esa
 *                 constancia en la factura.
 */
enum RegimenIsv: string
{
    case Exento = 'exento';
    case Gravado15 = 'gravado_15';
    case Gravado18 = 'gravado_18';
    case Exonerado = 'exonerado';

    /**
     * Tasa aplicable como fracción (0.15 = 15 %).
     */
    public function tasa(): float
    {
        return match ($this) {
            self::Exento, self::Exonerado => 0.0,
            self::Gravado15               => (float) config('sihla.isv.tasa_general', 0.15),
            self::Gravado18               => (float) config('sihla.isv.tasa_especial', 0.18),
        };
    }

    /**
     * ¿Exige guardar el número de constancia de exoneración en la factura?
     */
    public function requiereConstancia(): bool
    {
        return $this === self::Exonerado;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Exento    => 'Exento',
            self::Gravado15 => 'Gravado 15 %',
            self::Gravado18 => 'Gravado 18 %',
            self::Exonerado => 'Exonerado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Exento    => 'success',
            self::Gravado15 => 'warning',
            self::Gravado18 => 'danger',
            self::Exonerado => 'info',
        };
    }
}
