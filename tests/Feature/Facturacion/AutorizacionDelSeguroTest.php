<?php

declare(strict_types=1);

use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Services\RegistradorDeAutorizacion;

/**
 * LO QUE EL SEGURO AUTORIZÓ PARA ESTA CUENTA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE ARCHIVO EXISTE PARA IMPEDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que el hospital le cobre al paciente una plata que el seguro sí iba a
 * cubrir, o al revés: que espere de una aseguradora un pago que nunca
 * autorizó.
 *
 * Mientras la cuenta se carga, cada cargo se parte con la cobertura
 * GENERAL del convenio, que es lo único que se sabe en ese momento. La
 * autorización llega después —el Hospital Militar aprueba L 5,000 de los
 * L 10,000— y a partir de ahí el reparto de los cargos ya no describe la
 * realidad.
 *
 * ⚠️ Y LOS CARGOS NO SE TOCAN: cada uno guarda lo que se calculó el día
 * que ocurrió, y el trigger `cargos_append_only` lo impide. Lo que la
 * autorización corrige no es ningún asiento —el total no se mueve— sino
 * a quién se le cobra. Eso también se prueba acá.
 */
function autorizador(): RegistradorDeAutorizacion
{
    return app(RegistradorDeAutorizacion::class);
}

it('sin autorizacion propia manda lo que cubre el convenio', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    $cuenta->refresh();

    expect($cuenta->total)->toBe('10000.00')
        ->and($cuenta->total_aseguradora)->toBe('5000.00')
        ->and($cuenta->total_paciente)->toBe('5000.00')
        ->and($cuenta->tieneAutorizacionPropia())->toBeFalse();
});

it('🔴 el monto aprobado manda sobre la cobertura del convenio', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    /* El Hospital Militar aprobó 5,000 de los 10,000... y después 3,000. */
    $cuenta = autorizador()->registrar($cuenta->refresh(), null, Monto::de('3000.00'));

    expect($cuenta->total)->toBe('10000.00')
        ->and($cuenta->total_aseguradora)->toBe('3000.00')
        ->and($cuenta->total_paciente)->toBe('7000.00');
})->note('El total nunca cambia: cambia de qué lado cae.');

it('el porcentaje autorizado tambien manda', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    /* PALIG cubre 50 % por contrato, pero de esta cuenta solo el 30 %. */
    $cuenta = autorizador()->registrar($cuenta->refresh(), Decimal::de('0.30'), null);

    expect($cuenta->total_aseguradora)->toBe('3000.00')
        ->and($cuenta->total_paciente)->toBe('7000.00');
});

it('🔴 los cargos conservan el reparto del dia que ocurrieron', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    autorizador()->registrar($cuenta->refresh(), null, Monto::de('3000.00'));

    $cargo = Cargo::query()->where('cuenta_id', $cuenta->id)->firstOrFail();

    /*
     * El cargo sigue diciendo 5,000 y 5,000: es el hecho de ese día. La
     * cuenta dice 3,000 y 7,000, que es a quién se le cobra hoy.
     */
    expect($cargo->porcion_aseguradora)->toBe('5000.00')
        ->and($cargo->porcion_paciente)->toBe('5000.00');
})->note('Un cargo es un hecho, no una opinión revisable: el trigger cargos_append_only lo impide cambiar, y está bien que así sea.');

it('el monto aprobado se recorta al total de la cuenta', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '3000.0000');

    /* Autorizaron 5,000 pero la cuenta terminó en 3,000. */
    $cuenta = autorizador()->registrar($cuenta->refresh(), null, Monto::de('5000.00'));

    expect($cuenta->total_aseguradora)->toBe('3000.00')
        ->and($cuenta->total_paciente)->toBe('0.00');
})->note('Sin el recorte quedaría un paciente con saldo a favor de 2,000 que nadie le debe.');

it('quitar la autorizacion devuelve el reparto del convenio', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    autorizador()->registrar($cuenta->refresh(), null, Monto::de('3000.00'));
    $cuenta = autorizador()->quitar($cuenta->refresh());

    expect($cuenta->total_aseguradora)->toBe('5000.00')
        ->and($cuenta->total_paciente)->toBe('5000.00')
        ->and($cuenta->tieneAutorizacionPropia())->toBeFalse();
});

it('🔴 no acepta porcentaje y monto a la vez', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    expect(fn () => autorizador()->registrar($cuenta->refresh(), Decimal::de('0.30'), Monto::de('3000.00')))
        ->toThrow(CuentaException::class);
})->note('Con los dos puestos, cuál manda lo decidiría el código y alguna vez elegiría mal.');

it('un cargo posterior no pisa la autorizacion ya anotada', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));
    conUnServicioDe($cuenta, '10000.0000');

    autorizador()->registrar($cuenta->refresh(), null, Monto::de('3000.00'));

    /* Llega un cargo tardío: el sistema nunca rechaza un hecho clínico. */
    conUnServicioDe($cuenta->refresh(), '2000.0000');

    $cuenta->refresh();

    /*
     * El total sube a 12,000 y el seguro sigue en los 3,000 que autorizó:
     * lo que llegó después no lo cubre nadie salvo que lo aprueben.
     */
    expect($cuenta->total)->toBe('12000.00')
        ->and($cuenta->total_aseguradora)->toBe('3000.00')
        ->and($cuenta->total_paciente)->toBe('9000.00');
})->note('El refresco de totales de cada cargo pasa por el mismo repartidor, así que la autorización sobrevive.');
