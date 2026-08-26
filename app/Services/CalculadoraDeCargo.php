<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\CargoCalculado;
use App\Domain\ValueObjects\CoberturaAplicada;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\DescuentoAplicable;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioResuelto;
use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use App\Models\Item;
use Carbon\CarbonInterface;

/**
 * La aritmética de una línea de cuenta. No escribe nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ORDEN DE LAS OPERACIONES, Y POR QUÉ ESE Y NO OTRO
 * ─────────────────────────────────────────────────────────────────────
 *
 *   1. bruto        = precio × cantidad
 *   2. descuento legal según la base declarada en el convenio
 *   3. descuento comercial (con motivo y autorizador, §8.6.2-4)
 *   4. subtotal     = bruto − descuentos
 *   5. ISV **por línea**, según el régimen del ítem (§8.6.1)
 *   6. total        = subtotal + ISV
 *   7. cobertura sobre el total, con el tope del evento
 *   8. paciente     = total − aseguradora
 *
 * El paso 8 es una RESTA y no un segundo redondeo. Si las dos porciones
 * se calcularan por separado, el residuo de un centavo haría fallar el
 * CHECK `porcion_paciente + porcion_aseguradora = total` de la base — y
 * en una cuenta de cuarenta líneas eso pasa varias veces al día.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE REDONDEA UNA SOLA VEZ POR MONTO GUARDADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada columna de dinero se redondea a dos decimales en el momento de
 * fijarse, y todo lo que se deriva de ella parte del valor redondeado.
 * Así los CHECK de la base cuadran exacto, que es lo que el golden test
 * del §9.H13.1 verifica al céntimo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRECIO ES ANTES DEL ISV
 * ─────────────────────────────────────────────────────────────────────
 *
 * El tarifario guarda el precio limpio. La cafetería de L 345 del golden
 * test se carga con precio 300 y el ISV de 45 lo pone esta clase. Al
 * revés —guardar el precio final y sacar la base hacia atrás— es de
 * donde salen los centavos que después no cuadran.
 */
final class CalculadoraDeCargo
{
    public function __construct(
        private readonly ResolutorDeDescuentoLegal $descuentosDeLey,
        private readonly PoliticaDeDescuentoComercial $politicaDelHospital,
    ) {}

    public function calcular(
        LineaDeCargo $linea,
        PrecioResuelto $precio,
        DescuentoAplicable $descuentoLegal,
        Convenio $convenio,
        CoberturaAplicada $cobertura,
        CarbonInterface $fechaServicio,
    ): CargoCalculado {
        $item = $linea->item;

        if (! $item->vigenteEn($fechaServicio)) {
            throw CargoException::itemNoVigente($item->nombre, $fechaServicio->format('d/m/Y'));
        }

        // 1 ── Bruto
        $bruto = Monto::de(
            $precio->precio->cantidad()->por($linea->cantidad)->redondeado(2)
        );

        // 2 ── Descuento legal, sobre la base que declaró el convenio
        $base = $this->baseLegalDe($convenio);
        $fraccionLegal = $descuentoLegal->aplica() ? $descuentoLegal->fraccion : Decimal::cero();
        $montoLegal = $this->descuentoLegal($bruto, $fraccionLegal, $base, $cobertura);

        // 3 ── Descuento comercial, el que decide el hospital
        $montoComercial = $this->descuentoComercial($linea, $bruto);

        $this->exigirQueRespeteLaPolitica($linea, $bruto, $montoComercial, $descuentoLegal->rango);

        $descuentos = $montoLegal->sumar($montoComercial);

        if ($descuentos->mayorQue($bruto)) {
            throw CargoException::descuentoMayorQueElCargo($item->nombre);
        }

        $this->exigirQueRespeteElTope($linea, $bruto, $descuentos, $fechaServicio);

        // 4 ── Subtotal
        $subtotal = $bruto->restar($descuentos);

        // 5 y 6 ── ISV por línea y total
        $regimen = $item->regimen_isv;
        $tasa = Decimal::de($regimen->tasaComoTexto());
        $gravado = ! $tasa->esCero();

        $baseExenta = $gravado ? Monto::cero() : $subtotal;
        $baseGravada = $gravado ? $subtotal : Monto::cero();
        $isv = $gravado
            ? Monto::de($subtotal->cantidad()->por($tasa)->redondeado(2))
            : Monto::cero();

        $total = $subtotal->sumar($isv);

        // 7 y 8 ── División, con el residuo siempre del lado del paciente
        $porcionAseguradora = $cobertura->porcionDelPagador($total);
        $porcionPaciente = $total->restar($porcionAseguradora);

        $condicion = $precio->condicion;

        return new CargoCalculado(
            precioUnitario: $precio->precio,
            cantidad: $linea->cantidad,
            origen: $precio->origen,
            tarifarioId: $precio->fila?->id,
            condicionId: $condicion instanceof ConvenioCondicion ? $condicion->id : null,
            factorConvenio: $condicion instanceof ConvenioCondicion
                ? Decimal::de((string) $condicion->factor_sobre_lista)
                : null,
            categoriaLegal: $descuentoLegal->rango instanceof RangoEdad ? $descuentoLegal->rango : null,
            descuentoLegalFraccion: $fraccionLegal,
            baseDescuentoLegal: $base,
            descuentoLegal: $montoLegal,
            descuentoComercial: $montoComercial,
            regimen: $regimen,
            tasaIsv: $tasa,
            bruto: $bruto,
            subtotal: $subtotal,
            baseExenta: $baseExenta,
            baseGravada: $baseGravada,
            isv: $isv,
            total: $total,
            cobertura: $cobertura,
            porcionPaciente: $porcionPaciente,
            porcionAseguradora: $porcionAseguradora,
            politica: $item->politica_cargo,
            explicacionDelPrecio: $precio->explicacion(),
        );
    }

    /**
     * El descuento del hospital, venga como monto o como porcentaje.
     *
     * El porcentaje se resuelve ACÁ y no en la pantalla porque acá es
     * donde el bruto existe: convertirlo antes obligaría a resolver el
     * precio dos veces, y dos resoluciones del mismo precio se separan
     * el día que una cambie.
     */
    private function descuentoComercial(LineaDeCargo $linea, Monto $bruto): Monto
    {
        $fraccion = $linea->descuentoComercialPorcentaje;

        if (! $fraccion instanceof Decimal) {
            return $linea->descuentoComercialODefecto();
        }

        return Monto::de(
            $bruto->cantidad()->por($fraccion)->redondeado(2),
            $bruto->moneda,
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL TOPE: NADIE RECIBE MÁS QUE EL ADULTO MAYOR
     * ─────────────────────────────────────────────────────────────────
     *
     * El descuento TOTAL de una línea —el de ley más el del hospital— no
     * puede pasar del descuento de ley más alto de esa categoría. Un solo
     * número que resuelve dos problemas distintos:
     *
     *   · **Legal.** Si un paciente sin derecho recibiera más que el de
     *     cuarta edad, el beneficio del Art. 30 quedaría invertido: el
     *     que la ley protege pagaría más caro que el resto. Es el mismo
     *     resultado que el «precio inflado» que se rechazó, por otra vía.
     *   · **Económico.** El precio de lista se calcula dividiendo por ese
     *     mismo máximo (§4.5), así que respetarlo ES respetar el piso de
     *     margen. No hacen falta dos reglas ni conocer el costo acá.
     *
     * De ahí salen solos los topes de la política del hospital: cuarta
     * edad 40 % y nada más encima; tercera edad 25 % de ley y hasta 10 %
     * del hospital; sin descuento de ley, hasta 40 %.
     *
     * ⚠️ Lo que está fuera del Art. 30 —cafetería, parqueo— no tiene
     * máximo de ley del que colgarse: ahí manda el tope configurado.
     */
    private function exigirQueRespeteElTope(
        LineaDeCargo $linea,
        Monto $bruto,
        Monto $descuentos,
        CarbonInterface $fechaServicio,
    ): void {
        if ($descuentos->esCero() || $bruto->esCero()) {
            return;
        }

        $tope = $this->topeDe($linea->item, $fechaServicio);

        /*
         * Mismo motivo que en `exigirQueRespeteLaPolitica()`: los dos
         * descuentos ya están redondeados al centavo, así que el techo se
         * redondea igual antes de comparar. Sin eso, medio centavo de
         * redondeo hace fallar un cargo que respeta la ley.
         */
        $maximo = Monto::de($bruto->cantidad()->por($tope)->redondeado(2));

        if (! $descuentos->mayorQue($maximo)) {
            return;
        }

        $aplicada = $descuentos->cantidad()->entre($bruto->cantidad());

        throw CargoException::descuentoSuperaElTopeDeLey(
            $linea->item->nombre,
            $aplicada->comoPorcentaje(),
            $tope->comoPorcentaje(),
        );
    }

    /**
     * ──────────────────────────────────────────────────────────────
     * EL TOPE DE ADENTRO: EL QUE PUSO LA DIRECCIÓN
     * ──────────────────────────────────────────────────────────────
     *
     * Son dos límites distintos y los dos tienen que pasar:
     *
     *   · El de la LEY —`exigirQueRespeteElTope()`— mira el descuento
     *     TOTAL y no depende de nadie: existe aunque la dirección cambie
     *     de idea.
     *   · Este mira SOLO la parte del hospital, y depende de a quién se
     *     le está dando. Es el más estricto de los dos, y por eso se
     *     verifica primero: «para este paciente el máximo es 10 %» es un
     *     mensaje accionable en el mostrador; «el descuento total no
     *     puede pasar del máximo de la categoría» no lo es.
     *
     * ⚠️ El rango sale del descuento de ley que YA lleva la línea, no
     * de la edad suelta del paciente. Es la misma cosa en un medicamento
     * —que es donde la pantalla ofrece el descuento— y es lo correcto
     * fuera del Art. 30: en la cafetería nadie recibe rebaja de ley, así
     * que ahí el margen entero está disponible para todos por igual.
     */
    private function exigirQueRespeteLaPolitica(
        LineaDeCargo $linea,
        Monto $bruto,
        Monto $comercial,
        ?RangoEdad $rango,
    ): void {
        if ($comercial->esCero() || $bruto->esCero()) {
            return;
        }

        $tope = $this->politicaDelHospital->topePara($rango);
        $aQuien = $this->politicaDelHospital->aQuien($rango);

        if ($tope->esCero()) {
            throw CargoException::sinMargenParaDescuentoComercial($linea->item->nombre, $aQuien);
        }

        /*
         * 🔴 SE COMPARAN LEMPIRAS, NO FRACCIONES.
         *
         * El descuento se guarda redondeado al centavo: el 10 % de
         * L 36.67 son L 3.667, que en la columna quedan en L 3.67.
         * Volver a dividir ese 3.67 entre 36.67 da 10.008 %, y el tope
         * del 10 % terminaba rechazando su propio resultado — el sistema
         * calculaba el descuento y después se negaba a aceptarlo.
         *
         * El máximo se redondea con la MISMA regla que el descuento, así
         * que los dos números viven en la misma escala y la comparación
         * significa lo que dice.
         */
        $maximo = Monto::de($bruto->cantidad()->por($tope)->redondeado(2));

        if (! $comercial->mayorQue($maximo)) {
            return;
        }

        throw CargoException::descuentoComercialSuperaLaPolitica(
            $linea->item->nombre,
            $comercial->cantidad()->entre($bruto->cantidad())->comoPorcentaje(),
            $tope->comoPorcentaje(),
            $aQuien,
        );
    }

    private function topeDe(Item $item, CarbonInterface $fechaServicio): Decimal
    {
        $maximoDeLey = $this->descuentosDeLey
            ->maximoPara($item->categoria_legal_descuento, $fechaServicio);

        if ($maximoDeLey->aplica() && ! $maximoDeLey->fraccion->esCero()) {
            return $maximoDeLey->fraccion;
        }

        $configurado = config('sihla.facturacion.tope_descuento_comercial', '0.30');

        return Decimal::de(is_string($configurado) ? $configurado : '0.30');
    }

    /**
     * Sobre qué monto se calcula el descuento del adulto mayor.
     *
     * La decisión ya la tomó quien dio de alta el convenio, con su
     * fundamento escrito (`convenios.base_descuento_legal`, obligatoria y
     * sin default a propósito). Acá solo se obedece: el Art. 30 del
     * Decreto 199-2006 no dice sobre qué monto se calcula cuando paga un
     * seguro, hay tres lecturas defendibles, y ninguna se elige por
     * reflejo dentro de una calculadora.
     */
    private function baseLegalDe(Convenio $convenio): BaseDelDescuentoLegal
    {
        return $convenio->base_descuento_legal;
    }

    private function descuentoLegal(
        Monto $bruto,
        Decimal $fraccion,
        BaseDelDescuentoLegal $base,
        CoberturaAplicada $cobertura,
    ): Monto {
        if ($fraccion->esCero() || ! $base->aplica()) {
            return Monto::cero();
        }

        /*
         * «Sobre lo que paga el paciente»: si el seguro cubre el 80 %, el
         * descuento de ley se calcula sobre el 20 % restante. Es la
         * lectura más conservadora para el hospital y la que más de un
         * pagador exige por contrato — y por eso mismo es una decisión
         * declarada por convenio y no una regla del código.
         */
        /*
         * ⚠️ Deuda declarada: se usa el porcentaje PACTADO, no el que la
         * aseguradora termina poniendo de verdad. Cuando el tope por
         * evento ya está agotado, la aseguradora pone menos que su 80 % y
         * el paciente paga más, así que el descuento de ley que le
         * corresponde es mayor que el que se calcula acá.
         *
         * Resolverlo exacto es circular —el descuento cambia el total, el
         * total cambia la cobertura— y se cierra junto con el deducible
         * en el bloque 4b, que es donde el motor de cobertura se resuelve
         * completo. Mientras tanto la desviación existe solo con el tope
         * agotado y siempre en contra del hospital, nunca del paciente.
         */
        if ($base === BaseDelDescuentoLegal::SobreLoQuePagaElPaciente && $cobertura->elegible) {
            $resto = Decimal::de('1')->restar($cobertura->fraccion);

            return Monto::de(
                $bruto->cantidad()->por($resto)->por($fraccion)->redondeado(2)
            );
        }

        return Monto::de($bruto->cantidad()->por($fraccion)->redondeado(2));
    }
}
