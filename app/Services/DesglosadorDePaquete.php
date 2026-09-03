<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;

/**
 * Convierte el renglón de un paquete quirúrgico en los renglones que se
 * prestaron de verdad, sin mover un centavo del total.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PROBLEMA RESUELVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * En la cuenta, una apendicectomía es UN cargo cobrable (ADR-0009): los
 * diecinueve consumos entran como `IncluidoEnTarifa` y no se cobran
 * aparte. Eso es correcto para la cuenta y para el kardex.
 *
 * Pero una aseguradora adjudica renglón por renglón: un papel que dice
 * «APENDICECTOMIA L 14,333.55» y nada más se glosa. Así que al emitir se
 * puede pedir que el paquete salga desglosado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA REGLA QUE ORDENA TODO ESTE ARCHIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * **Los totales de la factura no cambian con o sin desglose.** El
 * desglose es presentación, no aritmética nueva. Si la suma de las
 * líneas no da el total del cargo al centavo, el desglose está mal — no
 * la cuenta.
 *
 * Por eso no se recalcula nada: se REPARTE. Los siete montos del cargo
 * —bruto, los dos descuentos, exento, gravado, ISV y total— se prorratean
 * entre las líneas proporcional a lo que valía cada una en el
 * presupuesto, y **el residuo de redondeo cae en la línea de mayor
 * base** (§8.6.2-4). Cada componente se reparte por separado y cada uno
 * cierra exacto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ LÍNEA ENTRA: LO ENTREGADO, NO LO PRESUPUESTADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Sale de `Presupuesto::desglose()`, que ya clasifica cada renglón:
 *
 *   · `incluido` — no sale de un estante: honorarios, sala de
 *     operaciones, laboratorio, alimentación **y la holgura**, que no
 *     tiene ítem por el CHECK `presupuesto_lineas_holgura_sin_item`.
 *     Nada que despachar, así que entra con la cantidad presupuestada.
 *   · `completo` / `parcial` — entra con la cantidad REALMENTE
 *     consumida.
 *   · `pendiente` — sale de farmacia y no se despachó nada. **No entra.**
 *
 * El §8.7-8 ya resolvió este mismo dilema para farmacia: se cobra por
 * consumo real, y facturarle a una aseguradora algo que no se entregó es
 * fraude aunque haya sido descuido. Facturar tres días de habitación
 * cuando el expediente dice dos es ese error con otro nombre.
 *
 * ⚠️ **Consecuencia que hay que saber explicar en el mostrador:** el
 * paquete cobra fijo. Repartido entre menos renglones, cada unidad sale
 * más cara en el papel. Con todo entregado el unitario coincide con el
 * del presupuesto y el descuento se ve parejo; con dos de tres días de
 * habitación, el día impreso sube. Es la aritmética honesta de un
 * paquete —se pagó lo mismo por menos— y fue una decisión tomada a
 * sabiendas, no un efecto que se descubrió después.
 *
 * ⚠️ Los renglones que valen cero no entran. El prorrateo no puede
 * asignarles nada y una fila en 0.00 dentro de un documento fiscal se
 * lee como un error de impresión, no como una cortesía.
 */
final class DesglosadorDePaquete
{
    /**
     * ¿Este cargo es el renglón de un paquete?
     *
     * `presupuesto_id` sin `presupuesto_linea_id` es exactamente eso
     * (ADR-0009). Con los dos es un consumo previsto, y sin ninguno un
     * cargo normal.
     */
    public function esDeUnPaquete(Cargo $cargo): bool
    {
        return $cargo->presupuesto_id !== null && $cargo->presupuesto_linea_id === null;
    }

    /**
     * Los renglones de factura en los que se abre el paquete.
     *
     * Devuelve `null` cuando NO se puede desglosar —el cargo no es de un
     * paquete, el presupuesto ya no está, o no quedó nada que listar—.
     * En ese caso el emisor saca el renglón del paquete como siempre: se
     * degrada a lo que ya funcionaba, nunca se traba una factura con la
     * familia esperando el papel.
     *
     * @return list<array{codigo: string|null, descripcion: string, cantidad: string, precio_unitario: string, bruto: string, descuento_legal: string, descuento_comercial: string, regimen_isv: string, tasa_isv: string, exento: string, gravado: string, isv: string, total: string}>|null
     */
    public function desglosar(Cargo $paquete): ?array
    {
        if (! $this->esDeUnPaquete($paquete)) {
            return null;
        }

        $presupuesto = $paquete->presupuesto;

        if (! $presupuesto instanceof Presupuesto) {
            return null;
        }

        $renglones = $this->loQueSePresto($presupuesto);

        if ($renglones === []) {
            return null;
        }

        $this->exigirUnSoloRegimen($paquete, $renglones);

        $base = Decimal::cero();

        foreach ($renglones as $renglon) {
            $base = $base->sumar($renglon['base']);
        }

        if (! $base->mayorQue('0')) {
            return null;
        }

        return $this->repartir($paquete, $renglones, $base);
    }

    /**
     * Lo que de verdad se prestó, con su valor de presupuesto.
     *
     * @return list<array{linea: PresupuestoLinea, cantidad: Decimal, base: Decimal}>
     */
    private function loQueSePresto(Presupuesto $presupuesto): array
    {
        $renglones = [];

        foreach ($presupuesto->desglose() as $fila) {
            if ($fila['estado'] === 'pendiente') {
                continue;
            }

            $linea = $fila['linea'];

            /*
             * Lo `incluido` no se despacha: se presta. Su «entrega» es
             * que la cirugía ocurrió, así que va por lo presupuestado.
             * Lo de farmacia va por lo que salió del estante.
             */
            $cantidad = $fila['estado'] === 'incluido'
                ? Decimal::de($linea->cantidad)
                : $fila['consumida'];

            if (! $cantidad->mayorQue('0')) {
                continue;
            }

            $base = $cantidad->por($linea->precio_unitario);

            if (! $base->mayorQue('0')) {
                continue;
            }

            $renglones[] = ['linea' => $linea, 'cantidad' => $cantidad, 'base' => $base];
        }

        return $renglones;
    }

    /**
     * 🔴 Un paquete lleva UN régimen de ISV.
     *
     * Hoy no puede pasar otra cosa —lo gravado nunca entra a un paquete,
     * porque un renglón no puede llevar dos regímenes (§8.6.1)—. Pero si
     * algún día pasa, el prorrateo repartiría el impuesto entre líneas
     * que no lo causan y la factura saldría con el ISV mal atribuido: un
     * hallazgo del SAR que nadie vería hasta la auditoría.
     *
     * Así que falla fuerte y con el renglón en el mensaje. Un desglose
     * que no se puede hacer bien no se hace.
     *
     * @param list<array{linea: PresupuestoLinea, cantidad: Decimal, base: Decimal}> $renglones
     */
    private function exigirUnSoloRegimen(Cargo $paquete, array $renglones): void
    {
        foreach ($renglones as $renglon) {
            if ($renglon['linea']->regimen_isv !== $paquete->regimen_isv) {
                throw FacturaException::elPaqueteMezclaRegimenes(
                    $paquete->texto,
                    $renglon['linea']->texto,
                );
            }
        }
    }

    /**
     * El prorrateo.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 SOLO SE REPARTEN LOS MONTOS INDEPENDIENTES
     * ─────────────────────────────────────────────────────────────────
     *
     * La primera versión repartía los siete —bruto, los dos descuentos,
     * exento, gravado, ISV y total— cada uno por su lado. La suma de
     * cada columna cerraba exacta, y aun así la base rechazó la fila:
     *
     *     factura_lineas_bruto_cuadra
     *     CHECK (exento + gravado = bruto - descuento_legal - descuento_comercial)
     *
     * Con bruto 3,047.62 y descuento 457.14 la resta da 2,590.48, pero
     * el exento prorrateado por su cuenta había caído en 2,590.47. Cada
     * redondeo era correcto y la identidad DENTRO de la línea no se
     * cumplía: siete redondeos independientes no tienen por qué respetar
     * una ecuación que los relaciona.
     *
     * Así que se reparten los tres que mandan —bruto y los dos
     * descuentos— y el resto **se deriva**:
     *
     *     subtotal = bruto - descuento_legal - descuento_comercial
     *     total    = subtotal + ISV
     *
     * La suma sigue cerrando por construcción: si las tres columnas
     * repartidas suman las del cargo, el subtotal derivado también,
     * porque es una resta de las tres.
     *
     * ⚠️ El ISV sí se reparte —cuando lo hay— porque no se puede
     * recalcular por línea: `round(gravado_i × tasa)` sumado no da el
     * ISV del cargo. Repartido, sí, y `total = gravado + isv` cierra
     * igual.
     *
     * @param list<array{linea: PresupuestoLinea, cantidad: Decimal, base: Decimal}> $renglones
     *
     * @return list<array{codigo: string|null, descripcion: string, cantidad: string, precio_unitario: string, bruto: string, descuento_legal: string, descuento_comercial: string, regimen_isv: string, tasa_isv: string, exento: string, gravado: string, isv: string, total: string}>
     */
    private function repartir(Cargo $paquete, array $renglones, Decimal $base): array
    {
        /*
         * A qué lado va el importe. Se pregunta por el CARGO y no por el
         * enum: es el cargo el que ya decidió cuánto es exento y cuánto
         * gravado, y este archivo no está para reinterpretarlo.
         */
        $vaGravado = Decimal::de($paquete->base_gravada)->mayorQue('0');

        $montos = [
            'bruto'               => Decimal::de($paquete->bruto),
            'descuento_legal'     => Decimal::de($paquete->descuento_legal),
            'descuento_comercial' => Decimal::de($paquete->descuento_comercial),
        ];

        if ($vaGravado) {
            $montos['isv'] = Decimal::de($paquete->isv);
        }

        $partes = $this->prorratear($montos, $renglones, $base, $this->indiceDeLaMayor($renglones));

        $lineas = [];

        foreach ($renglones as $i => $renglon) {
            $bruto = $partes[$i]['bruto'];

            $subtotal = Decimal::de($bruto)
                ->restar($partes[$i]['descuento_legal'])
                ->restar($partes[$i]['descuento_comercial'])
                ->redondeado(2);

            $isv = $vaGravado ? $partes[$i]['isv'] : '0.00';

            $lineas[] = [
                'codigo'      => $renglon['linea']->item?->codigo,
                'descripcion' => $renglon['linea']->texto,

                'cantidad' => $renglon['cantidad']->redondeado(4),

                /*
                 * Derivado del bruto repartido y NO el precio del
                 * presupuesto: si se imprimiera el del presupuesto,
                 * cantidad × precio no daría el importe de la línea y el
                 * papel no cerraría a la vista de quien lo revisa.
                 */
                'precio_unitario' => Decimal::de($bruto)->entre($renglon['cantidad'])->redondeado(4),

                'bruto'               => $bruto,
                'descuento_legal'     => $partes[$i]['descuento_legal'],
                'descuento_comercial' => $partes[$i]['descuento_comercial'],

                'regimen_isv' => $paquete->regimen_isv->value,
                'tasa_isv'    => $paquete->regimen_isv->tasaComoTexto(),

                'exento'  => $vaGravado ? '0.00' : $subtotal,
                'gravado' => $vaGravado ? $subtotal : '0.00',
                'isv'     => $isv,
                'total'   => Decimal::de($subtotal)->sumar($isv)->redondeado(2),
            ];
        }

        return $lineas;
    }

    /**
     * Reparte cada monto entre las líneas, proporcional a su base.
     *
     * La línea mayor NO se calcula: se despeja. Así la suma cierra
     * exacta por construcción y no por suerte del redondeo, y el centavo
     * que sobra cae donde es ruido y no un porcentaje visible del
     * renglón (§8.6.2-4).
     *
     * @param array<string, Decimal> $montos
     * @param list<array{linea: PresupuestoLinea, cantidad: Decimal, base: Decimal}> $renglones
     *
     * @return array<int, array<string, string>>
     */
    private function prorratear(array $montos, array $renglones, Decimal $base, int $mayor): array
    {
        /** @var array<int, array<string, string>> $partes */
        $partes = [];

        foreach ($montos as $clave => $monto) {
            $repartido = Decimal::cero();

            foreach ($renglones as $i => $renglon) {
                if ($i === $mayor) {
                    continue;
                }

                $parte = $monto->por($renglon['base'])->entre($base)->redondeado(2);

                $partes[$i][$clave] = $parte;
                $repartido = $repartido->sumar($parte);
            }

            $partes[$mayor][$clave] = $monto->restar($repartido)->redondeado(2);
        }

        return $partes;
    }

    /**
     * Dónde cae el residuo: en la línea que más pesa.
     *
     * En la más grande un centavo es ruido; en la más chica puede ser un
     * porcentaje visible del renglón (§8.6.2-4).
     *
     * @param list<array{linea: PresupuestoLinea, cantidad: Decimal, base: Decimal}> $renglones
     */
    private function indiceDeLaMayor(array $renglones): int
    {
        $mayor = 0;

        foreach ($renglones as $i => $renglon) {
            if ($renglon['base']->mayorQue($renglones[$mayor]['base'])) {
                $mayor = $i;
            }
        }

        return $mayor;
    }
}
