<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\Exceptions\TrasladoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Sede;
use App\Models\User;
use App\Services\CalculadoraDeCostoPromedio;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeMovimiento;
use App\Services\TrasladadorDeExistencias;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| LAS DIEZ AMPOLLAS QUE BAJAN AL CARRITO
|--------------------------------------------------------------------------
|
| Llegan 10 de fentanilo a BODEGA. Bajan 1 al CARRITO ROJO 1. El hospital
| sigue teniendo 10 — lo que cambió es dónde están.
|
| Todo lo que este archivo cuida se resume en esa frase: un traslado no
| crea ni destruye nada. Si alguna de estas pruebas se cae, o el inventario
| se está inflando o se está evaporando, y las dos se descubren meses
| después en un conteo físico.
*/

function trasladador(): TrasladadorDeExistencias
{
    return app(TrasladadorDeExistencias::class);
}

/**
 * Dos estantes de la MISMA sede, que es la única forma en que se
 * trasladan: el costo promedio y el kardex son de cada sede.
 *
 * @return array{bodega: Almacen, carrito: Almacen, item: Item, lote: Lote}
 */
function elEstanteYElCarrito(): array
{
    $sede = Sede::factory()->create();

    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create([
        'sede_id' => $sede->id,
        'codigo'  => 'BODEGA',
        'nombre'  => 'BODEGA',
    ]);

    $carrito = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create([
        'sede_id' => $sede->id,
        'codigo'  => 'CR1',
        'nombre'  => 'CARRITO ROJO 1',
    ]);

    $item = Item::factory()->medicamento()->create(['nombre' => 'FENTANILO 0.05 MG/ML']);
    $lote = Lote::factory()->delItem($item)->queVence('2027-06-30')->create(['numero' => 'F-2027']);

    return ['bodega' => $bodega, 'carrito' => $carrito, 'item' => $item, 'lote' => $lote];
}

/**
 * Deja `$cuantas` en bodega a `$costo` la unidad, como si hubieran
 * llegado del proveedor.
 *
 * @param array{bodega: Almacen, carrito: Almacen, item: Item, lote: Lote} $todo
 */
function llegaronABodega(array $todo, string $cuantas, string $costo = '120.00'): void
{
    app(CalculadoraDeCostoPromedio::class)->absorber(
        $todo['item'],
        $todo['bodega'],
        Decimal::de($cuantas),
        Decimal::de($costo),
    );

    app(RegistradorDeMovimiento::class)->registrar(
        item: $todo['item'],
        lote: $todo['lote'],
        almacen: $todo['bodega'],
        tipo: TipoMovimiento::EntradaPorCompra,
        cantidad: Decimal::de($cuantas),
        costoUnitario: Decimal::de($costo),
    );
}

/*
|--------------------------------------------------------------------------
| Lo que tiene que pasar
|--------------------------------------------------------------------------
*/

it('mover una ampolla al carrito no cambia cuantas tiene el hospital', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('1'),
    );

    $saldos = app(ConsultorDeExistencias::class);

    expect($saldos->totalEn($todo['item'], $todo['bodega'])->redondeado(0))->toBe('9')
        ->and($saldos->totalEn($todo['item'], $todo['carrito'])->redondeado(0))->toBe('1')
        ->and($saldos->totalGlobal($todo['item'])->redondeado(0))->toBe('10');
})->note('Un traslado mueve, no da de baja. Si el total del hospital cambiara, el sistema estaría diciendo que la ampolla se perdió o que apareció otra.');

it('el kardex de los dos estantes cuadra con su propio saldo', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('2'),
    );

    $saldos = app(ConsultorDeExistencias::class);

    foreach ([$todo['bodega'], $todo['carrito']] as $almacen) {
        expect($saldos->segunElKardex($todo['item'], $almacen)->redondeado(4))
            ->toBe(
                $saldos->totalEn($todo['item'], $almacen)->redondeado(4),
                "{$almacen->nombre} no cuadra con su propio kardex",
            );
    }
})->note('El kardex es la verdad y el saldo es su resumen. El día que no coincidan, gana el kardex — pero eso no puede empezar acá.');

it('las dos lineas quedan atadas por la misma referencia y dicen a donde fue', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    $asentado = trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('1'),
        motivo: 'Reposición del carro',
    );

    $salida = $asentado['salida'];
    $entrada = $asentado['entrada'];

    expect($salida->referencia)->toBe($entrada->referencia)
        ->and($salida->tipo)->toBe(TipoMovimiento::SalidaPorTraslado)
        ->and($entrada->tipo)->toBe(TipoMovimiento::EntradaPorTraslado)
        ->and($salida->motivo)->toContain('CARRITO ROJO 1')
        ->and($entrada->motivo)->toContain('BODEGA')
        ->and($salida->motivo)->toContain('Reposición del carro')
        ->and(MovimientoKardex::query()->where('referencia', $salida->referencia)->count())->toBe(2);
})->note('«¿A dónde fue esa ampolla?» se contesta leyendo el renglón, sin abrir otra pantalla. Y la referencia compartida devuelve el par completo aunque estén a miles de movimientos de distancia.');

it('el costo viaja con la mercaderia y el carrito no la valua en cero', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10', '120.00');

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('1'),
    );

    $costos = app(CalculadoraDeCostoPromedio::class);

    expect($costos->vigente($todo['item'], $todo['carrito'])->redondeado(2))->toBe('120.00')
        ->and($costos->vigente($todo['item'], $todo['bodega'])->redondeado(2))->toBe('120.00');
})->note('El promedio ponderado es POR almacén. Si el carrito recibiera la ampolla sin costo, el inventario del hospital perdería valor cada vez que algo se mueve de estante — y un traslado no es una donación.');

/*
|--------------------------------------------------------------------------
| Lo que NO tiene que pasar
|--------------------------------------------------------------------------
*/

it('no deja mover mas de lo que hay y el destino no recibe nada', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '2');

    expect(fn (): array => trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('5'),
    ))->toThrow(ExistenciaInsuficienteException::class);

    $saldos = app(ConsultorDeExistencias::class);

    expect($saldos->totalEn($todo['item'], $todo['bodega'])->redondeado(0))->toBe('2')
        ->and($saldos->totalEn($todo['item'], $todo['carrito'])->redondeado(0))->toBe('0');
})->note('🔴 Lo que se prueba acá no es el error: es que NO quedó nada a medias. Una entrada sin su salida es mercadería duplicada, y aparece como sobrante en el conteo del carrito.');

it('un traslado a si mismo se rechaza', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['bodega'],
        cantidad: Decimal::de('1'),
    );
})->throws(TrasladoException::class);

it('no se traslada entre sedes', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    $otraSede = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $otraSede,
        cantidad: Decimal::de('1'),
    );
})->throws(TrasladoException::class);

it('un almacen cerrado no recibe mercaderia', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    $todo['carrito']->update(['vigencia_hasta' => now()->subDay()->toDateString()]);

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito']->fresh() ?? $todo['carrito'],
        cantidad: Decimal::de('1'),
    );
})->throws(TrasladoException::class);

it('la cantidad de un traslado tiene que ser positiva', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::cero(),
    );
})->throws(TrasladoException::class);

/*
|--------------------------------------------------------------------------
| QUIÉN PUEDE MOVER QUÉ
|--------------------------------------------------------------------------
|
| El permiso protege contra el FALTANTE: que salga mercadería de un
| estante del que nadie responde. Ese riesgo vive entero en el ORIGEN, y
| por eso es lo único que se exige.
|
| Pedir también el destino sonaba más estricto y era peor: BODEGA →
| FARMACIA es el traslado de cada mañana, y nadie tiene los dos estantes
| salvo dirección. La reposición habría quedado esperando a alguien de
| dirección, y eso termina siempre igual — con el usuario de dirección
| abierto en una pestaña.
*/

/**
 * Un usuario activo con ese rol, autenticado, con los permisos frescos.
 */
function quienTraslada(string $rol): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    return $usuario->fresh() ?? $usuario;
}

/**
 * La farmacia de la misma sede que la bodega del escenario.
 *
 * @param array{bodega: Almacen, carrito: Almacen, item: Item, lote: Lote} $todo
 */
function laFarmaciaDeLaMismaSede(array $todo): Almacen
{
    return Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create([
        'sede_id' => $todo['bodega']->sede_id,
        'codigo'  => 'FARMACIA',
        'nombre'  => 'FARMACIA',
    ]);
}

it('bodega repone la farmacia aunque la farmacia no sea su estante', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    $farmacia = laFarmaciaDeLaMismaSede($todo);

    test()->actingAs(quienTraslada('bodega'));

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $farmacia,
        cantidad: Decimal::de('4'),
    );

    $saldos = app(ConsultorDeExistencias::class);

    expect($saldos->totalEn($todo['item'], $farmacia)->redondeado(0))->toBe('4')
        ->and($saldos->totalEn($todo['item'], $todo['bodega'])->redondeado(0))->toBe('6');
})->note('🔴 Es la prueba que impide volver a exigir permiso sobre el destino: bodega no tiene la farmacia en su lista, y este es el traslado que se hace todas las mañanas.');

it('bodega baja mercaderia a un carrito', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    test()->actingAs(quienTraslada('bodega'));

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $todo['carrito'],
        cantidad: Decimal::de('1'),
    );

    expect(app(ConsultorDeExistencias::class)->totalEn($todo['item'], $todo['carrito'])->redondeado(0))
        ->toBe('1');
})->note('Los carritos son de bodega: el carro de paro lo repone quien baja la mercadería, no el mostrador que dispensa.');

it('farmacia no puede sacar de la bodega', function (): void {
    $todo = elEstanteYElCarrito();
    llegaronABodega($todo, '10');

    $farmacia = laFarmaciaDeLaMismaSede($todo);

    test()->actingAs(quienTraslada('farmacia'));

    trasladador()->trasladar(
        item: $todo['item'],
        lote: $todo['lote'],
        origen: $todo['bodega'],
        destino: $farmacia,
        cantidad: Decimal::de('1'),
    );
})->throws(AjusteException::class)
    ->note('El origen sí se exige: sacar de un estante ajeno deja un faltante del que nadie responde. Farmacia pide la reposición; bodega la baja.');
