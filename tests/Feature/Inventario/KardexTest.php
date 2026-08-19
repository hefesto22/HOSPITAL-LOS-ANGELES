<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeMovimiento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function kardex(): RegistradorDeMovimiento
{
    return app(RegistradorDeMovimiento::class);
}

function existencias(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/*
|--------------------------------------------------------------------------
| Entradas y salidas
|--------------------------------------------------------------------------
*/

it('una entrada suma y deja su linea en el kardex', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    $movimiento = kardex()->registrar(
        item: $item,
        lote: $lote,
        almacen: $almacen,
        tipo: TipoMovimiento::EntradaPorCompra,
        cantidad: Decimal::de('1000'),
        referencia: 'FAC-4471',
    );

    expect(existencias()->totalEn($item, $almacen)->redondeado(0))->toBe('1000')
        ->and($movimiento->cantidadDecimal()->redondeado(0))->toBe('1000')
        ->and($movimiento->saldoDespuesDecimal()->redondeado(0))->toBe('1000');
})->note('Dos escrituras en la misma transacción: mueve el saldo y asienta la línea. Separarlas es cómo aparece un saldo que no coincide con su propio kardex.');

it('una salida resta y se asienta en negativo', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('100'));

    $salida = kardex()->registrar(
        $item,
        $lote,
        $almacen,
        TipoMovimiento::SalidaPorDispensacion,
        Decimal::de('30')
    );

    expect(existencias()->totalEn($item, $almacen)->redondeado(0))->toBe('70')
        ->and($salida->cantidadDecimal()->redondeado(0))->toBe('-30')
        ->and($salida->cantidadAbsoluta()->redondeado(0))->toBe('30')
        ->and($salida->saldoDespuesDecimal()->redondeado(0))->toBe('70');
})->note('`cantidad` va con signo para que la existencia sea literalmente SUM(cantidad). La versión sin signo obliga a un CASE en cada consulta.');

it('el saldo siempre coincide con la suma del kardex', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('500'));
    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('120'));
    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorDevolucion, Decimal::de('20'));
    kardex()->registrar(
        $item,
        $lote,
        $almacen,
        TipoMovimiento::SalidaPorMerma,
        Decimal::de('5'),
        motivo: 'Se rompieron dos frascos al trasladarlos.'
    );

    expect(existencias()->totalEn($item, $almacen)->redondeado(4))
        ->toBe(existencias()->segunElKardex($item, $almacen)->redondeado(4));
})->note('La tabla de saldos es un caché; el kardex es la verdad. El día que no coincidan, el kardex gana y el saldo se recalcula — la historia no se ajusta al resumen.');

it('el kardex acepta fracciones', function (): void {
    $item = Item::factory()->medicamento()->fraccionable()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));
    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('0.5'));

    expect(existencias()->totalEn($item, $almacen)->redondeado(1))->toBe('9.5');
})->note('Medio mililitro es una dosis. Por eso la cantidad es NUMERIC(14,4) y nunca un entero.');

/*
|--------------------------------------------------------------------------
| Lo que el kardex no deja hacer
|--------------------------------------------------------------------------
*/

it('no deja sacar mas de lo que hay', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));
    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('11'));
})->throws(ExistenciaInsuficienteException::class);

it('el saldo no se mueve cuando la salida se rechaza', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('10'));

    try {
        kardex()->registrar($item, $lote, $almacen, TipoMovimiento::SalidaPorDispensacion, Decimal::de('11'));
    } catch (ExistenciaInsuficienteException) {
        // Es lo que se espera.
    }

    expect(existencias()->totalEn($item, $almacen)->redondeado(0))->toBe('10')
        ->and(MovimientoKardex::query()->count())->toBe(1);
})->note('El descuento es un UPDATE condicional: si no alcanza no afecta ninguna fila, así que no queda ni medio movimiento asentado.');

it('no deja mover una cantidad negativa', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();

    kardex()->registrar(
        $item,
        $lote,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('-5')
    );
})->throws(ExistenciaInsuficienteException::class)
    ->note('La cantidad se pasa siempre positiva: el signo lo pone el tipo. Permitir negativos es cómo aparece una dispensación que suma existencias.');

it('no deja un ajuste sin explicacion', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, $lote, $almacen, TipoMovimiento::AjustePositivo, Decimal::de('5'));
})->throws(ExistenciaInsuficienteException::class)
    ->note('Un ajuste sin motivo es la forma más limpia de tapar un faltante: el número cuadra y nadie sabe qué pasó. La base lo exige con un CHECK, además del servicio.');

it('no deja mover un medicamento sin lote', function (): void {
    $item = Item::factory()->medicamento()->create();

    kardex()->registrar(
        $item,
        null,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );
})->throws(ExistenciaInsuficienteException::class)
    ->note('Sin lote no hay forma de saber qué vence cuándo, ni de aplicar FEFO al dispensar.');

it('no deja usar el lote de otro producto', function (): void {
    $item = Item::factory()->medicamento()->create();
    $otro = Item::factory()->medicamento()->create();
    $loteAjeno = Lote::factory()->delItem($otro)->create();

    kardex()->registrar(
        $item,
        $loteAjeno,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );
})->throws(ExistenciaInsuficienteException::class)
    ->note('Un CHECK no puede mirar otra tabla, así que esta la verifica el servicio. El error se descubriría en el conteo físico, meses después.');

it('un insumo sin lote si se mueve sin lote', function (): void {
    $item = Item::factory()->de(
        TipoItem::Insumo,
        CategoriaLegalDeDescuento::SinDescuentoLegal,
    )->create(['requiere_lote' => false]);
    $almacen = Almacen::factory()->create();

    kardex()->registrar($item, null, $almacen, TipoMovimiento::EntradaPorCompra, Decimal::de('200'));

    expect(existencias()->totalEn($item, $almacen)->redondeado(0))->toBe('200');
})->note('Gasas y jeringas no llevan lote. El índice único usa COALESCE(lote_id, 0) para que no puedan convivir dos saldos del mismo insumo en el mismo almacén.');

/*
|--------------------------------------------------------------------------
| Append-only
|--------------------------------------------------------------------------
*/

it('el modelo se niega a editar un movimiento', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();

    $movimiento = kardex()->registrar(
        $item,
        $lote,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );

    $movimiento->update(['cantidad' => '999']);
})->throws(LogicException::class);

it('el modelo se niega a borrar un movimiento', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();

    $movimiento = kardex()->registrar(
        $item,
        $lote,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );

    $movimiento->delete();
})->throws(LogicException::class);

it('la base rechaza el UPDATE aunque se esquive el modelo', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();

    $movimiento = kardex()->registrar(
        $item,
        $lote,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );

    DB::table('movimientos_kardex')->where('id', $movimiento->id)->update(['cantidad' => '999']);
})->throws(QueryException::class)
    ->note('Este es el candado que manda: vale aunque la escritura venga de un comando, de tinker o de una consulta cruda. Un movimiento equivocado se corrige con otro movimiento.');

it('la base rechaza el DELETE aunque se esquive el modelo', function (): void {
    $item = Item::factory()->medicamento()->create();
    $lote = Lote::factory()->delItem($item)->create();

    $movimiento = kardex()->registrar(
        $item,
        $lote,
        Almacen::factory()->create(),
        TipoMovimiento::EntradaPorCompra,
        Decimal::de('10')
    );

    DB::table('movimientos_kardex')->where('id', $movimiento->id)->delete();
})->throws(QueryException::class)
    ->note('Si se pudiera borrar, la pregunta «¿dónde se fueron las 40 ampollas?» no tendría respuesta posible.');
