<?php

declare(strict_types=1);

use App\Filament\Resources\Cuentas\Tables\CuentasTable;
use App\Models\Convenio;
use App\Models\Cuenta;

/*
|--------------------------------------------------------------------------
| Las pestañas de la consulta de cuentas (12-oct-2026)
|--------------------------------------------------------------------------
|
| «En curso» y «Por cobrar» son consultas, y cada pestaña muestra arriba
| cuántas cuentas tiene: si la consulta se equivoca, el número de la
| pestaña también miente.
|
| La que importa es «Por cobrar»: la cuenta saldada no debe nada y la
| recién abierta, sin un solo cargo, tampoco. Antes la recién abierta
| salía como «Saldada», que es decir que pagaron algo que no existe.
|
| ⚠️ El convenio de contado se crea UNA vez por prueba: la fábrica le
| pone siempre el código CONTADO, que es único.
*/

it('🔴 por cobrar trae solo la que debe: ni la saldada ni la que no tiene cargos', function (): void {
    $contado = Convenio::factory()->contado()->create();

    $debe = unaCuentaCon($contado);
    conUnServicioDe($debe, '1000.0000');

    $saldada = unaCuentaCon($contado);
    conUnServicioDe($saldada, '500.0000');
    abonarle($saldada, '500.00');

    unaCuentaCon($contado);

    $porCobrar = CuentasTable::soloPorCobrar(Cuenta::query()->deTodasLasSedes())->pluck('id')->all();

    expect($porCobrar)->toBe([$debe->id]);
});

it('la que abonó de menos sigue por cobrar', function (): void {
    $contado = Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '999.99');

    $porCobrar = CuentasTable::soloPorCobrar(Cuenta::query()->deTodasLasSedes())->pluck('id')->all();

    expect($porCobrar)->toBe([$cuenta->id]);
});

it('en curso trae todas las que todavía no se facturan, deban o no', function (): void {
    $contado = Convenio::factory()->contado()->create();

    $debe = unaCuentaCon($contado);
    conUnServicioDe($debe, '1000.0000');

    $vacia = unaCuentaCon($contado);

    $enCurso = CuentasTable::soloEnCurso(Cuenta::query()->deTodasLasSedes())->orderBy('id')->pluck('id')->all();

    expect($enCurso)->toBe([$debe->id, $vacia->id]);
});
