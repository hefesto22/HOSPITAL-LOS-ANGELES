<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\Decimal;

/**
 * Cuánto puede rebajar el hospital por su cuenta, y a quién.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO NO ES LEY: ES POLÍTICA DE DIRECCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Por eso vive en configuración y no en `descuentos_legales`. La ley
 * pone el TECHO —`legal + comercial ≤ máximo de ley de la categoría`—
 * y eso se verifica aparte, en `CalculadoraDeCargo`. Esta clase pone el
 * límite de adentro, el que decidió la dirección:
 *
 *   · Cuarta edad (80+)    → 0 %. Ya recibe el 40 % de ley, que ES el
 *     techo. El precio de lista se calculó dividiendo por ese mismo
 *     40 %, así que un punto más no sale del precio: sale del margen.
 *   · Tercera edad (60-79) → hasta 10 % sobre el 25 % de ley. Total
 *     35 %, que deja al paciente de 80 años pagando menos que el de 65.
 *     Ese orden es lo que mantiene el esquema del lado legal.
 *   · Sin descuento de ley → hasta 30 %.
 *
 * ⚠️ Bajarlos es seguro. SUBIRLOS no alcanza para pasar el techo de
 * ley: ese cálculo se hace igual y rechaza el cargo. Esta política solo
 * puede ser más estricta que la ley, nunca más floja.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ FALLA CERRADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Sin configuración —una clave mal escrita, un `config:cache` viejo— el
 * tope es CERO y la pantalla deja de ofrecer el descuento. Al revés
 * —caer en un default generoso— una configuración rota se vería igual
 * que una correcta, y la diferencia solo aparecería en la utilidad del
 * mes.
 */
final class PoliticaDeDescuentoComercial
{
    /**
     * La clave de quien no recibe descuento de ley en esa línea.
     *
     * `RangoEdad::Normal` y «no sé la edad» caen las dos acá: en ambos
     * casos la línea no lleva rebaja de ley, así que el margen entero
     * está disponible para lo que la dirección quiera dar.
     */
    private const SIN_RANGO = 'sin_rango';

    public function topePara(?RangoEdad $rango): Decimal
    {
        $clave = $rango instanceof RangoEdad && $rango->tieneDescuentoLegal()
            ? $rango->value
            : self::SIN_RANGO;

        $configurado = config('sihla.facturacion.descuento_comercial_por_rango.'.$clave);

        if (! is_string($configurado)) {
            return Decimal::cero();
        }

        return Decimal::de($configurado);
    }

    /**
     * Cómo se nombra a este paciente en un mensaje de error.
     *
     * Vive acá y no en `RangoEdad::etiqueta()` porque es otra frase: la
     * etiqueta rotula una columna («Tercera edad») y esto completa una
     * oración («…y para un paciente de tercera edad el máximo es 10 %»).
     */
    public function aQuien(?RangoEdad $rango): string
    {
        return match ($rango) {
            RangoEdad::Tercera => 'un paciente de tercera edad',
            RangoEdad::Cuarta  => 'un paciente de cuarta edad',
            default            => 'un paciente sin descuento de ley',
        };
    }
}
