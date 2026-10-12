<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;

/**
 * Precios de mostrador: siempre HACIA ARRIBA, por escalones.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · debajo de L 10   → al siguiente L 0.05  (1.81 → 1.85)
 *   · debajo de L 100  → al siguiente L 0.50  (15.95 → 16.00)
 *   · de L 100 arriba  → al siguiente L 1     (144.17 → 145)
 *
 * Los escalones son configuración (`sihla.precios.redondeo_de_lista`), no
 * constantes: otra clínica puede querer otros.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ NUNCA AL MÁS CERCANO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lista se calcula para que la cuarta edad, con su 40 % de ley, pague
 * exactamente el mínimo. Redondear hacia abajo la deja pagando menos que
 * el mínimo, y Mauricio fue claro: menos de ahí es pérdida. Hacia arriba,
 * el redondeo solo puede dejar un poco más.
 */
final class RedondeoDePrecio
{
    /**
     * Sin escalones configurados se sube al centavo: la lista igual tiene
     * que quedar en dos decimales, y hacia arriba sigue siendo seguro.
     */
    private const CENTAVO = '0.01';

    public function haciaArriba(Decimal $monto): Decimal
    {
        if (! $monto->mayorQue('0')) {
            return Decimal::cero();
        }

        return self::alSiguienteMultiplo($monto, $this->pasoPara($monto));
    }

    private function pasoPara(Decimal $monto): Decimal
    {
        $escalones = config('sihla.precios.redondeo_de_lista');

        foreach (is_array($escalones) ? $escalones : [] as $escalon) {
            if (! is_array($escalon) || ! is_string($escalon['a'] ?? null) || ! is_numeric($escalon['a'])) {
                continue;
            }

            $tope = $escalon['menor_que'] ?? null;

            if ($tope === null || (is_string($tope) && is_numeric($tope) && $monto->menorQue($tope))) {
                $paso = Decimal::de($escalon['a']);

                return $paso->mayorQue('0') ? $paso : Decimal::de(self::CENTAVO);
            }
        }

        return Decimal::de(self::CENTAVO);
    }

    /**
     * El primer múltiplo del paso que no queda debajo: 1.81 con paso 0.05
     * es 1.85, y 1.85 se queda en 1.85.
     */
    private static function alSiguienteMultiplo(Decimal $monto, Decimal $paso): Decimal
    {
        $abajo = Decimal::de(bcdiv($monto->exacto(), $paso->exacto(), 0))->por($paso);

        return $abajo->menorQue($monto) ? $abajo->sumar($paso) : $abajo;
    }
}
