<?php

declare(strict_types=1);

use App\Console\Commands\CargarPreciosDeLaboratorio;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\Tarifario;

/*
|--------------------------------------------------------------------------
| El precio de lista del laboratorio, de las listas del 11-nov-2026
|--------------------------------------------------------------------------
|
| Mauricio: «esos son los precios de laboratorio y de laboratorio externo;
| hay que subirlos a pruebas y producción».
|
| El comando se corre en producción, donde ya hay facturas: lo que se
| prueba acá es que `--revisar` no escriba, que el L 10 de relleno se
| cierre en vez de pisarse, que lo que ya tiene ese precio no se toque,
| que no invente exámenes, y que los números sean los de las hojas.
|
| ⚠️ Los ayudantes tienen nombres propios: con `--parallel` dos iguales en
| archivos distintos chocan (§9.M5).
*/

function examenDeLaListaDeLaboratorio(string $codigo, string $nombre): Item
{
    return Item::factory()->create([
        'codigo'                    => $codigo,
        'nombre'                    => $nombre,
        'tipo'                      => TipoItem::EstudioLaboratorio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
        'se_almacena'               => false,
    ]);
}

/** El L 10 de `ListaPendiente`, puesto hace diez días. */
function conElPrecioDeRelleno(Item $item): Tarifario
{
    return Tarifario::factory()->delItem($item)->a('10.0000')->create([
        'motivo'         => 'PENDIENTE PRECIO DE LISTA — puesto por el seeder del catálogo.',
        'vigencia_desde' => now()->subDays(10)->toDateString(),
    ]);
}

function precioDeListaDeHoy(Item $item): ?string
{
    $fila = Tarifario::query()
        ->where('item_id', $item->id)
        ->whereNull('convenio_id')
        ->whereNull('sede_id')
        ->vigentesEn(now())
        ->first();

    return $fila instanceof Tarifario ? (string) $fila->precio : null;
}

it('🔴 con --revisar dice qué cambiaría y no escribe nada', function (): void {
    $hemograma = examenDeLaListaDeLaboratorio('LAB-028', 'HEMOGRAMA');
    conElPrecioDeRelleno($hemograma);

    $filas = Tarifario::query()->count();

    test()->artisan('precios:laboratorio', ['--revisar' => true])
        ->expectsOutputToContain('1 precio(s) cambiarían')
        ->assertSuccessful();

    expect(Tarifario::query()->count())->toBe($filas)
        ->and(precioDeListaDeHoy($hemograma))->toBe('10.0000');
})->note('En producción se corre primero así: código, examen, antes y ahora, sin tocar nada.');

it('🔴 carga el interno y el externo, cierra el relleno y no reabre lo que ya estaba igual', function (): void {
    $hemograma = examenDeLaListaDeLaboratorio('LAB-028', 'HEMOGRAMA');
    $relleno = conElPrecioDeRelleno($hemograma);

    $externo = examenDeLaListaDeLaboratorio('LABEXT-001', 'HEMOGRAMA (LAB. EXTERNO)');

    $electrolitos = examenDeLaListaDeLaboratorio('LABEXT-002', 'ELECTROLITOS EN ORINA');
    Tarifario::factory()->delItem($electrolitos)->a('6000.0000')->create([
        'motivo'         => 'Precio de la propuesta del Hospital Militar.',
        'vigencia_desde' => now()->subMonth()->toDateString(),
    ]);

    test()->artisan('precios:laboratorio')->assertSuccessful();

    expect(precioDeListaDeHoy($hemograma))->toBe('130.0000')
        ->and(precioDeListaDeHoy($externo))->toBe('175.0000')
        ->and(precioDeListaDeHoy($electrolitos))->toBe('6000.0000')
        ->and(Tarifario::query()->where('item_id', $electrolitos->id)->count())->toBe(1)
        ->and($relleno->fresh()?->vigencia_hasta?->toDateString())->toBe(now()->subDay()->toDateString());

    $filas = Tarifario::query()->count();

    test()->artisan('precios:laboratorio')
        ->expectsOutputToContain('0 precio(s) de lista de laboratorio cargados')
        ->assertSuccessful();

    expect(Tarifario::query()->count())->toBe($filas);
})->note('Por la misma puerta que «Bases de precios»: el de otro día se cierra ayer y el nuevo rige desde hoy (ADR-0003). Correrlo dos veces no abre filas de más.');

it('lo que no está en el catálogo se dice al final, no se inventa', function (): void {
    $items = Item::query()->count();

    test()->artisan('precios:laboratorio')
        ->expectsOutputToContain('181 examen(es) de la lista no están en el catálogo')
        ->expectsOutputToContain('TOXOPLASMOSIS')
        ->assertSuccessful();

    expect(Item::query()->count())->toBe($items);
})->note('181 = 48 del laboratorio del hospital + 47 de esos mismos afuera (ALCOHOLEMIA EN ORINA viene sin precio externo) + 86 que solo se hacen afuera.');

it('🔴 los números son los de las hojas, al centavo', function (): void {
    /** @var list<array{0: string, 1: string, 2: string|null}> $basicos */
    $basicos = (new ReflectionClassConstant(CargarPreciosDeLaboratorio::class, 'BASICOS'))->getValue();

    /** @var list<array{0: string, 1: string}> $soloAfuera */
    $soloAfuera = (new ReflectionClassConstant(CargarPreciosDeLaboratorio::class, 'SOLO_AFUERA'))->getValue();

    $adentro = Decimal::cero();
    $afuera = Decimal::cero();
    $otros = Decimal::cero();

    foreach ($basicos as [, $interno, $externo]) {
        $adentro = $adentro->sumar($interno);
        $afuera = $afuera->sumar($externo ?? '0');
    }

    foreach ($soloAfuera as [, $precio]) {
        $otros = $otros->sumar($precio);
    }

    expect(count($basicos))->toBe(48)
        ->and(count($soloAfuera))->toBe(86)
        ->and($adentro->redondeado(2))->toBe('15210.00')
        ->and($afuera->redondeado(2))->toBe('21780.00')
        ->and($otros->redondeado(2))->toBe('98160.00');
})->note('Sumas de «Laboratorios.xlsx» (hoja LABORATORIO 15,210; OTROS LABORATORIOS 98,160) y de «Laboratorios Externos.xlsx» (21,780).');
