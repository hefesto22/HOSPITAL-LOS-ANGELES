<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\RecepcionException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\MovimientoKardex;
use App\Services\AnuladorDeCargo;
use App\Services\CambiadorDeStockInfinito;
use App\Services\RegistradorDeCargo;
use Illuminate\Database\QueryException;
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
| Y la regla de cómo convive con el inventario: «mientras esté en
| infinito no se puede ingresar mercadería; se va tomando cuánto se va
| usando, pero solo para reportaje… y de dónde se sacó». Si tenía stock,
| pasa a infinito; si se le quita, arranca en cero.
|
| El reporte de lo que se usó se prueba en `ConsumoDeProductosTest`. Los
| ayudantes de los dos archivos viven en `tests/Pest.php`.
*/

it('🔴 sin estante elegido se carga igual, sin tocar el inventario', function (): void {
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

it('🔴 con un estante elegido no descuenta nada, pero deja anotado de dónde salió', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $cargos = cargarConStockInfinito($cuenta, $item, '2', $farmacia);

    expect($cargos)->toHaveCount(1)
        ->and($cargos->first()?->movimiento_id)->toBeNull()
        ->and($cargos->first()?->almacen_id)->toBe($farmacia->id)
        ->and(MovimientoKardex::query()->count())->toBe(0)
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000');
})->note('El almacén es lo que contesta «de dónde se sacó» en el reporte de consumo.');

it('🔴 al marcarlo infinito, lo que tenía se da de baja con su línea en el kardex', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    recibirParaElStockInfinito($item, $farmacia, '5');

    $resultado = app(CambiadorDeStockInfinito::class)->cambiar([$item], true);

    $baja = MovimientoKardex::query()
        ->where('item_id', $item->id)
        ->where('tipo', TipoMovimiento::AjusteNegativo->value)
        ->first();

    expect($resultado['cambiados'])->toBe(1)
        ->and($item->refresh()->stock_infinito)->toBeTrue()
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000')
        ->and($baja)->toBeInstanceOf(MovimientoKardex::class)
        ->and($baja?->cantidad)->toBe('-5.0000')
        ->and($baja?->motivo)->toBe(CambiadorDeStockInfinito::MOTIVO_AL_MARCAR);
})->note('Así un producto en infinito nunca tiene existencia: no hay dos números distintos diciendo cuánto hay.');

it('marcar otra vez uno que ya es infinito limpia lo que le haya quedado', function (): void {
    $item = unMedicamentoDeStockInfinito(infinito: false);
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    recibirParaElStockInfinito($item, $farmacia, '4');

    /*
     * Como quedaron los que se marcaron antes de esta regla: la bandera
     * puesta y la existencia viva.
     */
    DB::table('items')->where('id', $item->id)->update(['stock_infinito' => true]);

    $resultado = app(CambiadorDeStockInfinito::class)->cambiar([$item->refresh()], true);

    expect($resultado['cambiados'])->toBe(1)
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000');
});

it('🔴 mientras es infinito no se le recibe mercadería', function (): void {
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    expect(fn () => recibirParaElStockInfinito($item, $farmacia, '10'))->toThrow(RecepcionException::class)
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000');
});

it('🔴 al quitarle el infinito arranca en cero, y desde ahí se recibe y se descuenta', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    app(CambiadorDeStockInfinito::class)->cambiar([$item], false);

    expect($item->refresh()->stock_infinito)->toBeFalse()
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000')
        ->and(fn () => cargarConStockInfinito($cuenta, $item, '1', $farmacia))->toThrow(CargoException::class);

    recibirParaElStockInfinito($item, $farmacia, '3');

    $cargo = cargarConStockInfinito($cuenta, $item->refresh(), '2', $farmacia)->firstOrFail();

    expect($cargo->movimiento_id)->not->toBeNull()
        ->and(elSaldoDe($item, $farmacia))->toBe('1.0000');
});

it('🔴 quitar el infinito en bloque no toca lo que ya estaba en real', function (): void {
    $real = unMedicamentoDeStockInfinito(infinito: false);
    $infinito = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    recibirParaElStockInfinito($real, $farmacia, '7');

    $resultado = app(CambiadorDeStockInfinito::class)->cambiar([$real, $infinito], false);

    expect($resultado['cambiados'])->toBe(1)
        ->and(elSaldoDe($real, $farmacia))->toBe('7.0000')
        ->and($infinito->refresh()->stock_infinito)->toBeFalse();
})->note('La acción en bloque se aplica a una selección: lo contado no puede darse de baja por estar en ella.');

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

it('anular un cargo de stock infinito no devuelve nada al estante', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $cargo = cargarConStockInfinito($cuenta, $item, '4', $farmacia)->firstOrFail();

    $reversa = app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGO AL PACIENTE EQUIVOCADO');

    expect($reversa->movimiento_id)->toBeNull()
        ->and(MovimientoKardex::query()->count())->toBe(0)
        ->and(elSaldoDe($item, $farmacia))->toBe('0.0000')
        ->and($cuenta->refresh()->total)->toBe('0.00');
});

it('🔴 apagar el infinito no traba rehacer lo que ya se usó sin existencia', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $cargo = cargarConStockInfinito($cuenta, $item, '4', $farmacia)->firstOrFail();

    app(CambiadorDeStockInfinito::class)->cambiar([$item], false);

    app(AnuladorDeCargo::class)->anular($cargo, 'SE REHACE CON EL DESCUENTO DEL HOSPITAL');

    /*
     * Como lo rehace el descuento a toda la cuenta: con el almacén del
     * original, que ahora sí viene anotado, y un estante en cero.
     */
    $rehecho = app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item->refresh(),
        cantidad: Decimal::de('4'),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $cargo->almacen,
        mismoPrecioQue: $cargo,
    ))->firstOrFail();

    expect($rehecho->movimiento_id)->toBeNull()
        ->and($rehecho->almacen_id)->toBe($farmacia->id)
        ->and($rehecho->cantidad)->toBe('4.0000');
})->note('Si el original no sacó nada del estante, el rehecho tampoco, aunque el producto ya esté en real.');

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
