<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UN PRÉSTAMO SOLO ENTRA DONDE SE PUEDE SACAR DE VUELTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio (3-sep-2026): «debe ser solo facturar o bodega; en
 * caso de que pidan 2, que una se guarde en bodega como prestado y otra
 * se facture — así tenemos una en stock disponible y se sabe a quién se
 * le pidió prestada».
 *
 * Un préstamo es una deuda con alguien de AFUERA, y para pagarla hay que
 * poder sacar el producto de donde quedó. La farmacia interna y el stock
 * del servicio no sirven: ahí lo que entra se CONSUME. Una deuda parada
 * en el carro de paro es una que el día de la compra va a pedir devolver
 * veinte tabletas que ya se usaron.
 */
it('la bodega, la farmacia de venta y el almacen unico reciben prestamos', function (): void {
    expect(TipoAlmacen::BodegaCentral->recibePrestamo())->toBeTrue()
        ->and(TipoAlmacen::FarmaciaVenta->recibePrestamo())->toBeTrue()
        ->and(TipoAlmacen::AlmacenUnico->recibePrestamo())->toBeTrue();
})->note('Los tres se cuentan y de los tres se puede sacar para devolver.');

it('🔴 los estantes de consumo interno no reciben prestamos', function (): void {
    expect(TipoAlmacen::FarmaciaInterna->recibePrestamo())->toBeFalse()
        ->and(TipoAlmacen::StockDeServicio->recibePrestamo())->toBeFalse();
})->note('🔴 Ahí lo que entra se consume. Un préstamo parado en el carro de paro es una deuda que no se puede devolver sin trasladarla primero.');

it('el desplegable solo ofrece los estantes que reciben', function (): void {
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create(['nombre' => 'BODEGA CENTRAL']);
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create(['nombre' => 'FARMACIA']);
    $carro = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['nombre' => 'CARRO DE PARO']);

    $ofrecidos = Almacen::query()->queRecibenPrestamo()->pluck('id')->all();

    expect($ofrecidos)->toContain($bodega->getKey())
        ->and($ofrecidos)->toContain($farmacia->getKey())
        ->and($ofrecidos)->not->toContain($carro->getKey());
})->note('El desplegable ofrecía TODOS los estantes, incluidos los de consumo interno.');

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL CASO QUE MOTIVÓ LA REGLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Si piden 2, que una se guarde en bodega como prestado y otra se
 * facture». Eso ya funcionaba sin campo nuevo: el préstamo sube las dos
 * al kardex y el cargo baja una. Lo que faltaba era que el estante
 * elegido fuera uno del que se pueda sacar la que queda.
 */
it('el sobrante queda en el estante y la deuda sigue entera', function (): void {
    $item = unInsumoDeBodega();
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();

    $prestamo = elPrestamista()->registrar(
        item: $item,
        almacen: $bodega,
        cantidad: Decimal::de('2'),
        quienPresta: QuienPresta::Farmacia,
        nombreDeQuienPresta: 'FARMACIA SAN JOSE',
        forma: FormaDeSaldo::DevolverProducto,
    );

    expect($bodega->tipo->recibePrestamo())->toBeTrue()
        ->and(elSaldoDe($item, $bodega))->toBe('2.0000')
        ->and($prestamo->saldoPendiente()->redondeado(4))->toBe('2.0000');
})->note('El sobrante vive en el kardex y la deuda en el préstamo: son dos preguntas distintas, y por eso son dos tablas. Cobrar una no salda nada — la deuda se cierra devolviendo o pagando.');

it('un hospital con solo estantes de consumo interno no ofrece ninguno', function (): void {
    Almacen::factory()->de(TipoAlmacen::FarmaciaInterna)->create();
    Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create();

    expect(Almacen::query()->queRecibenPrestamo()->count())->toBe(0);
})->note('El scope dice la verdad y no inventa. El «nunca vacío» —volver a ofrecer todos antes que dejar el desplegable en blanco con el paciente enfrente— es decisión de la pantalla, no de la consulta.');
