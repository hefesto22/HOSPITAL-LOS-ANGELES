<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\EnvaseDisponible;
use App\Domain\ValueObjects\TomaDeEnvase;

/**
 * De qué frascos sale lo que se despacha.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA, EN DOS RENGLONES
 * ─────────────────────────────────────────────────────────────────────
 *
 * **Se consume en orden de vencimiento, y si hay que destapar uno, se
 * destapa el que vence antes.** Solo entre frascos que vencen el mismo
 * día decide la aritmética: la combinación que deje menos destapado, y a
 * igualdad la que toque menos envases.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL VENCIMIENTO GANA AUNQUE «DESPERDICIE» MÁS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Destapar un frasco que ya estaba por vencerse NO agrega riesgo: ese
 * frasco ya estaba perdido. Destapar uno fresco sí — pone en riesgo
 * mililitros que hasta ese momento estaban seguros. Por eso un frasco de
 * 80 que vence en diez días le gana al de 120 que vence en seis meses,
 * aunque el de 120 «calce» mejor: el sobrante del primero es aparente y
 * el del segundo es real.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS REGLAS QUE NO HACE FALTA ESCRIBIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Primero el frasco ya abierto» y «cerrar exacto cuando se pueda» no
 * están programadas como preferencias: **salen solas del objetivo**.
 * Servir de lo ya destapado agrega cero volumen nuevo en riesgo, y una
 * combinación exacta deja cero, que es el mínimo posible. Las dos ganan
 * porque son las mejores, no porque estén escritas antes.
 *
 * De ahí sale además la invariante de la que depende `EnvaseDisponible`:
 * como lo destapado siempre se agota primero, **nunca hay dos frascos
 * abiertos del mismo lote**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ NO MIRA EL PRECIO, A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los envases tienen precios por mililitro distintos, así que elegir
 * frascos mueve lo que paga el paciente. Un repartidor que optimizara la
 * cuenta —para arriba o para abajo— sería una decisión de precios que
 * nadie pidió, tomada en el lugar donde nadie la va a buscar. Acá se
 * elige por vencimiento y por desperdicio; el precio es consecuencia.
 */
final class RepartidorDeEnvases
{
    /**
     * Tope de exploración de la búsqueda.
     *
     * Con un puñado de presentaciones no se acerca ni de lejos, pero un
     * catálogo mal cargado —cien presentaciones de un mililitro— no puede
     * colgar una dispensación. Si se llega al tope, se devuelve la mejor
     * combinación encontrada hasta ahí, que siempre existe.
     */
    private const TOPE_DE_BUSQUEDA = 20000;

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 `$envaseEntero`: EL FRASCO ES DEL PACIENTE PORQUE LO PAGÓ
     * ─────────────────────────────────────────────────────────────────
     *
     * Para ciertos medicamentos —los líquidos que se entregan— no se
     * factura lo que se tomó: se factura el envase. «15 ml cada 6 horas
     * por 2 días» son 120 ml, y esos 120 ml salen como UN frasco de 120
     * o DOS de 60, enteros. Si la receta pedía 100, el paciente igual se
     * lleva el frasco: lo pagó, y él decide qué hace con lo que sobre.
     *
     * ⚠️ Es lo contrario del modo normal, y las dos reglas no pueden
     * mezclarse sobre el mismo producto: si al primero se le cobra el
     * frasco entero y al siguiente se le cobran los mililitros que
     * sobraron, la misma gota se cobró dos veces. Por eso la marca es del
     * PRODUCTO y no de la dispensación.
     *
     * En este modo no se sirve de un frasco abierto ni se destapa
     * ninguno: no queda sobrante que rastrear, y la invariante del
     * «único frasco abierto» simplemente no tiene caso.
     *
     * @param list<EnvaseDisponible> $disponibles
     *
     * @return list<TomaDeEnvase>
     */
    public function repartir(Decimal $pedido, array $disponibles, bool $envaseEntero = false): array
    {
        $pendiente = $pedido;
        $tomas = [];

        foreach ($this->porVencimiento($disponibles) as $grupo) {
            if (! $pendiente->mayorQue('0')) {
                break;
            }

            $capacidad = $this->capacidad($grupo, $envaseEntero);

            if (! $capacidad->mayorQue('0')) {
                continue;
            }

            /*
             * Si el grupo entero no alcanza, se lleva completo y se sigue
             * con el siguiente vencimiento. No hay nada que elegir: todo
             * lo que vence ese día se consume, que es exactamente lo que
             * se quería.
             */
            if (! $capacidad->mayorQue($pendiente)) {
                foreach ($grupo as $envase) {
                    $seLleva = $envaseEntero ? $this->soloLoSellado($envase) : $envase->saldo;

                    if ($seLleva->mayorQue('0')) {
                        $tomas[] = new TomaDeEnvase($envase->clave, $seLleva);
                    }
                }

                $pendiente = $pendiente->restar($capacidad);

                continue;
            }

            foreach ($this->dentroDeUnaFecha($grupo, $pendiente, $envaseEntero) as $toma) {
                $tomas[] = $toma;
            }

            $pendiente = Decimal::cero();
        }

        return $tomas;
    }

    /**
     * @param list<EnvaseDisponible> $grupo
     *
     * @return list<TomaDeEnvase>
     */
    private function dentroDeUnaFecha(array $grupo, Decimal $pendiente, bool $envaseEntero = false): array
    {
        $tomas = [];

        /*
         * a) Lo ya destapado y lo que vino a granel. No rompe nada nuevo,
         *    así que siempre es la mejor primera cucharada.
         *
         * ⚠️ Salvo en modo envase entero: ahí un frasco abierto no es de
         *    este paciente —es de otro que ya lo pagó— y a granel no hay
         *    envase que entregar.
         */
        $yaDestapados = $envaseEntero ? [] : $grupo;

        foreach ($yaDestapados as $envase) {
            if (! $pendiente->mayorQue('0')) {
                break;
            }

            $abierto = $envase->abierto();

            if (! $abierto->mayorQue('0')) {
                continue;
            }

            $toma = $abierto->menorQue($pendiente) ? $abierto : $pendiente;

            $tomas[] = new TomaDeEnvase($envase->clave, $toma);
            $pendiente = $pendiente->restar($toma);
        }

        if (! $pendiente->mayorQue('0')) {
            return $tomas;
        }

        // b) De los cerrados, la combinación que deje menos afuera.
        $combinacion = $this->mejorCombinacion($grupo, $pendiente);

        /*
         * c) Se consumen de mayor a menor. Solo el último puede quedar a
         *    medias, y ese es el que queda destapado.
         */
        foreach ($this->deMayorAMenor($grupo) as $envase) {
            if (! $pendiente->mayorQue('0')) {
                break;
            }

            $cuantos = $combinacion[$envase->clave] ?? 0;

            if ($cuantos < 1 || ! $envase->tieneEnvase()) {
                continue;
            }

            /** @var Decimal $tamano */
            $tamano = $envase->tamano;
            $elegido = $tamano->por($cuantos);

            /*
             * Acá está toda la diferencia entre los dos modos: en el
             * normal el último frasco se parte en lo que hacía falta y
             * queda destapado; en envase entero se entrega completo y no
             * se destapa nada.
             */
            if ($envaseEntero) {
                $toma = $elegido;
                $destapa = false;
            } else {
                $toma = $elegido->menorQue($pendiente) ? $elegido : $pendiente;
                $destapa = $toma->menorQue($elegido);
            }

            $tomas[] = new TomaDeEnvase($envase->clave, $toma, $destapa);
            $pendiente = $pendiente->restar($toma);
        }

        return $tomas;
    }

    /**
     * La mejor combinación de frascos CERRADOS: primero la que deje menos
     * sobrante, y entre las que empatan, la que use menos frascos.
     *
     * @param list<EnvaseDisponible> $grupo
     *
     * @return array<int, int> clave del envase => cuántos frascos
     */
    private function mejorCombinacion(array $grupo, Decimal $pendiente): array
    {
        $conFrascos = array_values(array_filter(
            $this->deMayorAMenor($grupo),
            fn (EnvaseDisponible $envase): bool => $envase->tieneEnvase() && $envase->sellados() > 0,
        ));

        if ($conFrascos === []) {
            return [];
        }

        $mejorSobrante = null;
        $mejorFrascos = 0;
        $mejorCombo = [];
        $pasos = 0;

        $buscar = function (int $i, Decimal $suma, int $frascos, array $combo) use (
            &$buscar,
            &$mejorSobrante,
            &$mejorFrascos,
            &$mejorCombo,
            &$pasos,
            $conFrascos,
            $pendiente,
        ): void {
            $pasos++;

            if ($pasos > self::TOPE_DE_BUSQUEDA) {
                return;
            }

            if (! $suma->menorQue($pendiente)) {
                $sobrante = $suma->restar($pendiente);

                $gana = ! $mejorSobrante instanceof Decimal
                    || $sobrante->menorQue($mejorSobrante)
                    || ($sobrante->igualA($mejorSobrante) && $frascos < $mejorFrascos);

                if ($gana) {
                    $mejorSobrante = $sobrante;
                    $mejorFrascos = $frascos;
                    $mejorCombo = $combo;
                }

                /*
                 * Ya se llegó: sumar otro frasco solo puede empeorar el
                 * sobrante. Cortar acá es lo que mantiene la búsqueda
                 * chica sin perder la mejor respuesta.
                 */
                return;
            }

            if ($i >= count($conFrascos)) {
                return;
            }

            $envase = $conFrascos[$i];

            /** @var Decimal $tamano */
            $tamano = $envase->tamano;
            $maximo = $envase->sellados();

            for ($n = 0; $n <= $maximo; $n++) {
                $sumaN = $suma->sumar($tamano->por($n));

                $siguiente = $combo;

                if ($n > 0) {
                    $siguiente[$envase->clave] = $n;
                }

                $buscar($i + 1, $sumaN, $frascos + $n, $siguiente);

                if (! $sumaN->menorQue($pendiente)) {
                    break;
                }
            }
        };

        $buscar(0, Decimal::cero(), 0, []);

        return $mejorCombo;
    }

    /**
     * @param list<EnvaseDisponible> $disponibles
     *
     * @return list<list<EnvaseDisponible>>
     */
    private function porVencimiento(array $disponibles): array
    {
        $ordenados = $disponibles;

        usort($ordenados, function (EnvaseDisponible $a, EnvaseDisponible $b): int {
            /*
             * Lo que no vence va al final: no urge, y ponerlo antes haría
             * que se consuma mientras un lote con fecha se echa a perder.
             */
            if ($a->vence === $b->vence) {
                return 0;
            }

            if ($a->vence === null) {
                return 1;
            }

            if ($b->vence === null) {
                return -1;
            }

            return $a->vence <=> $b->vence;
        });

        $grupos = [];

        foreach ($ordenados as $envase) {
            $grupos[$envase->vence ?? 'sin fecha'][] = $envase;
        }

        return array_values($grupos);
    }

    /**
     * @param list<EnvaseDisponible> $grupo
     *
     * @return list<EnvaseDisponible>
     */
    private function deMayorAMenor(array $grupo): array
    {
        $ordenados = $grupo;

        usort($ordenados, function (EnvaseDisponible $a, EnvaseDisponible $b): int {
            if (! $a->tieneEnvase() && ! $b->tieneEnvase()) {
                return 0;
            }

            if (! $a->tieneEnvase()) {
                return 1;
            }

            if (! $b->tieneEnvase()) {
                return -1;
            }

            /** @var Decimal $suyo */
            $suyo = $b->tamano;

            /** @var Decimal $mio */
            $mio = $a->tamano;

            return $suyo->comparar($mio);
        });

        return $ordenados;
    }

    /**
     * Cuánto puede dar este grupo.
     *
     * En modo envase entero solo cuenta lo SELLADO: lo destapado no se
     * puede entregar —es de otro paciente— y lo que vino a granel no
     * tiene envase que dar.
     *
     * @param list<EnvaseDisponible> $grupo
     */
    private function capacidad(array $grupo, bool $envaseEntero = false): Decimal
    {
        $total = Decimal::cero();

        foreach ($grupo as $envase) {
            $total = $total->sumar($envaseEntero ? $this->soloLoSellado($envase) : $envase->saldo);
        }

        return $total;
    }

    /**
     * Los mililitros que están en frascos cerrados: `sellados × tamaño`.
     * Lo que quedó en el destapado no entra.
     */
    private function soloLoSellado(EnvaseDisponible $envase): Decimal
    {
        if (! $envase->tieneEnvase()) {
            return Decimal::cero();
        }

        /** @var Decimal $tamano */
        $tamano = $envase->tamano;

        return $tamano->por($envase->sellados());
    }
}
