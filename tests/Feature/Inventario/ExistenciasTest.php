<?php

declare(strict_types=1);

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\Lote;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeMovimiento;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function movimientos(): RegistradorDeMovimiento
{
    return app(RegistradorDeMovimiento::class);
}

function saldos(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/*
|--------------------------------------------------------------------------
| Las veinte cajas con dos vencimientos
|--------------------------------------------------------------------------
*/

it('un mismo item lleva dos lotes con vencimientos distintos', function (): void {
    $item = Item::factory()->medicamento()->create();
    $almacen = Almacen::factory()->create();

    $septiembre = Lote::factory()->delItem($item)->queVence('2026-09-01')->create(['numero' => 'L-SEP']);
    $octubre = Lote::factory()->delItem($item)->queVence('2026-10-01')->create(['numero' => 'L-OCT']);

    movimientos()->registrar($item, $septiembre, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));
    movimientos()->registrar($item, $octubre, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));

    expect(saldos()->totalEn($item, $almacen)->redondeado(0))->toBe('20')
        ->and($item->lotes()->count())->toBe(2)
        ->and(Item::query()->where('nombre', $item->nombre)->count())->toBe(1);
})->note('Veinte cajas con dos vencimientos son UN ítem con dos lotes. Duplicar el ítem rompería la búsqueda, el precio, los reportes y el detector de duplicados a la vez — y el médico receta el producto, no el lote.');

it('el mismo numero de lote puede repetirse entre productos distintos', function (): void {
    $uno = Item::factory()->medicamento()->create();
    $otro = Item::factory()->medicamento()->create();

    Lote::factory()->delItem($uno)->create(['numero' => 'AB-123']);
    $segundo = Lote::factory()->delItem($otro)->create(['numero' => 'AB-123']);

    expect($segundo->exists)->toBeTrue();
})->note('Dos laboratorios pueden usar la misma numeración, y eso no es error de nadie. Por eso el índice único es por ítem y no global.');

it('no deja dos lotes con el mismo numero en el mismo producto', function (): void {
    $item = Item::factory()->medicamento()->create();

    Lote::factory()->delItem($item)->create(['numero' => 'AB-123']);
    Lote::factory()->delItem($item)->create(['numero' => 'AB-123']);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| FEFO — sale primero lo que vence primero
|--------------------------------------------------------------------------
*/

it('ordena por vencimiento y no por orden de llegada', function (): void {
    $item = Item::factory()->medicamento()->create();
    $almacen = Almacen::factory()->create();

    $octubre = Lote::factory()->delItem($item)->queVence('2026-10-01')->create(['numero' => 'L-OCT']);
    $septiembre = Lote::factory()->delItem($item)->queVence('2026-09-01')->create(['numero' => 'L-SEP']);

    movimientos()->registrar($item, $octubre, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));
    movimientos()->registrar($item, $septiembre, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));

    $orden = saldos()->enOrdenFefo($item, $almacen)
        ->map(fn (Existencia $existencia): ?string => $existencia->lote?->numero)
        ->all();

    expect($orden)->toBe(['L-SEP', 'L-OCT']);
})->note('El de octubre entró PRIMERO. Con FIFO saldría primero y el de septiembre se vencería en el estante: el hospital paga esa pérdida dos veces, el producto y la baja.');

it('los lotes sin vencimiento salen al final', function (): void {
    $item = Item::factory()->medicamento()->create();
    $almacen = Almacen::factory()->create();

    $sinVencer = Lote::factory()->delItem($item)->sinVencimiento()->create(['numero' => 'SIN-VTO']);
    $vence = Lote::factory()->delItem($item)->queVence('2027-01-01')->create(['numero' => 'CON-VTO']);

    movimientos()->registrar($item, $sinVencer, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('5'));
    movimientos()->registrar($item, $vence, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('5'));

    $orden = saldos()->enOrdenFefo($item, $almacen)
        ->map(fn (Existencia $existencia): ?string => $existencia->lote?->numero)
        ->all();

    expect($orden)->toBe(['CON-VTO', 'SIN-VTO']);
})->note('No corren riesgo, así que no tienen por qué desplazar a los que sí. `NULLS LAST` en el ORDER BY.');

it('el lote agotado no aparece en la fila de despacho', function (): void {
    $item = Item::factory()->medicamento()->create();
    $almacen = Almacen::factory()->create();

    $vacio = Lote::factory()->delItem($item)->queVence('2026-09-01')->create(['numero' => 'VACIO']);
    $lleno = Lote::factory()->delItem($item)->queVence('2026-10-01')->create(['numero' => 'LLENO']);

    movimientos()->registrar($item, $vacio, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('5'));
    movimientos()->registrar($item, $vacio, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('5'));
    movimientos()->registrar($item, $lleno, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('5'));

    $orden = saldos()->enOrdenFefo($item, $almacen)
        ->map(fn (Existencia $existencia): ?string => $existencia->lote?->numero)
        ->all();

    expect($orden)->toBe(['LLENO']);
})->note('El saldo en cero se queda en la tabla —su kardex es historia que no se borra— pero no se ofrece para despachar.');

/*
|--------------------------------------------------------------------------
| Lo vencido
|--------------------------------------------------------------------------
*/

it('encuentra lo vencido que todavia esta en el estante', function (): void {
    $item = Item::factory()->medicamento()->create();
    $almacen = Almacen::factory()->create();

    $vencido = Lote::factory()->delItem($item)->queVence('2026-01-31')->create(['numero' => 'VIEJO']);
    $vigente = Lote::factory()->delItem($item)->queVence('2027-01-31')->create(['numero' => 'NUEVO']);

    movimientos()->registrar($item, $vencido, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('3'));
    movimientos()->registrar($item, $vigente, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('3'));

    $encontrados = saldos()->vencidosAl(Carbon::parse('2026-08-19'))
        ->map(fn (Existencia $existencia): ?string => $existencia->lote?->numero)
        ->all();

    expect($encontrados)->toBe(['VIEJO']);
})->note('Un medicamento vencido en el estante es un hallazgo de ARSA. El sistema tiene que poder listarlos sin que nadie recuerde revisarlos.');

it('cuenta bien los dias que faltan para vencer', function (): void {
    $lote = Lote::factory()->queVence('2026-09-01')->create();

    expect($lote->diasParaVencerDesde(Carbon::parse('2026-08-19')))->toBe(13)
        ->and($lote->diasParaVencerDesde(Carbon::parse('2026-09-15')))->toBe(-14)
        ->and($lote->estaVencidoAl(Carbon::parse('2026-09-15')))->toBeTrue()
        ->and($lote->estaVencidoAl(Carbon::parse('2026-08-19')))->toBeFalse();
})->note('Negativo cuando ya venció: quien lo lee necesita saber hace cuánto, no solo que pasó.');

/*
|--------------------------------------------------------------------------
| Las dos dispensaciones simultáneas del último frasco
|--------------------------------------------------------------------------
*/

it('el descuento condicional no deja pasar la segunda salida', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    movimientos()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('1'));

    /*
     * Esto reproduce el intercalado que produce la concurrencia: las dos
     * salidas parten del mismo estado leído —«hay 1»— y compiten por la
     * misma fila.
     *
     * Va con la misma sentencia que usa el registrador, ligando la
     * cantidad como parámetro: probar el mecanismo con otra consulta
     * sería probar otra cosa.
     */
    $descontarUno = fn (): int => DB::update(
        'update existencias set cantidad = cantidad - ? where item_id = ? and cantidad >= ?',
        ['1', $item->id, '1'],
    );

    $primera = $descontarUno();
    $segunda = $descontarUno();

    expect($primera)->toBe(1)
        ->and($segunda)->toBe(0)
        ->and(saldos()->totalEn($item, $almacen)->redondeado(0))->toBe('0');
})->note('La versión ingenua —leer, comparar, restar— deja pasar las dos: ambas leen «hay 1», ambas aprueban, y el estante queda vacío con el sistema diciendo que hay uno. Con la condición dentro del UPDATE, la segunda afecta cero filas.');

it('la base rechaza un saldo negativo aunque se esquive el servicio', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    movimientos()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('5'));

    Existencia::query()->where('item_id', $item->id)->update(['cantidad' => '-1']);
})->throws(QueryException::class)
    ->note('El CHECK `cantidad >= 0` es el segundo cinturón, por si la escritura no vino del registrador.');

it('la segunda salida real termina en excepcion, no en saldo negativo', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    movimientos()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('1'));
    movimientos()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('1'));
    movimientos()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('1'));
})->throws(ExistenciaInsuficienteException::class);

/*
|--------------------------------------------------------------------------
| Varios almacenes
|--------------------------------------------------------------------------
*/

it('cada almacen lleva su propia existencia', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $bodega = Almacen::factory()->create();
    $farmacia = Almacen::factory()->create();

    movimientos()->registrar($item, $lote, $bodega, TipoMovimiento::EntradaPorCompra, Decimal::de('100'));
    movimientos()->registrar($item, $lote, $farmacia, TipoMovimiento::EntradaPorCompra, Decimal::de('40'));

    expect(saldos()->totalEn($item, $bodega)->redondeado(0))->toBe('100')
        ->and(saldos()->totalEn($item, $farmacia)->redondeado(0))->toBe('40')
        ->and(saldos()->totalGlobal($item)->redondeado(0))->toBe('140');
})->note('Un mismo lote puede estar repartido: su vencimiento es el mismo en los dos lugares porque lo puso el laboratorio, pero la cantidad es de cada estante.');
