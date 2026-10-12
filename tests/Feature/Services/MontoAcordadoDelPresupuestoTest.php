<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Exceptions\PresupuestoException;
use App\Models\Item;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Services\CotizadorDePresupuesto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * LO QUE SE LE COBRA A LA FAMILIA, Y LA HOLGURA QUE COMPLETA.
 *
 * Mauricio, 12-oct-2026, de lo que contó el doctor: los renglones suman
 * L 645, a la familia se le cobran L 1,000, y el resto va en holgura o
 * agregando más cosas hasta llegar a los L 1,000.
 *
 * Lo que este archivo cuida:
 *
 *   · que la holgura complete exacto, al centavo, y que se ajuste sola
 *     cuando se agregan o quitan renglones;
 *   · que si los renglones se pasan NO se recorte nada — se avisa;
 *   · que quitar el acuerdo no mueva el total a escondidas;
 *   · que medicamentos y materiales no cambien de precio.
 */

// Un renglón de servicio por un monto, ya sumado en el presupuesto.
function unRenglonQueVale(Presupuesto $presupuesto, string $monto, int $orden = 10): PresupuestoLinea
{
    $linea = PresupuestoLinea::factory()->create([
        'presupuesto_id'  => $presupuesto->id,
        'orden'           => $orden,
        'precio_unitario' => $monto,
        'bruto'           => $monto,
        'subtotal'        => $monto,
        'base_exenta'     => $monto,
        'total'           => $monto,
    ]);

    app(CotizadorDePresupuesto::class)->recalcular($presupuesto);

    return $linea;
}

function cotizador(): CotizadorDePresupuesto
{
    return app(CotizadorDePresupuesto::class);
}

it('la holgura completa exacto lo que se le cobra', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '400.00');
    unRenglonQueVale($presupuesto, '245.00', 20);

    $presupuesto = cotizador()->acordarMonto($presupuesto, '1000', 'imprevistos');

    $complemento = $presupuesto->complemento();

    expect($presupuesto->monto_acordado)->toBe('1000.00')
        ->and($presupuesto->total)->toBe('1000.00')
        ->and($complemento)->toBeInstanceOf(PresupuestoLinea::class)
        ->and($complemento?->total)->toBe('355.00')
        ->and($complemento?->texto)->toBe('IMPREVISTOS')
        ->and($complemento?->origen)->toBe(OrigenLineaPresupuesto::Holgura)
        ->and($complemento?->item_id)->toBeNull()
        ->and($complemento?->orden)->toBe(CotizadorDePresupuesto::ORDEN_DEL_COMPLEMENTO);
});

it('sin nombre la holgura que completa sale con el de siempre', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');

    $presupuesto = cotizador()->acordarMonto($presupuesto, '1000.00');

    expect($presupuesto->complemento()?->texto)->toBe(CotizadorDePresupuesto::HOLGURA_POR_DEFECTO);
});

it('al agregar renglones la holgura se achica y el total sigue en lo acordado', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');
    cotizador()->acordarMonto($presupuesto, '1000.00');

    unRenglonQueVale($presupuesto, '120.50', 20);
    $presupuesto->refresh();

    expect($presupuesto->total)->toBe('1000.00')
        ->and($presupuesto->complemento()?->total)->toBe('234.50');
});

it('si los renglones se pasan no se recorta nada: la holgura se va y el total queda en lo que suman', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');
    cotizador()->acordarMonto($presupuesto, '1000.00');

    $sobra = unRenglonQueVale($presupuesto, '500.00', 20);
    $presupuesto->refresh();

    expect($presupuesto->total)->toBe('1145.00')
        ->and($presupuesto->complemento())->toBeNull();

    // Y si se quita lo que sobraba, la holgura vuelve a completar.
    cotizador()->quitarLinea($sobra);
    $presupuesto->refresh();

    expect($presupuesto->total)->toBe('1000.00')
        ->and($presupuesto->complemento()?->total)->toBe('355.00');
});

it('si los renglones llegan justo no queda una holgura en cero', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '1000.00');

    $presupuesto = cotizador()->acordarMonto($presupuesto, '1000.00');

    expect($presupuesto->total)->toBe('1000.00')
        ->and($presupuesto->complemento())->toBeNull()
        ->and($presupuesto->detalle()->count())->toBe(1);
});

it('la holgura que ya habia pasa a ser la que completa, con su nombre', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');

    PresupuestoLinea::factory()->holgura('64.50')->create([
        'presupuesto_id' => $presupuesto->id,
        'orden'          => 1010,
        'texto'          => 'MARGEN POR COMPLICACIONES',
    ]);

    $presupuesto = cotizador()->acordarMonto($presupuesto, '1000.00');

    expect($presupuesto->detalle()->where('origen', OrigenLineaPresupuesto::Holgura->value)->count())->toBe(1)
        ->and($presupuesto->complemento()?->texto)->toBe('MARGEN POR COMPLICACIONES')
        ->and($presupuesto->complemento()?->total)->toBe('355.00')
        ->and($presupuesto->total)->toBe('1000.00');
});

it('quitar el acuerdo deja la holgura como una mas, sin mover el total', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');
    cotizador()->acordarMonto($presupuesto, '1000.00');

    $presupuesto = cotizador()->acordarMonto($presupuesto, null);

    $holgura = $presupuesto->detalle()->where('origen', OrigenLineaPresupuesto::Holgura->value)->first();

    expect($presupuesto->monto_acordado)->toBeNull()
        ->and($presupuesto->total)->toBe('1000.00')
        ->and($presupuesto->complemento())->toBeNull()
        ->and($holgura?->completa_el_monto)->toBeFalse()
        ->and($holgura?->total)->toBe('355.00');
});

it('un monto en cero es lo mismo que no acordar', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');

    $presupuesto = cotizador()->acordarMonto($presupuesto, '0');

    expect($presupuesto->monto_acordado)->toBeNull()
        ->and($presupuesto->total)->toBe('645.00');
});

it('la holgura que completa no se ajusta a mano, pero si se le cambia el nombre', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    unRenglonQueVale($presupuesto, '645.00');
    $presupuesto = cotizador()->acordarMonto($presupuesto, '1000.00');

    $complemento = $presupuesto->complemento() ?? throw new RuntimeException('Debía quedar una holgura que complete.');

    expect(fn () => cotizador()->ajustarLinea($complemento, '1.0000', '500.0000'))
        ->toThrow(PresupuestoException::class);

    $renombrada = cotizador()->ajustarLinea($complemento, $complemento->cantidad, texto: 'imprevistos');

    expect($renombrada->texto)->toBe('IMPREVISTOS')
        ->and($renombrada->total)->toBe('355.00');
});

it('medicamentos y materiales no cambian de precio, pero si de cantidad', function (): void {
    $presupuesto = Presupuesto::factory()->create();

    $medicamento = PresupuestoLinea::factory()->create([
        'presupuesto_id' => $presupuesto->id,
        'item_id'        => Item::factory()->medicamento()->create()->id,
        'texto'          => 'CEFTRIAXONA 1 G',
    ]);

    expect($medicamento->refresh()->precioEsDeLista())->toBeTrue()
        ->and(fn () => cotizador()->ajustarLinea($medicamento, '1.0000', '1.0000'))
        ->toThrow(PresupuestoException::class);

    $ajustada = cotizador()->ajustarLinea($medicamento, '3.0000');

    expect($ajustada->cantidad)->toBe('3.0000')
        ->and($ajustada->precio_unitario)->toBe('1000.0000')
        ->and($ajustada->total)->toBe('3000.00');
});

it('un servicio si cambia de precio y queda como precio acordado a mano', function (): void {
    $presupuesto = Presupuesto::factory()->create();
    $servicio = unRenglonQueVale($presupuesto, '1000.00');

    expect($servicio->refresh()->precioEsDeLista())->toBeFalse();

    $ajustada = cotizador()->ajustarLinea($servicio, '1.0000', '1500.0000');

    expect($ajustada->precio_unitario)->toBe('1500.0000')
        ->and($ajustada->origen)->toBe(OrigenLineaPresupuesto::Manual);
});

it('un presupuesto que ya no se toca no acepta monto', function (): void {
    $presupuesto = Presupuesto::factory()->agregado()->create(['estado' => EstadoPresupuesto::Cerrado]);

    expect(fn () => cotizador()->acordarMonto($presupuesto, '1000.00'))
        ->toThrow(PresupuestoException::class);
});

it('la base no deja que un renglon que no es holgura complete el monto', function (): void {
    $linea = PresupuestoLinea::factory()->create();

    expect(fn () => DB::table('presupuesto_lineas')->where('id', $linea->id)->update(['completa_el_monto' => true]))
        ->toThrow(QueryException::class);
});

it('la base no deja dos holguras que completen el mismo presupuesto', function (): void {
    $presupuesto = Presupuesto::factory()->create();

    $una = PresupuestoLinea::factory()->holgura('100.00')->create(['presupuesto_id' => $presupuesto->id]);
    $otra = PresupuestoLinea::factory()->holgura('200.00')->create(['presupuesto_id' => $presupuesto->id]);

    DB::table('presupuesto_lineas')->where('id', $una->id)->update(['completa_el_monto' => true]);

    expect(fn () => DB::table('presupuesto_lineas')->where('id', $otra->id)->update(['completa_el_monto' => true]))
        ->toThrow(QueryException::class);
});

it('el monto acordado no puede ser negativo en la base', function (): void {
    $presupuesto = Presupuesto::factory()->create();

    expect(fn () => DB::table('presupuestos')->where('id', $presupuesto->id)->update(['monto_acordado' => '-1.00']))
        ->toThrow(QueryException::class);
});
