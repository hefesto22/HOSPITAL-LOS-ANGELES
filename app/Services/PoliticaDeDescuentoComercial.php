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
     * Lo que la dirección deja agregar en UNA categoría del Art. 30.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EN SERVICIOS EL TOPE NO SE CONFIGURA: SALE DE LA LEY (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Los números de la configuración —joven 30 %, tercera +10 %, cuarta
     * 0 %— se pensaron para medicamentos, donde la ley le da 40 % a la
     * cuarta edad y el orden queda: joven 30 < tercera 35 < cuarta 40.
     * En un servicio ese orden se rompe. La alimentación lleva 25 % de
     * ley a la tercera edad y 30 % a la cuarta: con el 30 % de farmacia,
     * un joven pagaría igual que alguien de 85 años y menos que alguien
     * de 65. Es la primera línea roja del esquema.
     *
     * Por eso, en los servicios del Art. 30 (Mauricio, 11-oct-2026):
     *
     *   · Sin descuento de ley → lo que la ley le da a la tercera edad en
     *     esa categoría, menos un colchón (`descuento_en_servicios_colchon`,
     *     5 puntos): 20 % en hospitalización y consulta general, 25 % en
     *     especialista, cirugía, laboratorio y rayos X.
     *   · Tercera y cuarta edad → 0 %: llevan la de ley. Si el pagador no
     *     la da, `topeParaLaLinea()` les suma lo que no les llegó.
     *
     * Si cambia la ley de la tercera edad en la tabla de descuentos
     * legales, estos topes se mueven solos.
     *
     * Medicamentos y lo que queda fuera del Art. 30 (cafetería, parqueo)
     * siguen con la configuración de siempre.
     */
    public function topeDeLaCategoria(
        ?RangoEdad $rango,
        CategoriaLegalDeDescuento $categoria,
        CarbonInterface $fecha,
    ): Decimal {
        if (! $this->esDeServicios($categoria)) {
            return $this->topePara($rango);
        }

        if ($rango instanceof RangoEdad && $rango->tieneDescuentoLegal()) {
            return Decimal::cero();
        }

        $deLaTercera = $this->ley->paraCategoria($categoria, RangoEdad::Tercera, $fecha);

        if (! $deLaTercera->aplica()) {
            return $this->topePara(null);
        }

        $colchon = config('sihla.facturacion.descuento_en_servicios_colchon');

        /*
         * Falla cerrado, como el resto de la clase: sin el colchón
         * configurado no hay descuento en servicios. Caer en un número
         * por defecto escondería una configuración rota.
         */
        if (! is_string($colchon)) {
            return Decimal::cero();
        }

        $tope = $deLaTercera->fraccion->restar(Decimal::de($colchon));

        return $tope->esNegativo() ? Decimal::cero() : $tope;
    }

    /**
     * ¿La categoría se topa con la regla de servicios?
     *
     * Todo lo del Art. 30 menos medicamentos y material. Lo que queda
     * fuera de la ley sigue con la configuración.
     */
    public function esDeServicios(CategoriaLegalDeDescuento $categoria): bool
    {
        return $categoria !== CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico
            && $categoria !== CategoriaLegalDeDescuento::SinDescuentoLegal;
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
     * @param CategoriaLegalDeDescuento $categoria de qué es la rebaja: decide si rige la configuración o la regla de servicios
     * @param CarbonInterface|null $fecha la del servicio; con ella se lee la ley de la tercera edad
     */
    public function topeParaLaLinea(
        ?RangoEdad $rango,
        Decimal $leTocaba,
        Decimal $recibio,
        CategoriaLegalDeDescuento $categoria = CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        ?CarbonInterface $fecha = null,
    ): Decimal {
        $tope = $this->topeDeLaCategoria($rango, $categoria, $fecha ?? now());
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
     *
     * `$politica` es de qué es la rebaja, cuando no es lo mismo que el
     * renglón: el paquete quirúrgico es un servicio, pero lo único que
     * se le rebaja son los medicamentos de adentro, y eso se topa como
     * farmacia. Sin ella, la categoría del renglón decide las dos cosas.
     */
    public function topeEnLaCuenta(
        Cuenta $cuenta,
        CategoriaLegalDeDescuento $categoria,
        CarbonInterface $fecha,
        ?CategoriaLegalDeDescuento $politica = null,
    ): Decimal {
        $politica ??= $categoria;
        $rango = $cuenta->encuentro->persona->rangoDeEdadEn($fecha);

        if (! $rango instanceof RangoEdad) {
            return $this->topeDeLaCategoria(null, $politica, $fecha);
        }

        $deLey = $this->ley->paraCategoria($categoria, $rango, $fecha);

        if (! $deLey->aplica()) {
            return $this->topeDeLaCategoria(null, $politica, $fecha);
        }

        $recibe = $cuenta->convenio->aplicaDescuentoLegal() ? $deLey->fraccion : Decimal::cero();

        return $this->topeParaLaLinea($deLey->rango, $deLey->fraccion, $recibe, $politica, $fecha);
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
