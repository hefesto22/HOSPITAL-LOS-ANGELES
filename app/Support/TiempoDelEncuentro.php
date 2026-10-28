<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\TipoEncuentro;
use Carbon\CarbonInterface;

/**
 * Cuánto lleva el paciente, como lo dice la tarjeta de cuentas abiertas
 * (Mauricio, 28-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * «HACE 1 MES» NO INFORMABA NADA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `diffForHumans()` redondea: todas las tarjetas decían «hace 1 mes», y
 * lo mismo daba un internado de 31 días que uno de 58. Ahora:
 *
 *   · Hospitalización: «Día 32». Es como lo cuenta el piso y lo que
 *     importa para cobrar la estancia. El día de ingreso es el día 1, y
 *     el día cambia a la hora de corte del censo
 *     (`sihla.operacion.hora_corte_censo`, §7.5-5): con el corte a las
 *     00:00, el que entró a las 23:50 está en el día 2 a las 00:10.
 *   · Lo demás (emergencia, consulta, cirugía, referido): minutos, horas
 *     o días, sin redondear a meses. Una emergencia de 30 horas dice
 *     «hace 1 día», y eso ya es una cuenta que alguien olvidó.
 */
final class TiempoDelEncuentro
{
    public static function paraLaTarjeta(TipoEncuentro $tipo, CarbonInterface $abiertoEn, CarbonInterface $ahora): string
    {
        if ($tipo->ocupaCama()) {
            return 'Día '.self::diaDeEstancia($abiertoEn, $ahora);
        }

        return self::haceCuanto($abiertoEn, $ahora);
    }

    /**
     * El día de estancia: el de ingreso es el 1.
     *
     * Se cuentan días de censo, no bloques de 24 horas: quien entró a las
     * 23:50 no espera hasta las 23:50 del día siguiente para estar en su
     * segundo día. Un reloj corrido hacia atrás no da «Día 0».
     */
    public static function diaDeEstancia(CarbonInterface $abiertoEn, CarbonInterface $ahora): int
    {
        $dias = (int) self::diaDeCenso($abiertoEn)->diffInDays(self::diaDeCenso($ahora), false);

        return max(1, $dias + 1);
    }

    /**
     * «Recién», «hace 40 min», «hace 3 h», «hace 2 días».
     */
    public static function haceCuanto(CarbonInterface $desde, CarbonInterface $ahora): string
    {
        $minutos = max(0, (int) $desde->diffInMinutes($ahora, false));

        if ($minutos < 1) {
            return 'Recién';
        }

        if ($minutos < 60) {
            return "hace {$minutos} min";
        }

        $horas = intdiv($minutos, 60);

        if ($horas < 24) {
            return "hace {$horas} h";
        }

        $dias = intdiv($horas, 24);

        return $dias === 1 ? 'hace 1 día' : "hace {$dias} días";
    }

    /**
     * El día de censo al que pertenece un momento: la fecha que tenía
     * hace «hora de corte» horas. Con el corte a las 06:00, las 03:00 del
     * martes todavía son el lunes.
     */
    private static function diaDeCenso(CarbonInterface $momento): CarbonInterface
    {
        [$horas, $minutos] = self::horaDeCorte();
        $zona = config('app.timezone');

        /*
         * La zona del hospital y no la del objeto: un instante que llegue
         * en UTC cae seis horas antes y cambiaría de día (§7.5).
         */
        return $momento->copy()
            ->setTimezone(is_string($zona) ? $zona : 'UTC')
            ->subHours($horas)
            ->subMinutes($minutos)
            ->startOfDay();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function horaDeCorte(): array
    {
        $corte = config('sihla.operacion.hora_corte_censo', '00:00');

        if (! is_string($corte) || preg_match('/^(\d{1,2}):(\d{2})$/', $corte, $partes) !== 1) {
            return [0, 0];
        }

        return [(int) $partes[1], (int) $partes[2]];
    }
}
