<?php

declare(strict_types=1);

use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\FijadorDeCondicion;
use App\Services\ResolutorDePrecio;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

function resolverConPactado(): ResolutorDePrecio
{
    return app(ResolutorDePrecio::class);
}

function pactador(): FijadorDeCondicion
{
    return app(FijadorDeCondicion::class);
}

const DIA_FACTURADO = '2026-08-19';

/*
|--------------------------------------------------------------------------
| El peldaño del medio
|--------------------------------------------------------------------------
*/

it('el pagador con porcentaje pactado paga la lista multiplicada', function (): void {
    $item = Item::factory()->create();
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    Tarifario::factory()->delItem($item)->a('29.3300')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->conFactor('0.8500')->create();

    $precio = resolverConPactado()->para($item, $ihss, Carbon::parse(DIA_FACTURADO));

    expect($precio->precio)->toBeMonto('24.93')
        ->and($precio->origen)->toBe(OrigenDelPrecio::PorcentajePactado)
        ->and($precio->origen->esDerivado())->toBeTrue();
})->note('29.33 × 0.85 = 24.9305 → 24.93. Un solo factor cubre las dos mil filas del catálogo sin cargar dos mil precios.');

it('el precio negociado del item le gana al porcentaje pactado', function (): void {
    $item = Item::factory()->create();
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    Tarifario::factory()->delItem($item)->a('29.3300')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($ihss)->a('22.0000')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->conFactor('0.8500')->create();

    $precio = resolverConPactado()->para($item, $ihss, Carbon::parse(DIA_FACTURADO));

    expect($precio->precio)->toBeMonto('22.00')
        ->and($precio->origen)->toBe(OrigenDelPrecio::PrecioNegociado);
})->note('Es lo que hace que «las dos formas» convivan: el factor cubre el catálogo entero, y los pocos ítems negociados uno por uno lo pisan.');

it('sin condicion vigente el pagador paga la lista completa', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    Tarifario::factory()->delItem($item)->a('29.3300')->create();

    $precio = resolverConPactado()->para($item, $convenio, Carbon::parse(DIA_FACTURADO));

    expect($precio->precio)->toBeMonto('29.33')
        ->and($precio->origen)->toBe(OrigenDelPrecio::PrecioDeLista);
});

it('un convenio puede pagar por encima de la lista', function (): void {
    $item = Item::factory()->create();
    $militar = Convenio::factory()->create(['codigo' => 'MILITAR']);

    Tarifario::factory()->delItem($item)->a('29.3300')->create();
    ConvenioCondicion::factory()->delConvenio($militar)->conFactor('1.1000')->create();

    $precio = resolverConPactado()->para($item, $militar, Carbon::parse(DIA_FACTURADO));

    expect($precio->precio)->toBeMonto('32.26')
        ->and($precio->condicion?->resumen())->toBe('Paga 110 % de la lista (+10 %)');
})->note('Por eso se guarda lo que PAGA y no lo que descuenta: acá el descuento tendría que ser −10 %, y esa frase nadie la lee bien a la primera.');

it('el factor cae sobre la lista ya redondeada, no sobre los cuatro decimales', function (): void {
    $item = Item::factory()->create();
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    Tarifario::factory()->delItem($item)->a('10.5678')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->conFactor('0.9000')->create();

    $precio = resolverConPactado()->para($item, $ihss, Carbon::parse(DIA_FACTURADO));

    expect($precio->precio)->toBeMonto('9.51');
})->note('La lista se redondea a 10.57 y recién ahí se multiplica: 10.57 × 0.90 = 9.513 → 9.51. Sobre los cuatro decimales daría 9.51102 → 9.51 también acá, pero la regla del §4.5 es cobrar sobre el número que el paciente vio.');

/*
|--------------------------------------------------------------------------
| La fecha manda
|--------------------------------------------------------------------------
*/

it('la renovacion vieja explica la factura vieja', function (): void {
    $item = Item::factory()->create();
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    Tarifario::factory()->delItem($item)->a('100.0000')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-01-01', '2026-06-30')->conFactor('0.9000')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-07-01')->conFactor('0.8000')->create();

    $enMarzo = resolverConPactado()->para($item, $ihss, Carbon::parse('2026-03-15'));
    $hoy = resolverConPactado()->para($item, $ihss, Carbon::parse(DIA_FACTURADO));

    expect($enMarzo->precio)->toBeMonto('90.00')
        ->and($hoy->precio)->toBeMonto('80.00');
})->note('Si el factor viviera en una columna del convenio, renegociar habría borrado el 90 % y la factura de marzo no se podría explicar.');

/*
|--------------------------------------------------------------------------
| Lo que se puede contestar después
|--------------------------------------------------------------------------
*/

it('el precio derivado trae la condicion que lo explica', function (): void {
    $item = Item::factory()->create();
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    $lista = Tarifario::factory()->delItem($item)->a('29.3300')->create();
    $condicion = ConvenioCondicion::factory()->delConvenio($ihss)->conFactor('0.8500')->create();

    $precio = resolverConPactado()->para($item, $ihss, Carbon::parse(DIA_FACTURADO));

    expect($precio->fila->id)->toBe($lista->id)
        ->and($precio->condicion?->id)->toBe($condicion->id)
        ->and($precio->explicacion())->toContain('Paga 85 % de la lista (−15 %)');
})->note('L 24.93 no está escrito en ninguna fila. Sin la condición al lado, dentro de dos años nadie podría rehacer la multiplicación con el factor que regía ese día.');

/*
|--------------------------------------------------------------------------
| Pactar y renegociar
|--------------------------------------------------------------------------
*/

it('cierra la condicion vigente el dia anterior y abre la nueva', function (): void {
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    $vieja = ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-01-01')->conFactor('0.9000')->create();

    $nueva = pactador()->fijar(
        convenio: $ihss,
        factor: Decimal::de('85')->entre('100'),
        motivo: 'Renovación 2026-2 del convenio con el IHSS.',
        desde: Carbon::parse('2026-09-01'),
    );

    expect($vieja->refresh()->vigencia_hasta?->toDateString())->toBe('2026-08-31')
        ->and($nueva->factor_sobre_lista)->toBe('0.8500')
        ->and($nueva->vigencia_hasta)->toBeNull();
});

it('se niega a pactar antes de lo que ya se pacto', function (): void {
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-06-01')->create();

    pactador()->fijar(
        convenio: $ihss,
        factor: Decimal::de('0.9'),
        motivo: 'Un intento de reescribir la renovación anterior.',
        desde: Carbon::parse('2026-03-01'),
    );
})->throws(PrecioNoFijableException::class);

it('la base no deja dos condiciones vigentes del mismo convenio', function (): void {
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);

    ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-01-01')->create();
    ConvenioCondicion::factory()->delConvenio($ihss)->vigenteEntre('2026-06-01')->create();
})->throws(QueryException::class);

it('dos convenios distintos pueden tener condiciones a la vez', function (): void {
    $ihss = Convenio::factory()->create(['codigo' => 'IHSS']);
    $militar = Convenio::factory()->create(['codigo' => 'MILITAR']);

    ConvenioCondicion::factory()->delConvenio($ihss)->conFactor('0.8500')->create();
    ConvenioCondicion::factory()->delConvenio($militar)->conFactor('0.9500')->create();

    expect(ConvenioCondicion::query()->vigentesEn(Carbon::parse(DIA_FACTURADO))->count())->toBe(2);
});

it('la condicion que paga la lista completa se lee sin restas', function (): void {
    $condicion = ConvenioCondicion::factory()->conFactor('1.0000')->create();

    expect($condicion->resumen())->toBe('Paga la lista completa (100 %)');
});
