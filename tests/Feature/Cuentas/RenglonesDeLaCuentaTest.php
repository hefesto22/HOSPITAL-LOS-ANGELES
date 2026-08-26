<?php

declare(strict_types=1);

use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\User;

/**
 * CÓMO SE LEE LA CUENTA MIENTRAS SE ARMA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DIEZ Y CINCO SON QUINCE, PERO SOLO SI LOS DIO LA MISMA PERSONA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Enfermería entrega cuando toca, no una sola vez. Una cuenta con doce
 * renglones de ACETAMINOFEN no se puede revisar, y esa pantalla existe
 * para revisarse. Pero sumar todo tampoco sirve: si el turno A dio 15 ml
 * y el turno B dio 20, un renglón de 35 borra la única pregunta que se
 * hace en el cambio de turno — **quién le dio qué a este paciente**.
 *
 * ⚠️ Nada de esto toca la base. Los cargos siguen siendo uno por entrega,
 * cada uno con su movimiento de kardex y su lote. Se agrupa AL LEER.
 */
function unaCuentaConCargos(): Cuenta
{
    return Cuenta::factory()->create();
}

/**
 * @param numeric-string $cantidad
 */
function entrega(Cuenta $cuenta, User $quien, string $cantidad, string $texto = 'ACETAMINOFEN JARABE'): Cargo
{
    return Cargo::factory()->enLaCuenta($cuenta)->create([
        'texto'      => $texto,
        'cantidad'   => $cantidad,
        'created_by' => $quien->id,
    ]);
}

it('🔴 dos entregas de la misma persona son un solo renglon', function (): void {
    $cuenta = unaCuentaConCargos();
    $turnoA = User::factory()->create();

    entrega($cuenta, $turnoA, '10.0000');
    entrega($cuenta, $turnoA, '5.0000');

    $renglones = $cuenta->renglonesVivos();

    expect($renglones)->toHaveCount(1)
        ->and($renglones->first()?->cantidad->redondeado(0))->toBe('15')
        ->and($renglones->first()?->cuantasEntregas())->toBe(2);
})->note('🔴 Los dos cargos siguen existiendo en la base, cada uno con su hora y su lote. Fusionarlos de verdad rompería el kardex append-only y borraría de qué lote salió cada mililitro: los 10 pueden venir de un lote y los 5 del siguiente.');

it('🔴 el turno siguiente abre renglon nuevo', function (): void {
    $cuenta = unaCuentaConCargos();
    $turnoA = User::factory()->create();
    $turnoB = User::factory()->create();

    entrega($cuenta, $turnoA, '10.0000');
    entrega($cuenta, $turnoA, '5.0000');
    entrega($cuenta, $turnoB, '20.0000');

    $renglones = $cuenta->renglonesVivos();

    expect($renglones)->toHaveCount(2)
        ->and($renglones->first()?->cantidad->redondeado(0))->toBe('15')
        ->and($renglones->last()?->cantidad->redondeado(0))->toBe('20')
        ->and($cuenta->laCargaMasDeUno())->toBeTrue();
})->note('🔴 Es el pedido textual: «turno A 10 de jarabe, después otros 5, eso se toma como 15; viene turno B y da 20, entonces otra línea de 20». En la factura se amontonará todo — eso es otra vista.');

it('con una sola persona no hace falta decir quien cargo', function (): void {
    $cuenta = unaCuentaConCargos();
    $quien = User::factory()->create();

    entrega($cuenta, $quien, '10.0000');

    expect($cuenta->laCargaMasDeUno())->toBeFalse();
})->note('El nombre solo se imprime cuando hay más de uno. Repetido en cada renglón no informa nada y un rótulo que está siempre se vuelve decorado.');

it('dos productos distintos no se mezclan aunque los cargue la misma persona', function (): void {
    $cuenta = unaCuentaConCargos();
    $quien = User::factory()->create();

    entrega($cuenta, $quien, '10.0000', 'ACETAMINOFEN JARABE · lote LOTE-1');
    entrega($cuenta, $quien, '10.0000', 'ACETAMINOFEN JARABE · lote LOTE-2');

    expect($cuenta->renglonesVivos())->toHaveCount(2);
})->note('El texto ya trae el lote, así que la llave lo separa sola. Dos lotes en un renglón dirían que salieron del mismo frasco.');

it('🔴 la ultima entrega es la que quita la equis', function (): void {
    $cuenta = unaCuentaConCargos();
    $quien = User::factory()->create();

    entrega($cuenta, $quien, '10.0000');
    $ultima = entrega($cuenta, $quien, '5.0000');

    $renglon = $cuenta->renglonesVivos()->first();

    expect($renglon?->ultimaEntrega()?->id)->toBe($ultima->id)
        ->and($renglon?->sePuedeQuitar())->toBeTrue();
})->note('🔴 Este es el test que faltaba: `ultimaEntrega()` usaba `end()`, que mueve el puntero interno del arreglo —o sea que lo MODIFICA— y `entregas` es readonly. PHP lo rechazaba en tiempo de ejecución al abrir la cuenta, y ni `php -l` ni PHPStan lo veían.');

it('un renglon sin entregas no revienta', function (): void {
    $cuenta = unaCuentaConCargos();

    expect($cuenta->renglonesVivos())->toBeEmpty();
})->note('La cuenta recién abierta pasa por el mismo camino que la que tiene veinte líneas.');
