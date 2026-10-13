<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\PreciosDeLaFicha;
use App\Services\RegistradorDeCargo;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Los precios de un servicio, en su misma ficha (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «debe ser rápido de registrar, y se deben poder editar los
| precios desde acá también». Cambiar sigue siendo fijar con fecha: el de
| ayer se cierra y el nuevo rige desde hoy.
|
| ⚠️ Los ayudantes tienen nombres propios: los de Pest son funciones
| globales, y con `--parallel` dos con el mismo nombre chocan.
*/

function fichaDePrecios(): PreciosDeLaFicha
{
    return app(PreciosDeLaFicha::class);
}

function unServicioParaLaFicha(): Item
{
    return Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
    ]);
}

function unSeguroParaLaFicha(string $nombre): Convenio
{
    return Convenio::factory()->create(['nombre' => $nombre]);
}

/**
 * Lo que rige hoy para ese pagador, sin sede ni envase.
 */
function precioDeLaFichaHoy(Item $item, ?Convenio $convenio = null): ?string
{
    $fila = fichaDePrecios()->vigentes($item)[$convenio instanceof Convenio ? 'c'.$convenio->id : PreciosDeLaFicha::LISTA] ?? null;

    return $fila instanceof Tarifario ? Decimal::de($fila->precio)->redondeado(2) : null;
}

it('🔴 al dar de alta fija el de lista y el de cada seguro escrito; el vacío paga el de lista', function (): void {
    $item = unServicioParaLaFicha();
    $palig = unSeguroParaLaFicha('PALIG');
    $militar = unSeguroParaLaFicha('MILITAR');

    $cambiados = fichaDePrecios()->aplicar($item, [
        PreciosDeLaFicha::LISTA => '400',
        'c'.$palig->id          => '300,50',
        'c'.$militar->id        => '',
    ], null, esAlta: true);

    $lista = Tarifario::query()->where('item_id', $item->id)->whereNull('convenio_id')->firstOrFail();

    expect($cambiados)->toBe(['Precio de lista', 'PALIG'])
        ->and(precioDeLaFichaHoy($item))->toBe('400.00')
        ->and(precioDeLaFichaHoy($item, $palig))->toBe('300.50')
        ->and(precioDeLaFichaHoy($item, $militar))->toBeNull()
        ->and($lista->vigencia_desde->toDateString())->toBe($item->vigencia_desde->toDateString())
        ->and($lista->motivo)->toBe(PreciosDeLaFicha::MOTIVO_AL_CREAR);
})->note('El servicio nace con el precio de cada seguro: no queda el hueco «sin precio para este pagador» que se descubre con el paciente enfrente.');

it('sin precio de lista no se da de alta', function (): void {
    $item = unServicioParaLaFicha();

    expect(fn () => fichaDePrecios()->aplicar($item, [PreciosDeLaFicha::LISTA => ''], null, esAlta: true))
        ->toThrow(PrecioNoFijableException::class);
});

it('🔴 cambiar un precio cierra el de ayer y abre el de hoy; lo que no cambió no se toca', function (): void {
    $item = unServicioParaLaFicha();
    $palig = unSeguroParaLaFicha('PALIG');

    Tarifario::factory()->delItem($item)->a('400.0000')->create();
    $viejo = Tarifario::factory()->delItem($item)->paraElConvenio($palig)->a('300.0000')->create();

    $cambiados = fichaDePrecios()->aplicar($item, [
        PreciosDeLaFicha::LISTA => '400.00',
        'c'.$palig->id          => '350',
    ], 'tarifa nueva firmada', esAlta: false);

    $nuevo = Tarifario::query()->where('item_id', $item->id)->where('convenio_id', $palig->id)->whereNull('vigencia_hasta')->firstOrFail();

    expect($cambiados)->toBe(['PALIG'])
        ->and($viejo->refresh()->vigencia_hasta?->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($nuevo->vigencia_desde->toDateString())->toBe(now()->toDateString())
        ->and(Decimal::de($nuevo->precio)->redondeado(2))->toBe('350.00')
        ->and($nuevo->motivo)->toBe(PreciosDeLaFicha::MOTIVO_AL_EDITAR.': TARIFA NUEVA FIRMADA')
        ->and(Tarifario::query()->where('item_id', $item->id)->whereNull('convenio_id')->count())->toBe(1);
})->note('Las facturas de ayer siguen explicándose con L 300: cada cargo guarda su número, y la fila vieja queda.');

it('🔴 un error de tecleo del mismo día se corrige ahí mismo si nadie lo usó', function (): void {
    $item = unServicioParaLaFicha();
    $palig = unSeguroParaLaFicha('PALIG');

    Tarifario::factory()->delItem($item)->a('400.0000')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($palig)->a('300.0000')->create();

    fichaDePrecios()->aplicar($item, ['c'.$palig->id => '3000'], null, esAlta: false);
    fichaDePrecios()->aplicar($item, ['c'.$palig->id => '350'], null, esAlta: false);

    $deHoy = Tarifario::query()->where('item_id', $item->id)->where('convenio_id', $palig->id)->whereNull('vigencia_hasta')->firstOrFail();

    expect(Tarifario::query()->where('item_id', $item->id)->where('convenio_id', $palig->id)->count())->toBe(2)
        ->and(precioDeLaFichaHoy($item, $palig))->toBe('350.00')
        ->and($deHoy->motivo)->toStartWith('CORREGIDO EL MISMO DÍA, ANTES');
})->note('Sin esto, quien escribe 3000 en vez de 300 no podría corregirlo hasta mañana.');

it('🔴 si ya se cobró con el precio de hoy, no se corrige: espera a mañana', function (): void {
    $item = unServicioParaLaFicha();
    $cuenta = unaCuentaDeStockInfinito();

    Tarifario::factory()->delItem($item)->a('400.0000')->create();

    fichaDePrecios()->aplicar($item, [PreciosDeLaFicha::LISTA => '450'], null, esAlta: false);

    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ));

    expect(fn () => fichaDePrecios()->aplicar($item, [PreciosDeLaFicha::LISTA => '480'], null, esAlta: false))
        ->toThrow(PrecioNoFijableException::class)
        ->and(precioDeLaFichaHoy($item))->toBe('450.00');
})->note('Corregir esa fila cambiaría el número con el que se explica un cargo que ya existe.');

it('el centinela de L 10 no se muestra como precio, y escribir el de verdad lo reemplaza', function (): void {
    $item = unServicioParaLaFicha();

    Tarifario::factory()->delItem($item)->a('10.0000')->create([
        'motivo' => 'PENDIENTE PRECIO DE LISTA — EL HOSPITAL AÚN NO LO FIJÓ',
    ]);

    expect(fichaDePrecios()->estadoInicial($item)[PreciosDeLaFicha::LISTA])->toBe('')
        ->and(fichaDePrecios()->aplicar($item, [PreciosDeLaFicha::LISTA => ''], null, esAlta: false))->toBe([])
        ->and(fichaDePrecios()->aplicar($item, [PreciosDeLaFicha::LISTA => '500'], null, esAlta: false))->toBe(['Precio de lista'])
        ->and(precioDeLaFichaHoy($item))->toBe('500.00');
});

it('el convenio de contado no sale entre los pagadores: paga el precio de lista', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $palig = unSeguroParaLaFicha('PALIG');

    $claves = array_column(fichaDePrecios()->pagadores(), 'clave');

    expect($claves)->toBe([PreciosDeLaFicha::LISTA, 'c'.$palig->id])
        ->and($claves)->not->toContain('c'.$contado->id);
});

it('un número que no se entiende no fija nada, ni lo demás', function (): void {
    $item = unServicioParaLaFicha();
    $palig = unSeguroParaLaFicha('PALIG');

    Tarifario::factory()->delItem($item)->a('400.0000')->create();

    expect(fn () => fichaDePrecios()->aplicar($item, [
        PreciosDeLaFicha::LISTA => '420',
        'c'.$palig->id          => '3OO',
    ], null, esAlta: false))->toThrow(PrecioNoFijableException::class)
        ->and(precioDeLaFichaHoy($item))->toBe('400.00')
        ->and(precioDeLaFichaHoy($item, $palig))->toBeNull();
})->note('Todo o nada: si un precio no se puede fijar, no queda ninguno a medias.');
