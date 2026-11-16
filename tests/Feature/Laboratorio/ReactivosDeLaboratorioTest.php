<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoItem;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Enums\TipoServicio;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\ConsumoDeCargo;
use App\Models\ConsumoDeServicio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\MovimientoKardex;
use App\Models\Servicio;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\AnuladorDeCargo;
use App\Services\GastosDelServicio;
use App\Services\RegistradorDeCargo;
use App\Support\AlmacenesDelUsuario;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Los kits del laboratorio (16-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «una caja de 25 kits es para 25 exámenes; se usa 1, quedan
| 24… cuando el stock es 0 no se pueden agregar más de esos exámenes en el
| hospital, pero sí de laboratorio externo».
|
| ⚠️ Ayudantes con nombre propio (`--parallel`).
*/

function elEstanteDelLaboratorio(int $sedeId): Almacen
{
    $servicio = Servicio::factory()->de(TipoServicio::Laboratorio)->create(['sede_id' => $sedeId]);

    return Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create([
        'sede_id'     => $sedeId,
        'servicio_id' => $servicio->id,
        'nombre'      => 'LABORATORIO',
    ]);
}

function unKitDePruebas(bool $infinito = false): Item
{
    return Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::SinDescuentoLegal)
        ->create(['se_almacena' => true, 'stock_infinito' => $infinito, 'nombre' => 'KIT GLUCOSA']);
}

function unExamenQueGasta(?Item $kit): Item
{
    $examen = Item::factory()->create([
        'tipo'                      => TipoItem::EstudioLaboratorio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
        'nombre'                    => $kit instanceof Item ? 'GLUCOSA' : 'GLUCOSA (LAB. EXTERNO)',
    ]);

    Tarifario::factory()->delItem($examen)->a('100.0000')->create();

    if ($kit instanceof Item) {
        ConsumoDeServicio::query()->create(['item_id' => $examen->id, 'insumo_id' => $kit->id, 'cantidad' => '1']);
    }

    return $examen;
}

function cargarElExamen(Cuenta $cuenta, Item $examen, string $veces = '1', ?Cargo $comoEste = null): Cargo
{
    $cargo = app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $examen,
        cantidad: Decimal::de($veces),
        claveIdempotencia: (string) Str::uuid(),
        mismoPrecioQue: $comoEste,
    ))->first();

    expect($cargo)->toBeInstanceOf(Cargo::class);

    /** @var Cargo $cargo */
    return $cargo;
}

it('🔴 cargar el examen saca una prueba del estante del laboratorio, y anularlo la devuelve', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $estante = elEstanteDelLaboratorio($cuenta->sede_id);
    $kit = unKitDePruebas();
    recibirParaElStockInfinito($kit, $estante, '25');

    $cargo = cargarElExamen($cuenta, unExamenQueGasta($kit));

    expect(elSaldoDe($kit, $estante))->toBe('24.0000');

    $gasto = ConsumoDeCargo::query()->where('cargo_id', $cargo->id)->sole();
    expect($gasto->sin_existencia)->toBeFalse()
        ->and($gasto->lote_id)->not->toBeNull()
        ->and(MovimientoKardex::query()->whereKey($gasto->movimiento_id)->value('tipo'))
        ->toBe(TipoMovimiento::SalidaPorConsumoDeServicio);

    app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGÓ EN LA CUENTA EQUIVOCADA');

    expect(elSaldoDe($kit, $estante))->toBe('25.0000')
        ->and($gasto->fresh()?->revertido_en)->not->toBeNull();
});

it('🔴 sin pruebas el examen de adentro no se carga; el de afuera sí', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $estante = elEstanteDelLaboratorio($cuenta->sede_id);
    $kit = unKitDePruebas();
    recibirParaElStockInfinito($kit, $estante, '1');
    $adentro = unExamenQueGasta($kit);

    cargarElExamen($cuenta, $adentro);

    expect(fn (): Cargo => cargarElExamen($cuenta, $adentro))->toThrow(CargoException::class)
        ->and(app(GastosDelServicio::class)->faltaPara($adentro, $cuenta->sede_id))->not->toBeNull();

    cargarElExamen($cuenta, unExamenQueGasta(null));

    expect(elSaldoDe($kit, $estante))->toBe('0.0000')
        ->and(Cargo::query()->where('cuenta_id', $cuenta->id)->count())->toBe(2);
});

it('dos del mismo examen en una línea gastan dos pruebas, y no alcanza con una', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $estante = elEstanteDelLaboratorio($cuenta->sede_id);
    $kit = unKitDePruebas();
    recibirParaElStockInfinito($kit, $estante, '3');
    $examen = unExamenQueGasta($kit);

    cargarElExamen($cuenta, $examen, '2');

    expect(elSaldoDe($kit, $estante))->toBe('1.0000')
        ->and(fn (): Cargo => cargarElExamen($cuenta, $examen, '2'))->toThrow(CargoException::class)
        ->and(elSaldoDe($kit, $estante))->toBe('1.0000');
});

it('con el kit en stock infinito solo se anota cuánto se usó', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    elEstanteDelLaboratorio($cuenta->sede_id);
    $kit = unKitDePruebas(infinito: true);

    $cargo = cargarElExamen($cuenta, unExamenQueGasta($kit));

    $gasto = ConsumoDeCargo::query()->where('cargo_id', $cargo->id)->sole();

    expect($gasto->sin_existencia)->toBeTrue()
        ->and($gasto->movimiento_id)->toBeNull()
        ->and($gasto->cantidad)->toBe('1.0000');
});

it('🔴 rehacer el cargo gasta lo mismo del mismo lote: el estante queda igual', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $estante = elEstanteDelLaboratorio($cuenta->sede_id);
    $kit = unKitDePruebas();
    recibirParaElStockInfinito($kit, $estante, '1');
    $examen = unExamenQueGasta($kit);

    $original = cargarElExamen($cuenta, $examen);
    app(AnuladorDeCargo::class)->anular($original, 'SE REHACE CON EL DESCUENTO DE LA CUENTA');
    $rehecho = cargarElExamen($cuenta, $examen, comoEste: $original);

    expect(elSaldoDe($kit, $estante))->toBe('0.0000')
        ->and(ConsumoDeCargo::query()->where('cargo_id', $rehecho->id)->value('lote_id'))
        ->toBe(ConsumoDeCargo::query()->where('cargo_id', $original->id)->value('lote_id'));
});

it('el estante del laboratorio recibe del proveedor; el carrito no', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $laboratorio = elEstanteDelLaboratorio($cuenta->sede_id);
    $carrito = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['sede_id' => $cuenta->sede_id]);

    $reciben = Almacen::query()->queRecibenDelProveedor()->pluck('id')->all();

    expect($laboratorio->recibeDelProveedor())->toBeTrue()
        ->and($carrito->recibeDelProveedor())->toBeFalse()
        ->and(in_array($laboratorio->id, $reciben, true))->toBeTrue()
        ->and(in_array($carrito->id, $reciben, true))->toBeFalse();
});

it('🔴 laboratorio ve y opera solo su estante; bodega sigue viendo todo', function (): void {
    test()->seed(RoleSeeder::class);

    $cuenta = unaCuentaDeStockInfinito();
    $laboratorio = elEstanteDelLaboratorio($cuenta->sede_id);
    $carrito = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['sede_id' => $cuenta->sede_id]);

    /** @var User $quimico */
    $quimico = User::factory()->create(['is_active' => true]);
    $quimico->assignRole('laboratorio');

    /** @var User $bodeguero */
    $bodeguero = User::factory()->create(['is_active' => true]);
    $bodeguero->assignRole('bodega');

    expect(AlmacenesDelUsuario::idsQueVe($quimico))->toBe([$laboratorio->id])
        ->and(AlmacenesDelUsuario::puedeOperarEn($laboratorio, $quimico))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($carrito, $quimico))->toBeFalse()
        ->and(AlmacenesDelUsuario::idsQueVe($bodeguero))->toBeNull()
        ->and(AlmacenesDelUsuario::puedeOperarEn($carrito, $bodeguero))->toBeTrue();
});
