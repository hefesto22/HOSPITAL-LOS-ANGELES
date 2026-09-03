<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Enums\TipoItem;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\MovimientoKardex;
use App\Models\Prestamo;
use App\Services\RegistradorDePrestamo;

/*
 * Pedir prestado lo que no hay.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PROTEGE ESTE ARCHIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El problema real que resuelve el módulo: la farmacia se queda sin
 * amoxicilina, la pide prestada a la de la esquina, la dispensa, y a la
 * semana nadie se acuerda de que hay que devolverla. Hasta hoy la única
 * salida era una entrada por «Ajustes y bajas», que deja la cantidad bien
 * y pierde al acreedor.
 *
 * Las tres invariantes que no se pueden romper:
 *
 *   1. el documento y la entrada al kardex viven o mueren juntos;
 *   2. lo que trajo la familia del paciente NO es deuda del hospital;
 *   3. el préstamo no toca el costo promedio — un costo inventado acá
 *      mueve el precio de lista de todo el producto.
 */

function elPrestamista(): RegistradorDePrestamo
{
    return app(RegistradorDePrestamo::class);
}

/** Un insumo que sí se almacena y no exige lote. */
function unInsumoDeBodega(): Item
{
    return Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico)
        ->create(['se_almacena' => true]);
}

function elSaldoDe(Item $item, Almacen $almacen): string
{
    $saldo = Existencia::query()
        ->where('item_id', $item->id)
        ->where('almacen_id', $almacen->id)
        ->first();

    return $saldo instanceof Existencia ? $saldo->cantidadDecimal()->redondeado(4) : '0.0000';
}

it('sube la existencia y deja el documento con el acreedor', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    $movimiento = MovimientoKardex::query()
        ->where('referencia', "Préstamo #{$prestamo->id}")
        ->sole();

    expect(elSaldoDe($item, $almacen))->toBe('20.0000')
        ->and($movimiento->tipo)->toBe(TipoMovimiento::EntradaPorPrestamo)
        ->and($prestamo->estado)->toBe(EstadoPrestamo::Pendiente)
        ->and($prestamo->saldoPendiente()->redondeado(4))->toBe('20.0000')
        ->and($prestamo->seDebe())->toBeTrue();
})->note('Sin la entrada al kardex, el cobro siguiente lo rechaza el sistema con la caja ahí en el estante.');

it('no le inventa un costo al producto prestado', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    $movimiento = MovimientoKardex::query()
        ->where('referencia', "Préstamo #{$prestamo->id}")
        ->sole();

    expect($movimiento->costo_unitario)->toBeNull()
        ->and($movimiento->costo_promedio_despues)->toBeNull();
})->note('Un costo cero acá baja el promedio móvil, sube el margen aparente, y el hospital vende bajo costo sin que nada avise.');

it('lo que trae la familia del paciente se registra pero no se debe', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $delPaciente = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('5'),
        quienPresta: QuienPresta::MedicoOFamiliar,
        nombreDeQuienPresta: 'HERMANA DEL PACIENTE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    $deLaFarmacia = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    /** @var list<int> $seDeben */
    $seDeben = Prestamo::query()->queSeDeben()->pluck('id')->all();

    expect(elSaldoDe($item, $almacen))->toBe('25.0000')
        ->and($seDeben)->toBe([$deLaFarmacia->id])
        ->and($delPaciente->seDebe())->toBeFalse();
})->note('Una lista de «lo que debemos» con cosas que nadie va a devolver deja de mirarse, y ahí se pierde la que sí importaba.');

it('la devolución parcial deja el préstamo abierto y sin fecha de cierre', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    elPrestamista()->devolver($prestamo, Decimal::de('8'));

    $releido = $prestamo->fresh();

    expect($releido?->estado)->toBe(EstadoPrestamo::Parcial)
        ->and($releido?->saldoPendiente()->redondeado(4))->toBe('12.0000')
        ->and($releido?->saldado_en)->toBeNull()
        ->and(elSaldoDe($item, $almacen))->toBe('12.0000');
})->note('Obligar a devolver todo de una vez hace que nadie registre nada hasta el final, y el final no llega.');

it('devolver el resto lo cierra con fecha', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    elPrestamista()->devolver($prestamo, Decimal::de('8'));
    elPrestamista()->devolver($prestamo->fresh() ?? $prestamo, Decimal::de('12'));

    $releido = $prestamo->fresh();

    expect($releido?->estado)->toBe(EstadoPrestamo::Saldado)
        ->and($releido?->saldado_en)->not->toBeNull()
        ->and($releido?->seDebe())->toBeFalse()
        ->and(elSaldoDe($item, $almacen))->toBe('0.0000');
});

it('no deja devolver más de lo que se debe', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    elPrestamista()->devolver($prestamo, Decimal::de('25'));
})->throws(PrestamoException::class);

it('el que se acordó pagar no se cierra devolviendo producto', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::HospitalOClinica,
        nombreDeQuienPresta: 'CLINICA DEL VALLE',
        forma: FormaDeSaldo::Pagar,
        montoAcordado: Decimal::de('450'),
    );

    elPrestamista()->devolver($prestamo, Decimal::de('20'));
})->throws(PrestamoException::class);

it('pagarle cierra el préstamo sin tocar el inventario', function (): void {
    $item = unInsumoDeBodega();
    $almacen = Almacen::factory()->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $almacen,
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::HospitalOClinica,
        nombreDeQuienPresta: 'CLINICA DEL VALLE',
        forma: FormaDeSaldo::Pagar,
        montoAcordado: Decimal::de('450'),
    );

    elPrestamista()->marcarPagado($prestamo, 'Recibo 1204');

    $releido = $prestamo->fresh();

    expect($releido?->estado)->toBe(EstadoPrestamo::Saldado)
        ->and($releido?->referencia_del_saldo)->toBe('Recibo 1204')
        ->and(elSaldoDe($item, $almacen))->toBe('20.0000');
})->note('Lo prestado entró y se queda: lo que se salda es plata, no producto.');

it('exige el monto cuando se acordó pagar', function (): void {
    elPrestamista()->registrar(
        item: unInsumoDeBodega(),
        almacen: Almacen::factory()->create(),
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::Pagar,
    );
})->throws(PrestamoException::class);

it('exige el nombre de quien prestó', function (): void {
    elPrestamista()->registrar(
        item: unInsumoDeBodega(),
        almacen: Almacen::factory()->create(),
        cantidad: Decimal::de('20'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: '  ',
        forma: FormaDeSaldo::DevolverProducto,
    );
})->throws(PrestamoException::class);
