<?php

declare(strict_types=1);

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\FijadorDePrecio;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

function fijadorDePrecio(): FijadorDePrecio
{
    return app(FijadorDePrecio::class);
}

it('cierra el precio vigente el dia anterior y abre el nuevo', function (): void {
    $item = Item::factory()->create();

    $viejo = Tarifario::factory()->delItem($item)->vigenteEntre('2026-01-01')->a('20.0000')->create();

    $nuevo = fijadorDePrecio()->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('29.33'),
        motivo: 'Sube el costo de importación del proveedor.',
        desde: Carbon::parse('2026-09-01'),
    );

    expect($viejo->refresh()->vigencia_hasta?->toDateString())->toBe('2026-08-31')
        ->and($nuevo->vigencia_desde->toDateString())->toBe('2026-09-01')
        ->and($nuevo->vigencia_hasta)->toBeNull()
        ->and($nuevo->monto())->toBeMonto('29.33');
})->note('Cerrar el viejo el mismo día en que arranca el nuevo los solaparía 24 horas: `daterange(desde, hasta, \'[]\')` incluye los dos extremos y la restricción de exclusión rechazaría el insert.');

it('el primer precio no tiene nada que cerrar', function (): void {
    $item = Item::factory()->create();

    $precio = fijadorDePrecio()->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('800.00'),
        motivo: 'Honorario de consulta especializada fijado por dirección.',
        desde: Carbon::parse('2026-08-19'),
    );

    expect(Tarifario::query()->count())->toBe(1)
        ->and($precio->vigencia_hasta)->toBeNull();
});

it('la lista y el precio del convenio son historiales independientes', function (): void {
    $item = Item::factory()->create();
    $aseguradora = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);

    $lista = Tarifario::factory()->delItem($item)->vigenteEntre('2026-01-01')->a('29.3300')->create();

    fijadorDePrecio()->fijar(
        item: $item,
        convenio: $aseguradora,
        sede: null,
        precio: Monto::de('25.00'),
        motivo: 'Precio negociado en la renovación del convenio.',
        desde: Carbon::parse('2026-09-01'),
    );

    expect($lista->refresh()->vigencia_hasta)->toBeNull();
})->note('Fijarle precio a la aseguradora no puede cerrar el de lista: son dos escaleras distintas, y cerrarlo dejaría sin precio a todo el que paga de su bolsillo.');

it('se niega a meter un precio antes de uno que ya existe', function (): void {
    $item = Item::factory()->create();

    Tarifario::factory()->delItem($item)->vigenteEntre('2026-06-01')->create();

    fijadorDePrecio()->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('10.00'),
        motivo: 'Un intento de reescribir el pasado.',
        desde: Carbon::parse('2026-03-01'),
    );
})->throws(PrecioNoFijableException::class)
    ->note('Meter una fila en medio del historial haría que una venta ya cobrada pase a explicarse con una tarifa que ese día no existía.');

it('se niega a fijar dos veces el mismo dia', function (): void {
    $item = Item::factory()->create();

    Tarifario::factory()->delItem($item)->vigenteEntre('2026-08-19')->create();

    fijadorDePrecio()->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('10.00'),
        motivo: 'El mismo dia que el anterior.',
        desde: Carbon::parse('2026-08-19'),
    );
})->throws(PrecioNoFijableException::class);

it('guarda el precio con cuatro decimales', function (): void {
    $item = Item::factory()->create();

    $precio = fijadorDePrecio()->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('29.33'),
        motivo: 'Precio derivado del costo por la calculadora.',
        desde: Carbon::parse('2026-08-19'),
    );

    expect($precio->refresh()->precio)->toBe('29.3300');
})->note('La columna lleva cuatro decimales porque un unitario de fracción —media ampolla, un mililitro— los necesita. Se muestran dos, se guardan cuatro.');

it('la base no deja dos precios de lista vigentes del mismo item', function (): void {
    $item = Item::factory()->create();

    Tarifario::factory()->delItem($item)->vigenteEntre('2026-01-01')->create();
    Tarifario::factory()->delItem($item)->vigenteEntre('2026-06-01')->create();
})->throws(QueryException::class)
    ->note('En SQL NULL = NULL no es verdadero, así que la exclusión va sobre COALESCE(convenio_id, 0) y COALESCE(sede_id, 0). Sin eso podrían convivir dos precios de lista y el cobro dependería del ORDER BY.');

it('deja el historial legible: cada factura vieja tiene su fila con fecha', function (): void {
    $item = Item::factory()->create();

    fijadorDePrecio()->fijar($item, null, null, Monto::de('20.00'), 'Precio de apertura del catálogo.', Carbon::parse('2026-01-01'));
    fijadorDePrecio()->fijar($item, null, null, Monto::de('25.00'), 'Ajuste por costo de importación.', Carbon::parse('2026-05-01'));
    fijadorDePrecio()->fijar($item, null, null, Monto::de('29.33'), 'Nueva política de margen del 120 %.', Carbon::parse('2026-08-17'));

    $enMarzo = Tarifario::query()->vigentesEn(Carbon::parse('2026-03-15'))->sole();
    $enJunio = Tarifario::query()->vigentesEn(Carbon::parse('2026-06-15'))->sole();
    $hoy = Tarifario::query()->vigentesEn(Carbon::parse('2026-08-19'))->sole();

    expect($enMarzo->monto())->toBeMonto('20.00')
        ->and($enJunio->monto())->toBeMonto('25.00')
        ->and($hoy->monto())->toBeMonto('29.33');
})->note('`sole()` y no `first()`: si algún día dos precios quedaran vigentes el mismo día, este test tiene que fallar en vez de elegir uno por orden de inserción.');
