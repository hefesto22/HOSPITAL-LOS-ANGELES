<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los turnos de caja que tiene el hospital, en el orden en que se suceden.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA LISTA Y UNA SOLA FORMA DE COMPARAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lista vive en `sihla.caja.turnos`. El usuario guarda el turno como
 * está escrito ahí («Turno A») y el turno de caja lo guarda en mayúsculas
 * («TURNO A»): se comparan siempre por acá, nunca con `===`, o la cajera
 * del «Turno A» no podría cerrar el «TURNO A» que le abrieron.
 */
final class NombresDeTurno
{
    /**
     * Los turnos en mayúsculas, en el orden de la config, sin repetidos.
     *
     * @return list<string>
     */
    public static function enOrden(): array
    {
        $enOrden = [];

        foreach (self::configurados() as $turno) {
            $canonico = TextoCanonico::mayusculas($turno);

            if ($canonico !== null && ! in_array($canonico, $enOrden, true)) {
                $enOrden[] = $canonico;
            }
        }

        return $enOrden;
    }

    /**
     * Para el selector del usuario: se guarda como está escrito en la
     * config, que es como ya están guardados los que existen.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::configurados() as $turno) {
            $opciones[$turno] = $turno;
        }

        return $opciones;
    }

    /**
     * «Turno A», «TURNO A» y « turno  a » son el mismo turno. Dos vacíos
     * no lo son: quien no tiene turno asignado no es «del mismo turno»
     * que un turno sin nombre.
     */
    public static function mismo(?string $uno, ?string $otro): bool
    {
        $uno = TextoCanonico::mayusculas($uno);

        return $uno !== null && $uno === TextoCanonico::mayusculas($otro);
    }

    /**
     * El que sigue al último que se abrió. Después del último de la lista
     * vuelve al primero; si el último tenía un nombre que ya no está en
     * la lista —o no hubo ninguno—, se arranca por el primero.
     *
     * `null` solo si el hospital no tiene turnos configurados.
     */
    public static function siguienteDe(?string $ultimo): ?string
    {
        $enOrden = self::enOrden();

        if ($enOrden === []) {
            return null;
        }

        $donde = array_search(TextoCanonico::mayusculas($ultimo), $enOrden, true);

        return $donde === false ? $enOrden[0] : $enOrden[($donde + 1) % count($enOrden)];
    }

    /**
     * @return list<string>
     */
    private static function configurados(): array
    {
        $configurados = config('sihla.caja.turnos');

        if (! is_array($configurados)) {
            return [];
        }

        $limpios = [];

        foreach ($configurados as $turno) {
            if (is_string($turno) && trim($turno) !== '') {
                $limpios[] = trim($turno);
            }
        }

        return $limpios;
    }
}
