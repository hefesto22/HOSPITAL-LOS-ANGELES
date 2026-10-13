<?php

declare(strict_types=1);

use App\Domain\Exceptions\FormasDeVentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Filament\Resources\Items\Schemas\FormasDeVenta;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Unidad;
use App\Services\FormasDeCobro;
use App\Services\RegistradorDeRecepcion;
use App\Services\SincronizadorDeFormasDeVenta;

/*
 * «FORMAS DE VENTA» EN LA FICHA DEL PRODUCTO (Mauricio, 11-oct-2026).
 *
 * «Tableta se sabe que es uno; al agregar blíster dirá de cuánto es el
 * blíster, y caja pues es el total de pastillas. Así en automático se
 * crean como se vende.» La ficha manda las filas y el servicio deja las
 * presentaciones como dicen, sin tocar lo que ya explica mercadería.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales, y con
 * `--parallel` dos con el mismo nombre en archivos distintos chocan.
 */
function envaseDeFormaDeVenta(string $codigo, bool $traeEnvases = false): Unidad
{
    $existente = Unidad::query()->where('codigo', $codigo)->first();

    return $existente instanceof Unidad
        ? $existente
        : Unidad::factory()->create([
            'codigo'           => $codigo,
            'nombre'           => $codigo,
            'contiene_envases' => $traeEnvases,
        ]);
}

function unProductoQueSeDispensaEn(string $suelta = 'TAB'): Item
{
    return Item::factory()->medicamento()->create([
        'unidad_dispensacion_id' => envaseDeFormaDeVenta($suelta)->id,
    ]);
}

/**
 * @param numeric-string $contenido
 *
 * @return array{presentacion_id: int|null, unidad_id: int, unidades_por_presentacion: string, principal: bool}
 */
function unaFormaDeVenta(string $envase, string $contenido, ?int $presentacion = null, bool $principal = false): array
{
    return [
        'presentacion_id'           => $presentacion,
        'unidad_id'                 => envaseDeFormaDeVenta($envase, $envase === 'CAJA')->id,
        'unidades_por_presentacion' => $contenido,
        'principal'                 => $principal,
    ];
}

/**
 * @param list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal?: bool}> $formas
 */
function guardarFormasDeVenta(Item $item, array $formas, bool $sueltaPrincipal = false): void
{
    app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, $formas, $sueltaPrincipal);
}

function laPrincipalDe(Item $item): ?ItemPresentacion
{
    return ItemPresentacion::query()
        ->where('item_id', $item->id)
        ->where('es_predeterminada', true)
        ->first();
}

function presentacionDeFormaDeVenta(Item $item, string $envase): ItemPresentacion
{
    return ItemPresentacion::query()
        ->where('item_id', $item->id)
        ->where('unidad_id', envaseDeFormaDeVenta($envase)->id)
        ->firstOrFail();
}

function entraUnaCajaDe(ItemPresentacion $caja): void
{
    app(RegistradorDeRecepcion::class)->registrar(almacen: Almacen::factory()->create(), lineas: [
        new LineaRecibida(
            item: $caja->item,
            presentacion: $caja,
            cantidadPresentacion: Decimal::de('1'),
            unidadesPorPresentacion: Decimal::de($caja->unidades_por_presentacion),
            costoPorPresentacion: Decimal::de('100'),
            numeroLote: 'L-FORMAS',
            vencimiento: now()->addYear(),
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| Se crean como se vende
|--------------------------------------------------------------------------
*/

it('🔴 la ficha crea el blister y la caja de una vez, con su nombre', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('BLISTER', '10'), unaFormaDeVenta('CAJA', '100')]);

    expect(presentacionDeFormaDeVenta($item, 'BLISTER')->nombre)->toBe('BLISTER X 10 TAB')
        ->and(presentacionDeFormaDeVenta($item, 'BLISTER')->unidades_por_presentacion)->toBe('10.0000')
        ->and(presentacionDeFormaDeVenta($item, 'CAJA')->nombre)->toBe('CAJA X 100 TAB')
        ->and(presentacionDeFormaDeVenta($item, 'CAJA')->es_predeterminada)->toBeTrue()
        ->and(presentacionDeFormaDeVenta($item, 'BLISTER')->es_predeterminada)->toBeFalse();
})->note('Antes era un modal por envase y recién con el producto guardado. La habitual es la más grande: la caja.');

it('guardar la ficha otra vez no duplica nada', function (): void {
    $item = unProductoQueSeDispensaEn();
    $formas = [unaFormaDeVenta('BLISTER', '10'), unaFormaDeVenta('CAJA', '100')];

    guardarFormasDeVenta($item, $formas);
    guardarFormasDeVenta($item, $formas);

    expect(ItemPresentacion::query()->where('item_id', $item->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| La principal
|--------------------------------------------------------------------------
*/

it('🔴 la estrella elige la principal aunque no sea la mas grande', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('BLISTER', '10', principal: true), unaFormaDeVenta('CAJA', '100')]);

    expect(laPrincipalDe($item)?->id)->toBe(presentacionDeFormaDeVenta($item, 'BLISTER')->id);
})->note('Mauricio: «¿cómo se selecciona cuál es el envase principal?». Con la estrella; la más grande es solo lo que queda si nadie elige.');

it('mover la estrella deja una sola principal', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('BLISTER', '10'), unaFormaDeVenta('CAJA', '100')]);
    $blister = presentacionDeFormaDeVenta($item, 'BLISTER');
    $caja = presentacionDeFormaDeVenta($item, 'CAJA');

    expect(laPrincipalDe($item)?->id)->toBe($caja->id);

    guardarFormasDeVenta($item, [
        unaFormaDeVenta('BLISTER', '10', $blister->id, principal: true),
        unaFormaDeVenta('CAJA', '100', $caja->id),
    ]);

    expect(laPrincipalDe($item)?->id)->toBe($blister->id)
        ->and(ItemPresentacion::query()->where('item_id', $item->id)->where('es_predeterminada', true)->count())->toBe(1);
});

it('🔴 la suelta puede ser la principal: el suero que se dispensa por bolsa y llega en caja', function (): void {
    $suero = unProductoQueSeDispensaEn('BOLSA');

    guardarFormasDeVenta($suero, [unaFormaDeVenta('CAJA', '12')], sueltaPrincipal: true);

    $alAbrir = FormasDeVenta::estadoDe($suero);

    expect(laPrincipalDe($suero))->toBeNull()
        ->and($alAbrir[FormasDeVenta::SUELTA_PRINCIPAL])->toBeTrue();
})->note('Mauricio: «en otros es el frasco, la bolsa de suero». La más grande sería la caja; la principal es la bolsa, que es la unidad suelta, así que ninguna presentación lleva la estrella.');

it('sin envases no hay nada que marcar', function (): void {
    $item = unProductoQueSeDispensaEn();

    expect(FormasDeVenta::estadoDe($item)[FormasDeVenta::SUELTA_PRINCIPAL])->toBeFalse()
        ->and(FormasDeVenta::estadoDe($item)[FormasDeVenta::CAMPO])->toBe([]);
});

it('una forma sin mercaderia se corrige y se quita', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('CAJA', '100')]);
    $caja = presentacionDeFormaDeVenta($item, 'CAJA');

    guardarFormasDeVenta($item, [unaFormaDeVenta('CAJA', '50', $caja->id)]);

    expect($caja->refresh()->unidades_por_presentacion)->toBe('50.0000')
        ->and($caja->nombre)->toBe('CAJA X 50 TAB');

    guardarFormasDeVenta($item, []);

    expect(ItemPresentacion::query()->where('item_id', $item->id)->count())->toBe(0)
        ->and(ItemPresentacion::withTrashed()->where('item_id', $item->id)->count())->toBe(1);
})->note('Una forma que nunca se usó no explica ningún lote ni ninguna factura: se puede corregir o quitar.');

it('🔴 una forma por la que ya entro mercaderia no se quita, y cambiarla la reemplaza desde hoy', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('CAJA', '100')]);
    $caja = presentacionDeFormaDeVenta($item, 'CAJA');

    entraUnaCajaDe($caja);

    expect(fn () => guardarFormasDeVenta($item, []))
        ->toThrow(FormasDeVentaException::class)
        ->and($caja->refresh()->trashed())->toBeFalse();

    $cambios = app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, [unaFormaDeVenta('CAJA', '50', $caja->id)]);

    $nueva = presentacionDeFormaDeVenta($item, 'CAJA');
    $lote = Lote::query()->where('item_id', $item->id)->sole();

    expect($caja->refresh()->trashed())->toBeTrue()
        ->and($caja->unidades_por_presentacion)->toBe('100.0000')
        ->and($caja->es_predeterminada)->toBeFalse()
        ->and($nueva->unidades_por_presentacion)->toBe('50.0000')
        ->and($nueva->es_predeterminada)->toBeTrue()
        ->and($lote->presentacion->unidades_por_presentacion)->toBe('100.0000')
        ->and($cambios->cambios)->toBe(['CAJA X 100 TAB PASÓ A CAJA X 50 TAB']);

    expect($nueva->id)->not->toBe($caja->id);
})->note('🔴 §9.G11: si la CAJA X 100 pasara a decir 50 en su fila, cada lote que entró en esa caja se leería con la mitad. Por eso se reemplaza: el lote sigue diciendo 100 (Mauricio, 13-oct-2026: «debería poder editarla»).');

it('la misma forma dos veces no se guarda', function (): void {
    $item = unProductoQueSeDispensaEn();

    expect(fn () => guardarFormasDeVenta($item, [unaFormaDeVenta('CAJA', '100'), unaFormaDeVenta('CAJA', '100.00')]))
        ->toThrow(FormasDeVentaException::class);

    expect(ItemPresentacion::query()->where('item_id', $item->id)->count())->toBe(0);
});

it('una forma sin envase o sin cuantas trae no se guarda', function (): void {
    $item = unProductoQueSeDispensaEn();

    expect(fn () => guardarFormasDeVenta($item, [[
        'presentacion_id'           => null,
        'unidad_id'                 => null,
        'unidades_por_presentacion' => '10',
    ]]))->toThrow(FormasDeVentaException::class)
        ->and(fn () => guardarFormasDeVenta($item, [[
            'presentacion_id'           => null,
            'unidad_id'                 => envaseDeFormaDeVenta('BLISTER')->id,
            'unidades_por_presentacion' => null,
        ]]))->toThrow(FormasDeVentaException::class);
});

/*
|--------------------------------------------------------------------------
| Lo que lee la ficha
|--------------------------------------------------------------------------
*/

it('la ficha abre con las formas de la mas chica a la mas grande y marca las usadas', function (): void {
    $item = unProductoQueSeDispensaEn();

    guardarFormasDeVenta($item, [unaFormaDeVenta('CAJA', '100'), unaFormaDeVenta('BLISTER', '10')]);
    entraUnaCajaDe(presentacionDeFormaDeVenta($item, 'CAJA'));

    $filas = array_values(FormasDeVenta::filasDe($item));

    expect($filas)->toHaveCount(2)
        ->and($filas[0]['unidad_id'])->toBe(envaseDeFormaDeVenta('BLISTER')->id)
        ->and($filas[0]['unidades_por_presentacion'])->toBe('10')
        ->and($filas[0]['usada'])->toBeFalse()
        ->and($filas[0]['principal'])->toBeFalse()
        ->and($filas[1]['unidad_id'])->toBe(envaseDeFormaDeVenta('CAJA')->id)
        ->and($filas[1]['usada'])->toBeTrue()
        ->and($filas[1]['principal'])->toBeTrue();
});

it('lo que manda la ficha llega limpio al servicio', function (): void {
    $formas = FormasDeVenta::paraGuardar([
        'a' => ['presentacion_id' => null, 'usada' => false, 'principal' => true, 'unidad_id' => '7', 'unidades_por_presentacion' => '100'],
        'b' => ['presentacion_id' => '12', 'usada' => true, 'unidad_id' => 3, 'unidades_por_presentacion' => ''],
        'c' => 'basura',
    ]);

    expect($formas)->toHaveCount(2)
        ->and($formas[0]['presentacion_id'])->toBeNull()
        ->and($formas[0]['unidad_id'])->toBe(7)
        ->and(Decimal::de((string) $formas[0]['unidades_por_presentacion'])->redondeado(4))->toBe('100.0000')
        ->and($formas[0]['principal'])->toBeTrue()
        ->and($formas[1]['presentacion_id'])->toBe(12)
        ->and($formas[1]['unidades_por_presentacion'])->toBeNull()
        ->and($formas[1]['principal'])->toBeFalse();
});

it('cuantos envases chicos trae uno grande', function (): void {
    $formas = app(FormasDeCobro::class);
    $caja = envaseDeFormaDeVenta('CAJA', true);
    $frasco = envaseDeFormaDeVenta('FRASCO');
    $blister = envaseDeFormaDeVenta('BLISTER');

    expect($formas->cuantosTrae($caja, Decimal::de('100'), $blister->id, Decimal::de('10'))?->redondeado(0))->toBe('10')
        ->and($formas->cuantosTrae($frasco, Decimal::de('100'), $blister->id, Decimal::de('10')))->toBeNull()
        ->and($formas->cuantosTrae($caja, Decimal::de('100'), $caja->id, Decimal::de('10')))->toBeNull()
        ->and($formas->cuantosTrae($caja, Decimal::de('100'), $blister->id, Decimal::de('14')))->toBeNull();
})->note('Es lo que la ficha escribe al lado: «1 CAJA = 100 TAB · trae 10 BLISTER». El frasco de tabletas no trae blísteres, la caja no trae cajas, y 100 entre 14 no da enteros.');
