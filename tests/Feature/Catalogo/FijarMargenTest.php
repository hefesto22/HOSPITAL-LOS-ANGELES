<?php

declare(strict_types=1);

use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\MargenNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\MargenObjetivo;
use App\Services\FijadorDeMargenObjetivo;
use Carbon\Carbon;

function fijador(): FijadorDeMargenObjetivo
{
    return app(FijadorDeMargenObjetivo::class);
}

it('cierra el margen vigente el dia anterior y abre el nuevo', function (): void {
    $viejo = MargenObjetivo::factory()->vigenteEntre('2026-01-01')->del('1.2000')->create();

    $nuevo = fijador()->fijar(
        tipo: null,
        fraccion: Decimal::de('0.8'),
        motivo: 'Baja de margen por competencia en la zona.',
        desde: Carbon::parse('2026-09-01'),
    );

    expect($viejo->refresh()->vigencia_hasta?->toDateString())->toBe('2026-08-31')
        ->and($nuevo->vigencia_desde->toDateString())->toBe('2026-09-01')
        ->and($nuevo->vigencia_hasta)->toBeNull();
})->note('El viejo cierra el 31 y el nuevo abre el 1: `daterange(desde, hasta, \'[]\')` incluye los dos extremos, así que cerrarlo el mismo día los solaparía 24 horas y la base rechazaría el insert.');

it('el primer margen no tiene nada que cerrar', function (): void {
    $margen = fijador()->fijar(
        tipo: TipoItem::Medicamento,
        fraccion: Decimal::de('1.2'),
        motivo: 'Decisión de Mauricio: el margen nunca baja del 120 % en medicamentos.',
        desde: Carbon::parse('2026-08-17'),
    );

    expect(MargenObjetivo::query()->count())->toBe(1)
        ->and($margen->vigencia_hasta)->toBeNull();
});

it('guarda la fraccion y no el porcentaje', function (): void {
    $margen = fijador()->fijar(
        tipo: null,
        fraccion: Decimal::de('120')->entre('100'),
        motivo: 'Se escribe 120 en la pantalla y se guarda 1.2000 en la base.',
        desde: Carbon::parse('2026-08-17'),
    );

    expect($margen->porcentaje)->toBe('1.2000')
        ->and($margen->fraccion()->comoPorcentaje())->toBe('120 %');
})->note('La conversión pasa por Decimal y no por `/ 100` en float: 12.5 % dividido en punto flotante da 0.125000000000000006, y ese arrastre termina en el precio de cada producto del catálogo (§8.6.2).');

it('se niega a meter un margen antes de uno que ya existe', function (): void {
    MargenObjetivo::factory()->vigenteEntre('2026-06-01')->create();

    fijador()->fijar(
        tipo: null,
        fraccion: Decimal::de('0.9'),
        motivo: 'Un intento de reescribir el pasado.',
        desde: Carbon::parse('2026-03-01'),
    );
})->throws(MargenNoFijableException::class)
    ->note('Meter una fila en medio del historial obligaría a recortar rangos hacia los dos lados, y el precio de una venta que ya ocurrió pasaría a explicarse con una política que ese día no existía.');

it('se niega a fijar dos veces el mismo dia', function (): void {
    MargenObjetivo::factory()->vigenteEntre('2026-08-17')->create();

    fijador()->fijar(
        tipo: null,
        fraccion: Decimal::de('0.9'),
        motivo: 'El mismo día que el anterior.',
        desde: Carbon::parse('2026-08-17'),
    );
})->throws(MargenNoFijableException::class);

it('el default y el margen de un tipo son historiales independientes', function (): void {
    MargenObjetivo::factory()->vigenteEntre('2026-01-01')->del('0.8000')->create();

    $medicamentos = fijador()->fijar(
        tipo: TipoItem::Medicamento,
        fraccion: Decimal::de('1.2'),
        motivo: 'Medicamentos van al 120 %, el resto se queda donde está.',
        desde: Carbon::parse('2026-08-17'),
    );

    $default = MargenObjetivo::query()->whereNull('tipo_item')->sole();

    expect($default->vigencia_hasta)->toBeNull()
        ->and($medicamentos->tipo_item)->toBe(TipoItem::Medicamento);
})->note('Fijar el margen de medicamentos no puede cerrar el default: son dos escaleras distintas, y cerrarlo dejaría sin margen a todo lo que no es medicamento.');

it('deja el historial legible: cada precio viejo tiene su fila con fecha', function (): void {
    fijador()->fijar(null, Decimal::de('0.5'), 'Margen inicial de la instalación.', Carbon::parse('2026-01-01'));
    fijador()->fijar(null, Decimal::de('0.8'), 'Sube por costo de importación.', Carbon::parse('2026-05-01'));
    fijador()->fijar(null, Decimal::de('1.2'), 'Política de Mauricio del 17-ago-2026.', Carbon::parse('2026-08-17'));

    $enMarzo = MargenObjetivo::query()->vigentesEn(Carbon::parse('2026-03-15'))->sole();
    $enJunio = MargenObjetivo::query()->vigentesEn(Carbon::parse('2026-06-15'))->sole();
    $hoy = MargenObjetivo::query()->vigentesEn(Carbon::parse('2026-08-18'))->sole();

    expect($enMarzo->fraccion()->comoPorcentaje())->toBe('50 %')
        ->and($enJunio->fraccion()->comoPorcentaje())->toBe('80 %')
        ->and($hoy->fraccion()->comoPorcentaje())->toBe('120 %');
})->note('`sole()` y no `first()`: si algún día dos márgenes quedaran vigentes el mismo día, este test tiene que fallar en vez de elegir uno por orden de inserción.');
