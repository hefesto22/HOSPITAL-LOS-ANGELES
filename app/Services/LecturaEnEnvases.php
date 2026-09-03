<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;

/**
 * Cómo se lee una cantidad en envases: «1 BLISTER X 10 + 5 TAB».
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ES UNA LECTURA, NO UN SALDO. LA DIFERENCIA ES TODO.
 * ─────────────────────────────────────────────────────────────────────
 *
 * El kardex sigue en la unidad mínima —15 TAB son 15 TAB— y la
 * existencia sigue sin partirse por presentación. Lo único que hace esta
 * clase es TRADUCIR ese número a lo que la persona tiene enfrente en el
 * estante.
 *
 * Y esa distinción es la que hace que funcione. Un saldo por presentación
 * («quedan 97 en la caja abierta») exige que alguien teclee de qué envase
 * físico sacó cada tableta, y con varias personas despachando eso no pasa
 * nunca: a la semana el número es mentira y nadie lo mira. Una lectura no
 * se puede desincronizar porque **se calcula cada vez**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL REPARTO ES CODICIOSO Y NO ÓPTIMO
 * ─────────────────────────────────────────────────────────────────────
 *
 * De mayor a menor, y el resto en unidades sueltas. No busca la
 * combinación con menos envases ni nada parecido: la persona abre el
 * envase más grande que le sirva y sigue, que es lo que hace la mano.
 *
 * ⚠️ NO confundir con `RepartidorDeEnvases`, que resuelve otro problema:
 * de QUÉ frascos físicos sale un jarabe, mirando cuáles ya están
 * destapados y cuáles vencen antes. Eso decide inventario. Esto decide
 * cómo se escribe un número.
 *
 * ⚠️ `envase()` y no `etiqueta()`. La lectura va SIEMPRE al lado del
 * producto —debajo del campo de cantidad, con el nombre arriba— así que
 * repetirlo daría «1 ACETAMINOFEN 500 MG TABLETA BLISTER X 10 + 5 TAB»,
 * un renglón que hay que leer dos veces para encontrar el número.
 */
final class LecturaEnEnvases
{
    /**
     * La cantidad, leída en envases. Null cuando no hay nada útil que
     * decir — no hay presentaciones, o no alcanza ni para una.
     */
    public function de(Item $item, Decimal $cantidad): ?string
    {
        if (! $cantidad->mayorQue('0')) {
            return null;
        }

        $envases = $this->envasesDeMayorAMenor($item);

        if ($envases === []) {
            return null;
        }

        $resto = $cantidad;
        $partes = [];

        foreach ($envases as $envase) {
            $porEnvase = Decimal::de($envase->unidades_por_presentacion);

            if (! $porEnvase->mayorQue('1')) {
                continue;
            }

            $cuantos = (int) bcdiv($resto->redondeado(4), $porEnvase->redondeado(4), 0);

            if ($cuantos < 1) {
                continue;
            }

            $partes[] = $cuantos.' '.$envase->envase();
            $resto = $resto->restar($porEnvase->por((string) $cuantos));
        }

        /*
         * Si no entró en ningún envase, no hay nada que traducir: «5 TAB»
         * ya se lee solo, y repetirlo debajo del campo es ruido en el
         * lugar donde menos tiempo hay para leer.
         */
        if ($partes === []) {
            return null;
        }

        if ($resto->mayorQue('0')) {
            $partes[] = $this->sinCerosDeMas($resto->redondeado(4)).' '.$this->unidadDe($item);
        }

        return implode(' + ', $partes);
    }

    /**
     * Las presentaciones vigentes, de la más grande a la más chica.
     *
     * @return list<ItemPresentacion>
     */
    private function envasesDeMayorAMenor(Item $item): array
    {
        // array_values: Collection::all() está tipado array<TKey,TValue>, así
        // que ->values()->all() no le alcanza a Larastan para ver un list.
        return array_values(
            $item->presentaciones()
                ->vigentesEn(now())
                ->get()
                ->sortByDesc(fn (ItemPresentacion $envase): string => $envase->unidades_por_presentacion)
                ->all()
        );
    }

    private function unidadDe(Item $item): string
    {
        $unidad = $item->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : 'unidades';
    }

    private function sinCerosDeMas(string $numero): string
    {
        if (! str_contains($numero, '.')) {
            return $numero;
        }

        $limpio = rtrim(rtrim($numero, '0'), '.');

        return $limpio === '' ? '0' : $limpio;
    }
}
