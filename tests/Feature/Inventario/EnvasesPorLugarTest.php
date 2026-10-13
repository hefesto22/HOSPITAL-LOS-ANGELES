<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\UbicacionException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Pages\Existencias;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\MovimientoKardex;
use App\Models\Tarifario;
use App\Models\UbicacionDeProducto;
use App\Models\Unidad;
use App\Models\User;
use App\Services\CambiadorDeStockInfinito;
use App\Services\FormasDeCobro;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use App\Services\UbicadorDeProducto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Qué envases hay en cada lugar, y mover por envase (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «cuando sea real, debe ingresar qué cantidad se está moviendo,
| y en qué presentación: caja o blíster. Puede ser que en un lugar solo
| haya blíster, o cajas, o unidades, o de todo. Si marca caja, por ende
| hay unidades y blíster. Aunque sean infinitos, hay que tenerlo en
| cuenta».
|
| ⚠️ Los ayudantes tienen nombres propios: los de Pest son funciones
| globales, y con `--parallel` dos con el mismo nombre chocan según cómo
| caiga el reparto.
*/

function elUbicadorDeEnvases(): UbicadorDeProducto
{
    return app(UbicadorDeProducto::class);
}

function unaUnidadParaLugares(string $codigo): Unidad
{
    $existente = Unidad::query()->where('codigo', $codigo)->first();

    return $existente instanceof Unidad
        ? $existente
        : Unidad::factory()->de(MagnitudDeMedida::Conteo)->create([
            'codigo'           => $codigo,
            'nombre'           => $codigo,
            'contiene_envases' => $codigo === 'CAJA',
        ]);
}

/**
 * Una tableta que viene en CAJA X 100, que trae BLISTER X 10.
 *
 * @return array{item: Item, caja: ItemPresentacion, blister: ItemPresentacion}
 */
function unProductoEnCajaYBlister(bool $infinito = true): array
{
    $item = Item::factory()->medicamento()->create([
        'se_almacena'               => true,
        'stock_infinito'            => $infinito,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'unidad_dispensacion_id'    => unaUnidadParaLugares('TAB')->id,
    ]);

    Tarifario::factory()->delItem($item)->a('10.0000')->create();

    $envase = fn (string $codigo, string $contenido): ItemPresentacion => ItemPresentacion::factory()
        ->conContenido($contenido, $codigo.' X '.(int) $contenido.' TABLETAS')
        ->create([
            'item_id'   => $item->id,
            'unidad_id' => unaUnidadParaLugares($codigo)->id,
        ]);

    return [
        'item'    => $item,
        'caja'    => $envase('CAJA', '100.0000'),
        'blister' => $envase('BLISTER', '10.0000'),
    ];
}

/**
 * @return array{farmacia: Almacen, carrito: Almacen, bodega: Almacen}
 */
function almacenesParaLugares(): array
{
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    return [
        'farmacia' => $farmacia,
        'carrito'  => Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['sede_id' => $farmacia->sede_id]),
        'bodega'   => Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create(['sede_id' => $farmacia->sede_id]),
    ];
}

/**
 * @param numeric-string $cuantos
 * @param numeric-string $contenido
 */
function recibirEnvasesParaLugares(Almacen $almacen, Item $item, ?ItemPresentacion $envase, string $cuantos, string $contenido, string $lote): void
{
    app(RegistradorDeRecepcion::class)->registrar(almacen: $almacen, lineas: [
        new LineaRecibida(
            item: $item,
            presentacion: $envase,
            cantidadPresentacion: Decimal::de($cuantos),
            unidadesPorPresentacion: Decimal::de($contenido),
            costoPorPresentacion: Decimal::de('100'),
            numeroLote: $lote,
            vencimiento: now()->addYear(),
        ),
    ]);
}

/**
 * Lo que manda la pantalla de cobro al cargar envases sin abrir.
 *
 * @param numeric-string $tabletas
 * @param numeric-string $envases
 *
 * @return Collection<int, Cargo>
 */
function cargarEnvaseDeLugar(Cuenta $cuenta, ItemPresentacion $envase, Almacen $almacen, string $tabletas, string $envases): Collection
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $envase->item,
        cantidad: Decimal::de($tabletas),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
        presentacion: $envase,
        envases: Decimal::de($envases),
    ));
}

/**
 * Un método privado de una pantalla: lo que se pinta o se aprieta, no
 * una API.
 */
function enLaPantallaDeLugares(string $pantalla, string $metodo, mixed ...$argumentos): mixed
{
    return (new ReflectionMethod($pantalla, $metodo))->invoke(app($pantalla), ...$argumentos);
}

it('🔴 donde hay cajas hay blísteres y unidades; donde hay blísteres, cajas no', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia, [$caja->id]);
    elUbicadorDeEnvases()->ubicar($item, $carrito, [$blister->id]);

    expect(elUbicadorDeEnvases()->sePuedeSacarDe($item, $farmacia, $caja))->toBeTrue()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $farmacia, $blister))->toBeTrue()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $farmacia))->toBeTrue()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $carrito, $blister))->toBeTrue()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $carrito, $caja))->toBeFalse()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $carrito))->toBeTrue();
})->note('Mauricio: «si marca caja, por ende hay unidades y blíster».');

it('marcar la caja es «de todo», y sin envases solo hay unidades sueltas', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia, [$caja->id, $blister->id]);
    elUbicadorDeEnvases()->ubicar($item, $carrito, []);

    $lugares = elUbicadorDeEnvases()->lugaresDe($item);

    expect($lugares[$farmacia->id])->toBeNull()
        ->and($lugares[$carrito->id])->toBe([])
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $carrito, $blister))->toBeFalse()
        ->and(elUbicadorDeEnvases()->sePuedeSacarDe($item, $carrito))->toBeTrue();
})->note('Lo marcado se guarda en su forma más corta: la caja ya trae el blíster, y con eso hay de todo.');

it('🔴 cambiar lo que hay cierra la fila y abre otra; repetirlo no cambia nada', function (): void {
    ['item' => $item, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia);

    expect(elUbicadorDeEnvases()->ubicar($item, $farmacia, [$blister->id]))->toBeTrue()
        ->and(elUbicadorDeEnvases()->ubicar($item, $farmacia, [$blister->id]))->toBeFalse();

    $filas = UbicacionDeProducto::query()->where('item_id', $item->id)->orderBy('id')->get();

    expect($filas)->toHaveCount(2)
        ->and($filas[0]->hasta)->not->toBeNull()
        ->and($filas[0]->motivo_cierre)->toBe(UbicadorDeProducto::MOTIVO_CAMBIO_LO_QUE_HAY)
        ->and($filas[1]->hasta)->toBeNull()
        ->and($filas[1]->de_todo)->toBeFalse();
})->note('Así «¿qué había en farmacia el sábado?» tiene respuesta: lo que dice una fila no se edita.');

it('🔴 la caja se carga solo de donde hay cajas', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia);
    elUbicadorDeEnvases()->ubicar($item, $carrito, [$blister->id]);

    expect(fn () => cargarEnvaseDeLugar($cuenta, $caja, $carrito, '100', '1'))->toThrow(CargoException::class);

    $deFarmacia = cargarEnvaseDeLugar($cuenta, $caja, $farmacia, '100', '1')->firstOrFail();
    $delCarrito = cargarEnvaseDeLugar($cuenta, $blister, $carrito, '10', '1')->firstOrFail();

    expect($deFarmacia->almacen_id)->toBe($farmacia->id)
        ->and($delCarrito->almacen_id)->toBe($carrito->id)
        ->and($delCarrito->movimiento_id)->toBeNull();
})->note('El historial dice de dónde salió cada caja, y es siempre un lugar donde había cajas.');

it('la pantalla de cobro ofrece la caja solo donde hay cajas', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia);
    elUbicadorDeEnvases()->ubicar($item, $carrito, [$blister->id]);

    $cajas = enLaPantallaDeLugares(CuentasAbiertas::class, 'estantesConLoQueHay', $item, FormasDeCobro::ENVASE.$caja->id);
    $blisteres = enLaPantallaDeLugares(CuentasAbiertas::class, 'estantesConLoQueHay', $item, FormasDeCobro::ENVASE.$blister->id);
    $sueltas = enLaPantallaDeLugares(CuentasAbiertas::class, 'estantesConLoQueHay', $item, FormasDeCobro::SUELTA);

    expect($cajas)->toBeArray()->toHaveKey($farmacia->id)
        ->and($cajas)->not->toHaveKey($carrito->id)
        ->and($blisteres)->toBeArray()->toHaveKeys([$farmacia->id, $carrito->id])
        ->and($sueltas)->toBeArray()->toHaveKeys([$farmacia->id, $carrito->id]);
});

it('🔴 llevar un blíster al carrito anota que ahí hay blísteres, no cajas', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $farmacia);

    expect(elUbicadorDeEnvases()->mover($item, $farmacia, $carrito, quedaEnOrigen: true, envase: $blister))->toBeTrue()
        ->and(elUbicadorDeEnvases()->lugaresDe($item)[$carrito->id])->toBe([$blister->id])
        ->and(elUbicadorDeEnvases()->mover($item, $farmacia, $carrito, quedaEnOrigen: true, envase: $blister))->toBeFalse()
        ->and(elUbicadorDeEnvases()->mover($item, $farmacia, $carrito, quedaEnOrigen: true))->toBeFalse()
        ->and(elUbicadorDeEnvases()->mover($item, $farmacia, $carrito, quedaEnOrigen: true, envase: $caja))->toBeTrue()
        ->and(elUbicadorDeEnvases()->lugaresDe($item)[$carrito->id])->toBeNull();
})->note('Llevar lo que ya había —otro blíster, unas tabletas— no cambia nada; llevar una caja, sí.');

it('no se lleva de un lugar un envase que ahí no hay', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $carrito, [$blister->id]);

    expect(fn () => elUbicadorDeEnvases()->mover($item, $carrito, $farmacia, quedaEnOrigen: true, envase: $caja))
        ->toThrow(UbicacionException::class);
});

it('llevarse todo pasa al destino lo que había en el origen', function (): void {
    ['item' => $item, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['carrito' => $carrito, 'bodega' => $bodega] = almacenesParaLugares();

    elUbicadorDeEnvases()->ubicar($item, $carrito, [$blister->id]);
    elUbicadorDeEnvases()->mover($item, $carrito, $bodega, quedaEnOrigen: false);

    expect(elUbicadorDeEnvases()->lugaresDe($item))->toBe([$bodega->id => [$blister->id]]);
});

it('🔴 al marcarlo infinito cada lugar queda con los envases que había enteros', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister(infinito: false);
    ['farmacia' => $farmacia, 'carrito' => $carrito, 'bodega' => $bodega] = almacenesParaLugares();

    recibirEnvasesParaLugares($farmacia, $item, $caja, '2', '100', 'L-CAJAS');
    recibirEnvasesParaLugares($bodega, $item, $blister, '3', '10', 'L-BLISTERES');
    recibirEnvasesParaLugares($carrito, $item, null, '5', '1', 'L-SUELTAS');

    app(CambiadorDeStockInfinito::class)->cambiar([$item], true);

    $lugares = elUbicadorDeEnvases()->lugaresDe($item->refresh());

    expect($lugares[$farmacia->id])->toBeNull()
        ->and($lugares[$bodega->id])->toBe([$blister->id])
        ->and($lugares[$carrito->id])->toBe([]);
})->note('Dos cajas cerradas son «de todo»; tres blísteres, solo blísteres; cinco tabletas sueltas, solo unidades.');

it('«Lugares» deja cada almacén con lo que se marcó', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister();
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    $formulario = UbicadorDeProducto::lugaresDelFormulario([
        ['almacen' => (string) $farmacia->id, 'envases' => [(string) $caja->id]],
        ['almacen' => $carrito->id, 'envases' => []],
        ['almacen' => 'cualquier cosa', 'envases' => [1]],
    ]);

    elUbicadorDeEnvases()->dejarConEnvases($item, $formulario);

    expect(elUbicadorDeEnvases()->lugaresDe($item))->toBe([$farmacia->id => null, $carrito->id => []]);

    elUbicadorDeEnvases()->dejarConEnvases($item, [$carrito->id => [$blister->id]]);

    expect(elUbicadorDeEnvases()->lugaresDe($item))->toBe([$carrito->id => [$blister->id]]);
});

it('🔴 en Existencias se mueven blísteres de una caja, enteros, y el kardex dice cuántos', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = unProductoEnCajaYBlister(infinito: false);
    ['farmacia' => $farmacia, 'carrito' => $carrito] = almacenesParaLugares();

    recibirEnvasesParaLugares($farmacia, $item, $caja, '1', '100', 'L-CAJA');

    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true, 'sede_id' => $farmacia->sede_id]);
    test()->actingAs($usuario);
    Gate::before(fn (): bool => true);

    $existencia = Existencia::query()->where('item_id', $item->id)->where('almacen_id', $farmacia->id)->firstOrFail();

    $formas = enLaPantallaDeLugares(Existencias::class, 'formasDelLote', $existencia, elUbicadorDeEnvases()->envasesDe($item));

    expect($formas)->toBeArray()
        ->and(array_column($formas, 'max', 'nombre'))->toMatchArray(['CAJA X 100' => '1', 'BLISTER X 10' => '10', 'TAB' => '100']);

    $traslado = [
        'existencia' => $existencia->id,
        'destino'    => $carrito->id,
        'forma'      => 'envase:'.$blister->id,
        'cantidad'   => '1.5',
    ];

    expect(enLaPantallaDeLugares(Existencias::class, 'trasladar', $item, $traslado, null))->toBeFalse()
        ->and(enLaPantallaDeLugares(Existencias::class, 'trasladar', $item, [...$traslado, 'cantidad' => '11'], null))->toBeFalse()
        ->and(elSaldoDe($item, $carrito))->toBe('0.0000');

    expect(enLaPantallaDeLugares(Existencias::class, 'trasladar', $item, [...$traslado, 'cantidad' => '2'], 'reposición'))->toBeTrue()
        ->and(elSaldoDe($item, $farmacia))->toBe('80.0000')
        ->and(elSaldoDe($item, $carrito))->toBe('20.0000')
        ->and(MovimientoKardex::query()->where('item_id', $item->id)->where('almacen_id', $carrito->id)->value('motivo'))
        ->toContain('2 BLISTER X 10');
})->note('Mauricio: «debe ingresar qué cantidad se está moviendo, y en qué presentación». Medio blíster no se mueve sin abrir: para eso están las tabletas sueltas.');
