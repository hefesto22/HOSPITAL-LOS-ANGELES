<?php

declare(strict_types=1);

use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Services\CotizadorDePresupuesto;

/**
 * LA HOLGURA SE LLAMA COMO SE LE EXPLIQUE A LA FAMILIA.
 *
 * Mauricio, 12-oct-2026: «que se le pueda poner un nombre a voluntad».
 * IMPREVISTOS, MARGEN POR COMPLICACIONES: el colchón es el mismo, lo que
 * cambia es cómo se dice en el papel.
 *
 * Lo que este archivo cuida: que el nombre salga en MAYÚSCULAS y sin
 * espacios de sobra, que vacío vuelva al de siempre, y que un renglón
 * del catálogo NO se deje renombrar — dice lo que dice su ítem.
 */
it('agrega la holgura con el nombre que se le puso, en mayusculas y sin espacios de sobra', function (): void {
    $presupuesto = Presupuesto::factory()->create();

    $linea = app(CotizadorDePresupuesto::class)->agregarHolgura(
        presupuesto: $presupuesto,
        monto: '1500.00',
        texto: '  margen por   complicaciones ',
    );

    expect($linea->texto)->toBe('MARGEN POR COMPLICACIONES')
        ->and($linea->origen)->toBe(OrigenLineaPresupuesto::Holgura)
        ->and($linea->item_id)->toBeNull()
        ->and($linea->total)->toBe('1500.00');
});

it('sin nombre la holgura sale con el de siempre', function (?string $nombre): void {
    $presupuesto = Presupuesto::factory()->create();

    $linea = app(CotizadorDePresupuesto::class)->agregarHolgura(
        presupuesto: $presupuesto,
        monto: '800.00',
        texto: $nombre,
    );

    expect($linea->texto)->toBe(CotizadorDePresupuesto::HOLGURA_POR_DEFECTO);
})->with([
    'nulo'          => [null],
    'vacío'         => [''],
    'solo espacios' => ['    '],
    'una letra'     => ['x'],
]);

it('al ajustar se le puede cambiar el nombre a la holgura que puso la plantilla', function (): void {
    $linea = PresupuestoLinea::factory()->holgura('2000.00')->create();

    $ajustada = app(CotizadorDePresupuesto::class)->ajustarLinea(
        linea: $linea,
        cantidad: '1.0000',
        precioUnitario: '2500.0000',
        texto: 'imprevistos',
    );

    expect($ajustada->texto)->toBe('IMPREVISTOS')
        ->and($ajustada->total)->toBe('2500.00');
});

it('al ajustar se le puede cambiar el nombre a lo agregado a mano sin item', function (): void {
    $linea = PresupuestoLinea::factory()->create([
        'item_id' => null,
        'origen'  => OrigenLineaPresupuesto::Manual,
        'texto'   => 'HONORARIOS CIRUJANO',
    ]);

    $ajustada = app(CotizadorDePresupuesto::class)->ajustarLinea(
        linea: $linea,
        cantidad: '1.0000',
        texto: 'Honorarios cirujano Dr. López',
    );

    expect($ajustada->texto)->toBe('HONORARIOS CIRUJANO DR. LÓPEZ');
});

it('un renglon del catalogo no se deja renombrar aunque llegue un nombre', function (): void {
    $linea = PresupuestoLinea::factory()->create(['texto' => 'HEMOGRAMA COMPLETO']);

    $ajustada = app(CotizadorDePresupuesto::class)->ajustarLinea(
        linea: $linea,
        cantidad: '2.0000',
        texto: 'OTRA COSA',
    );

    expect($ajustada->seLePuedeCambiarElNombre())->toBeFalse()
        ->and($ajustada->texto)->toBe('HEMOGRAMA COMPLETO')
        ->and($ajustada->cantidad)->toBe('2.0000');
});

it('un nombre vacio al ajustar deja el que tenia', function (): void {
    $linea = PresupuestoLinea::factory()->holgura('500.00')->create(['texto' => 'IMPREVISTOS']);

    $ajustada = app(CotizadorDePresupuesto::class)->ajustarLinea(
        linea: $linea,
        cantidad: '1.0000',
        texto: '   ',
    );

    expect($ajustada->texto)->toBe('IMPREVISTOS');
});
