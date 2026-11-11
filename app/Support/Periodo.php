<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Un período de reporte: dos fechas `Y-m-d`, validadas y en orden, y cómo
 * se leen.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ FUERA DEL TRAIT (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `ElijePeriodo` lo usan las páginas del panel. Los reportes impresos de
 * honorarios son rutas web que reciben las mismas fechas por la URL y
 * tienen que leerlas igual —«Noviembre 2026», no «01/11/2026 — 30/11/2026»—
 * y rechazar lo mismo. Si cada lado validara a su manera, la pantalla y
 * el papel podrían mostrar dos períodos distintos para el mismo enlace.
 * La regla vive acá y el trait la usa.
 */
final class Periodo
{
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * La fecha si es una fecha real en `Y-m-d`; si no, null. Lo que entra
     * lo escribe el navegador o viene pegado en una URL: un «2026-02-31»
     * no llega a una consulta.
     */
    public static function fechaValida(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) !== 1) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }

    /**
     * Las dos puntas, válidas y en orden. Sin fechas, el mes en curso; si
     * el «hasta» quedó antes del «desde», se dan vuelta en vez de devolver
     * un período vacío sin explicación.
     *
     * @return array{0: string, 1: string}
     */
    public static function de(mixed $desde, mixed $hasta): array
    {
        $desde = self::fechaValida($desde) ?? now()->startOfMonth()->toDateString();
        $hasta = self::fechaValida($hasta) ?? now()->endOfMonth()->toDateString();

        return $desde > $hasta ? [$hasta, $desde] : [$desde, $hasta];
    }

    /**
     * Cuántos días cubre, contando las dos puntas.
     */
    public static function dias(string $desde, string $hasta): int
    {
        return (int) CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) + 1;
    }

    /**
     * Cómo se lee: «Octubre 2026» si es un mes entero, una fecha si es un
     * solo día, o las dos puntas.
     */
    public static function etiqueta(string $desde, string $hasta): string
    {
        $inicio = CarbonImmutable::parse($desde);
        $fin = CarbonImmutable::parse($hasta);

        if ($inicio->day === 1 && $fin->isSameDay($inicio->endOfMonth())) {
            return ucfirst(self::MESES[$inicio->month]).' '.$inicio->year;
        }

        if ($inicio->isSameDay($fin)) {
            return $inicio->format('d/m/Y');
        }

        return $inicio->format('d/m/Y').' — '.$fin->format('d/m/Y');
    }
}
