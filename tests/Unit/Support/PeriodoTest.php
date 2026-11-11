<?php

declare(strict_types=1);

use App\Support\Periodo;

/**
 * El período de un reporte se lee igual en la pantalla y en el papel
 * (11-nov-2026): las dos usan esta clase.
 */
it('lee un mes entero como el mes, un día como el día y lo demás como las dos puntas', function (): void {
    expect(Periodo::etiqueta('2026-11-01', '2026-11-30'))->toBe('Noviembre 2026')
        ->and(Periodo::etiqueta('2026-02-01', '2026-02-28'))->toBe('Febrero 2026')
        ->and(Periodo::etiqueta('2026-11-10', '2026-11-10'))->toBe('10/11/2026')
        ->and(Periodo::etiqueta('2026-11-01', '2026-11-15'))->toBe('01/11/2026 — 15/11/2026');
});

it('solo acepta fechas que existen', function (): void {
    expect(Periodo::fechaValida('2026-11-10'))->toBe('2026-11-10')
        ->and(Periodo::fechaValida('2026-02-31'))->toBeNull()
        ->and(Periodo::fechaValida('10/11/2026'))->toBeNull()
        ->and(Periodo::fechaValida(['2026-11-10']))->toBeNull()
        ->and(Periodo::fechaValida(null))->toBeNull();
});

it('cuenta los días con las dos puntas', function (): void {
    expect(Periodo::dias('2026-11-10', '2026-11-10'))->toBe(1)
        ->and(Periodo::dias('2026-11-01', '2026-11-30'))->toBe(30)
        ->and(Periodo::dias('2026-01-01', '2026-12-31'))->toBe(365);
});
