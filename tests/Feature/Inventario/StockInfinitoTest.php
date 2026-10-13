<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\MovimientoKardex;
use App\Models\Tarifario;
use App\Services\AnuladorDeCargo;
use App\Services\CambiadorDeStockInfinito;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Stock infinito mientras se cuadra el inventario (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «debe haber una opción para colocarle que el stock es
| infinito, ya que en producción también se usará. No sabemos cuánto se
| tardarán en cuadrar el inventario; irán agregando stock de uno o dos
| productos y ahí desactivarán el infinito y pasarán al real».
|
| Lo que importa probar: que se carga sin existencia, que lo que SÍ hay
| sale primero (así, al apagarlo, la existencia ya es la real), que sin
| la bandera todo sigue igual, y que un controlado nunca la tiene.
*/

function unaCuentaDeStockInfinito(): Cuenta
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    return unaCuentaCon($contado);
}

function unMedicamentoDeStockInfinito(bool $infinito = true): Item
{
    $item = Item::factory()->medicamento()->create([
        'se_almacena'               => true,
        'stock_infinito'            => $infinito,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a('10.0000')->create();

    return $item;
}

/**
 * @param numeric-string $cantidad
 */
function recibirParaElStockInfinito(Item $item, Almacen $almacen, string $cantidad): void
{
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [new LineaRecibida(
            item: $item,
            presentacion: null,
            cantidadPresentacion: Decimal::de($cantidad),
            unidadesPorPresentacion: Decimal::de('1'),
            costoPorPresentacion: Decimal::de('5'),
            numeroLote: 'L-INF',
            vencimiento: now()->addYear(),
        )],
    );
}

/**
 * @param numeric-string $cantidad
 *
 * @return Collection<int, Cargo>
 */
function cargarConStockInfinito(Cuenta $cuenta, Item $item, string $cantidad, ?Almacen $almacen = null): Collection
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ));
}

it('🔴 sin existencia y sin estante elegido se carga igual, sin tocar el inventario', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();

    $cargos = cargarConStockInfinito($cuenta, $item, '3');

    expect($cargos)->toHaveCount(1)
        ->and($cargos->first()?->movimiento_id)->toBeNull()
        ->and($cargos->first()?->lote_id)->toBeNull()
        ->and($cargos->first()?->cantidad)->toBe('3.0000')
        ->and($cuenta->refresh()->total)->toBe('30.00')
        ->and(MovimientoKardex::query()->count())->toBe(0);
});

it('con un estante elegido que está vacío también se carga', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $cargos = cargarConStockInfinito($cuenta, $item, '2', $farmacia);

    expect($cargos)->toHaveCount(1)
        ->and($cargos->first()?->movimiento_id)->toBeNull()
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000');
});

it('🔴 lo que hay en el estante sale primero, y solo el resto va sin existencia', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    recibirParaElStockInfinito($item, $farmacia, '2');

    $cargos = cargarConStockInfinito($cuenta, $item, '5', $farmacia);

    $delEstante = $cargos->first(fn (Cargo $cargo): bool => $cargo->movimiento_id !== null);
    $sinExistencia = $cargos->first(fn (Cargo $cargo): bool => $cargo->movimiento_id === null);

    expect($cargos)->toHaveCount(2)
        ->and($delEstante?->cantidad)->toBe('2.0000')
        ->and($delEstante?->lote_id)->not->toBeNull()
        ->and($sinExistencia?->cantidad)->toBe('3.0000')
        ->and($sinExistencia?->lote_id)->toBeNull()
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000')
        ->and($cuenta->refresh()->total)->toBe('50.00');
})->note('Así, cuando se apague el infinito, la existencia ya es la de verdad: lo que había se descontó.');

it('🔴 sin stock infinito, lo que no hay sigue sin poder cargarse', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito(infinito: false);
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    expect(fn () => cargarConStockInfinito($cuenta, $item, '1', $farmacia))->toThrow(CargoException::class)
        ->and(fn () => cargarConStockInfinito($cuenta, $item, '1'))->toThrow(CargoException::class);
});

it('🔴 un estupefaciente o psicotrópico nunca queda con stock infinito', function (): void {
    $controlado = Item::factory()->controlado()->create([
        'se_almacena'    => true,
        'stock_infinito' => true,
    ]);

    expect($controlado->refresh()->stock_infinito)->toBeFalse()
        ->and($controlado->tieneStockInfinito())->toBeFalse()
        ->and(fn () => DB::table('items')->where('id', $controlado->id)->update(['stock_infinito' => true]))
        ->toThrow(QueryException::class);
})->note('§9.F11: su libro exige saldo corrido real ante ARSA. El modelo lo corrige y la base lo rechaza.');

it('anular un cargo sin existencia no devuelve nada al estante', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();

    $cargo = cargarConStockInfinito($cuenta, $item, '4')->firstOrFail();

    $reversa = app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGO AL PACIENTE EQUIVOCADO');

    expect($reversa->movimiento_id)->toBeNull()
        ->and(MovimientoKardex::query()->count())->toBe(0)
        ->and($cuenta->refresh()->total)->toBe('0.00');
});

it('🔴 apagar el infinito no traba rehacer lo que ya se vendió sin existencia', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();

    $cargo = cargarConStockInfinito($cuenta, $item, '4')->firstOrFail();

    $item->update(['stock_infinito' => false]);

    app(AnuladorDeCargo::class)->anular($cargo, 'SE REHACE CON EL DESCUENTO DEL HOSPITAL');

    $rehecho = app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item->refresh(),
        cantidad: Decimal::de('4'),
        claveIdempotencia: (string) Str::uuid(),
        mismoPrecioQue: $cargo,
    ))->firstOrFail();

    expect($rehecho->movimiento_id)->toBeNull()
        ->and($rehecho->cantidad)->toBe('4.0000');
})->note('Es lo que hace aplicar un descuento a toda la cuenta: anula y rehace. Si el original no sacó nada del estante, el rehecho tampoco.');

it('el cambio en bloque salta los controlados y dice cuántos', function (): void {
    $uno = unMedicamentoDeStockInfinito(infinito: false);
    $otro = unMedicamentoDeStockInfinito(infinito: false);
    $controlado = Item::factory()->controlado()->create(['se_almacena' => true]);

    $resultado = app(CambiadorDeStockInfinito::class)->cambiar([$uno, $otro, $controlado], true);

    expect($resultado)->toBe(['cambiados' => 2, 'controlados' => 1])
        ->and($uno->refresh()->stock_infinito)->toBeTrue()
        ->and($controlado->refresh()->stock_infinito)->toBeFalse();

    $deVuelta = app(CambiadorDeStockInfinito::class)->cambiar([$uno, $otro], false);

    expect($deVuelta)->toBe(['cambiados' => 2, 'controlados' => 0])
        ->and($otro->refresh()->stock_infinito)->toBeFalse();
});
