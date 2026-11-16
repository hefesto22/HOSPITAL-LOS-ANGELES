<?php

declare(strict_types=1);

use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Tarifario;

/*
|--------------------------------------------------------------------------
| El precio de lista que se puede subir al cargar (16-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «hay unos como RECARGO EXAMEN DE LABORATORIO que su precio base
| es de 250 pero puede cambiarse a más… esto solo en precios de lista, en
| lo de los seguros no».
|
| ⚠️ Ayudantes con nombre propio (`--parallel`).
*/

function unRecargoQueSeSube(bool $modificable = true): Item
{
    $item = unServicioDe('250.0000');
    $item->forceFill(['precio_modificable' => $modificable])->save();

    return $item;
}

/**
 * @param array<string, mixed> $data
 */
function elPrecioSubido(Item $item, Cuenta $cuenta, array $data): ?Monto
{
    $pantalla = app(CuentasAbiertas::class);

    /** @var Monto|null $precio */
    $precio = (new ReflectionMethod(CuentasAbiertas::class, 'precioSubido'))->invoke($pantalla, $item, $cuenta, $data);

    return $precio;
}

it('🔴 marcado, en la lista se cobra lo escrito si es más; menos no se puede', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $recargo = unRecargoQueSeSube();

    expect(elPrecioSubido($recargo, $cuenta, ['precio_acordado' => '400'])?->valor())->toBe('400.00')
        ->and(elPrecioSubido($recargo, $cuenta, ['precio_acordado' => '250']))->toBeNull()
        ->and(fn (): ?Monto => elPrecioSubido($recargo, $cuenta, ['precio_acordado' => '200']))
        ->toThrow(CargoException::class);
});

it('🔴 sin la marca, lo que llegue del navegador se ignora', function (): void {
    $cuenta = unaCuentaDeStockInfinito();

    expect(elPrecioSubido(unRecargoQueSeSube(modificable: false), $cuenta, ['precio_acordado' => '900']))->toBeNull();
});

it('🔴 con un seguro que lo pacto, el precio no se sube', function (): void {
    $seguro = unSeguroQueCubre('0.5000');
    $cuenta = unaCuentaCon($seguro);
    $recargo = unRecargoQueSeSube();
    Tarifario::factory()->delItem($recargo)->paraElConvenio($seguro)->a('300.0000')->create();

    expect(elPrecioSubido($recargo, $cuenta, ['precio_acordado' => '900']))->toBeNull();
});
