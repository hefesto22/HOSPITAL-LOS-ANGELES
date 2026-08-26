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
     *
     * ⚠️ Devuelve `float` y por eso NO se usa para calcular dinero. Sirve
     * para mostrar, para comparar contra cero y para configurar. Lo que
     * entra a la aritmética de una factura es `tasaComoTexto()`.
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
     * La misma tasa, como texto, para entrar directo a bcmath.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ EXISTEN LOS DOS MÉTODOS
     * ─────────────────────────────────────────────────────────────────
     *
     * §8.6.2-1 prohíbe el punto flotante en matemática de dinero, y con
     * razón: `0.15` no es 0.15 en binario. Multiplicar un subtotal por
     * ese float y redondear produce, cada tantos miles de líneas, un
     * centavo que hace fallar el CHECK `total = exento + gravado + isv`
     * de la base — y el error aparece en producción, no en las pruebas.
     *
     * `number_format` acá es seguro y no contradice la regla: el valor
     * viene de `config`, tiene a lo sumo cuatro decimales significativos,
     * y esta es la ÚNICA conversión, hecha una vez y hacia texto. Lo que
     * la regla prohíbe es acumular operaciones en float, no leer un
     * parámetro.
     *
     * @return numeric-string
     */
    public function tasaComoTexto(): string
    {
        return match ($this) {
            self::Exento, self::Exonerado => '0',
            default                       => number_format($this->tasa(), 4, '.', ''),
        };
    }

    /**
     * ¿Se le suma ISV a la línea?
     */
    public function esGravado(): bool
    {
        return $this === self::Gravado15 || $this === self::Gravado18;
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
