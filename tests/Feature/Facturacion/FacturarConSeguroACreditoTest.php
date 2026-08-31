<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Models\Convenio;
use App\Models\RangoCai;
use App\Services\EmisorDeFactura;

/**
 * FACTURAR CON EL SEGURO A CRÉDITO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE ARCHIVO EXISTE PARA IMPEDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que una cuenta con seguro quede trabada para siempre.
 *
 * El sistema exigía la cuenta SALDADA para emitir la factura, y eso
 * medía el total. Con un seguro que cubre el 70 %, esos L 7,000 llegan a
 * treinta días CONTRA LA FACTURA —la que se está por emitir—, así que
 * había que cobrarle al paciente la parte del seguro para poder
 * facturarle al seguro. Un círculo del que solo se salía cobrándole de
 * más a alguien.
 *
 * Ahora se mide lo del PACIENTE. Lo del seguro se factura y queda por
 * cobrar, que es de lo que vive un convenio.
 *
 * ⚠️ Y para el paciente de contado NADA cambió: su porción ES el total.
 * Eso también se prueba acá, porque es la mitad que no se puede romper.
 */
it('🔴 factura con el paciente al dia aunque el seguro no haya pagado', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.7000'));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '10000.0000');

    $cuenta->refresh();

    /* La cobertura ya partió la cuenta sola: 7,000 y 3,000. */
    expect($cuenta->total)->toBe('10000.00')
        ->and($cuenta->total_aseguradora)->toBe('7000.00')
        ->and($cuenta->total_paciente)->toBe('3000.00');

    /* El paciente deja lo suyo. El seguro paga a treinta días. */
    abonarle($cuenta, '3000.00');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('JUAN PEREZ'),
    );

    /* Se factura el TOTAL: el seguro y el paciente van en el mismo papel. */
    expect($factura->total)->toBe('10000.00');
})->note('Antes esto tiraba «la cuenta debe L 7,000»: había que cobrarle al paciente la parte del seguro para poder facturarle al seguro.');

it('no factura mientras el paciente deba lo suyo', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.7000'));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '10000.0000');

    /* Pone la mitad de su parte y se quiere ir. */
    abonarle($cuenta, '1500.00');

    expect(fn () => app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('JUAN PEREZ'),
    ))->toThrow(FacturaException::class, 'el paciente todavía debe L 1500.00');
});

it('🔴 al paciente de contado se le sigue exigiendo todo', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create([
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreElTotalFacturado,
    ]));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '10000.0000');

    $cuenta->refresh();

    /* Sin seguro, su porción ES el total: la regla nueva es la vieja. */
    expect($cuenta->total_paciente)->toBe('10000.00')
        ->and($cuenta->total_aseguradora)->toBe('0.00');

    abonarle($cuenta, '9999.00');

    expect(fn () => app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('JUAN PEREZ'),
    ))->toThrow(FacturaException::class);
})->note('La mitad que no se puede romper: sin seguro nadie se lleva la factura sin pagar.');

it('el saldo del paciente descuenta lo que ya abono', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.6000'));

    conUnServicioDe($cuenta, '5000.0000');

    $cuenta->refresh();

    expect($cuenta->saldoPendienteDelPaciente()->redondeado(2))->toBe('2000.00')
        ->and($cuenta->elPacientePusoLoSuyo())->toBeFalse();

    abonarle($cuenta, '2000.00');

    expect($cuenta->refresh()->elPacientePusoLoSuyo())->toBeTrue()
        /* Y la cuenta SIGUE debiendo: los 3,000 del seguro. */
        ->and($cuenta->saldoPendiente()->redondeado(2))->toBe('3000.00');
})->note('Las dos preguntas conviven: «¿el paciente puso lo suyo?» decide la factura, «¿cuánto debe la cuenta?» sigue siendo la deuda real.');
