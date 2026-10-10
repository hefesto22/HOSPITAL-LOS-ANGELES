<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cuenta;
use Carbon\CarbonInterface;

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
 * 🔴 EL TECHO ES DE LA PERSONA, NO DEL PAGADOR (9-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los tres números de arriba son lo que el hospital agrega ENCIMA del
 * descuento de ley. Dan, en medicamentos, un techo TOTAL por persona:
 * 40 % la cuarta edad, 35 % la tercera, 30 % el resto.
 *
 * Hay pagadores que no aplican el Art. 30 —las aseguradoras, por
 * decisión del hospital: ver `BaseDelDescuentoLegal::NoAplica`—. Ahí el
 * adulto mayor recibe 0 % de ley, y si su tope siguiera siendo el de
 * arriba quedaría PEOR que el joven: 10 % (o 0 % en la cuarta edad)
 * contra 30 %. Es la primera línea roja del esquema —el adulto mayor
 * nunca paga más que el joven— rota por la puerta de atrás.
 *
 * Por eso el tope de una línea es la política MÁS lo que la ley le
 * tocaba y no le llegó (`topeParaLaLinea()`). El techo total de la
 * persona queda igual lo pague quien lo pague: en una cuenta de PALIG,
 * la tercera edad puede llegar a 35 % y la cuarta a 40 %, todo como
 * descuento del hospital —con motivo escrito, nunca solo—.
 *
 * Sigue sin poder pasar el techo de ley de la categoría, que es el
 * mismo con el que se calculó el precio de lista: el margen queda
 * intacto.
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

    public function __construct(
        private readonly ResolutorDeDescuentoLegal $ley,
    ) {}

    /**
     * Lo que la dirección deja agregar ENCIMA del descuento de ley.
     *
     * Es el número crudo de la configuración. Para decidir cuánto se le
     * puede dar a una línea concreta se usa `topeParaLaLinea()`, que
     * además sabe si la ley llegó o no.
     */
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
     * Cuánto puede rebajar el hospital en UNA línea.
     *
     * La política del rango, más lo que la ley le tocaba a esa línea y
     * no le llegó. Cuando el pagador aplica el Art. 30 completo, la
     * diferencia es cero y queda la política sola, como siempre:
     *
     *     tercera edad, de contado   → 10 % + (25 − 25) = 10 %
     *     tercera edad, con PALIG    → 10 % + (25 −  0) = 35 %
     *     cuarta edad,  con PALIG    →  0 % + (40 −  0) = 40 %
     *
     * Todo en fracciones y sin redondear: quien llama lo convierte a
     * lempiras con la MISMA regla con que redondea el descuento, y así
     * los dos números se comparan en la misma escala (ver el comentario
     * de lempiras en `CalculadoraDeCargo`).
     *
     * @param RangoEdad|null $rango el de la fila de ley que le tocaba a la línea; nulo si no le tocaba ninguna
     * @param Decimal $leTocaba la fracción de ley que le correspondía por edad y categoría
     * @param Decimal $recibio la que de verdad se aplicó, según la base del pagador
     */
    public function topeParaLaLinea(?RangoEdad $rango, Decimal $leTocaba, Decimal $recibio): Decimal
    {
        $tope = $this->topePara($rango);
        $loQueNoLlego = $leTocaba->restar($recibio);

        if ($loQueNoLlego->esCero() || $loQueNoLlego->esNegativo()) {
            return $tope;
        }

        return $tope->sumar($loQueNoLlego);
    }

    /**
     * El tope de una cuenta para una categoría, ANTES de cargar nada.
     *
     * Lo usan la banda de descuento de Cuentas abiertas y el renglón del
     * paquete: los dos tienen que ofrecer un número antes de que exista
     * la línea, y ese número no puede ser mayor que el que la calculadora
     * va a aceptar después.
     *
     * ⚠️ Solo distingue «el pagador aplica el Art. 30» de «no lo aplica».
     * Con la base «sobre lo que paga el paciente» y un seguro que cubre
     * un porcentaje, la ley llega recortada y la calculadora dejaría un
     * poco más. Este número queda por DEBAJO de lo que la calculadora
     * acepta, nunca por encima: la pantalla nunca ofrece algo que el
     * cargo después rechace.
     */
    public function topeEnLaCuenta(
        Cuenta $cuenta,
        CategoriaLegalDeDescuento $categoria,
        CarbonInterface $fecha,
    ): Decimal {
        $rango = $cuenta->encuentro->persona->rangoDeEdadEn($fecha);

        if (! $rango instanceof RangoEdad) {
            return $this->topePara(null);
        }

        $deLey = $this->ley->paraCategoria($categoria, $rango, $fecha);

        if (! $deLey->aplica()) {
            return $this->topePara(null);
        }

        $recibe = $cuenta->convenio->aplicaDescuentoLegal() ? $deLey->fraccion : Decimal::cero();

        return $this->topeParaLaLinea($deLey->rango, $deLey->fraccion, $recibe);
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
