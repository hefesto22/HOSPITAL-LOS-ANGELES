<?php

declare(strict_types=1);

use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Models\Convenio;
use App\Models\RangoCai;
use App\Services\EmisorDeFactura;

/**
 * CON SEGURO, LA FACTURA SALE CON EL RTN DEL SEGURO. SIEMPRE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REGLA DEL HOSPITAL (10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La aseguradora —o el Militar, o el IHSS— es quien paga y quien
 * reembolsa: su RTN es el que va en el papel. Antes, sin RTN cargado en
 * el convenio, la pantalla proponía la identidad del paciente y la
 * factura salía a nombre del seguro con el documento de otra persona.
 *
 * Lo decide `EmisorDeFactura`, no la pantalla: el número sale del
 * convenio y lo que llegue en el formulario no cuenta. Sin RTN en el
 * convenio, no se emite.
 */
it('🔴 con seguro sale el rtn del convenio aunque la pantalla mande la identidad del paciente', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('1.0000'));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '1000.0000');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura(
            nombre: 'ASEGURADORA / JUAN PEREZ',
            documento: '0801198012345',
            tipoDocumento: TipoIdentificador::Dni,
        ),
    );

    expect($factura->cliente_documento)->toBe('08019001234567')
        ->and($factura->cliente_documento_tipo)->toBe(TipoIdentificador::Rtn)
        ->and($factura->cliente_nombre)->toBe('ASEGURADORA / JUAN PEREZ');
})->note('El nombre queda como vino —aseguradora y paciente—; el documento es el del que paga.');

it('🔴 sin rtn en el convenio no se emite', function (): void {
    $sinRtn = Convenio::factory()->create([
        'tipo'                  => TipoConvenio::Institucional,
        'rtn'                   => null,
        'cobertura_fraccion'    => '1.0000',
        'cubre_por_defecto'     => true,
        'requiere_autorizacion' => false,
    ]);

    $cuenta = unaCuentaCon($sinRtn);
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '1000.0000');

    expect(fn () => app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('HOSPITAL MILITAR / JUAN PEREZ', '0801198012345', TipoIdentificador::Dni),
    ))->toThrow(FacturaException::class, 'no está cargado');
})->note('Es el caso del Hospital Militar hoy: sin RTN en Seguros y convenios. Antes salía con la identidad del paciente.');

it('de contado manda el documento del cliente, como siempre', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '1000.00');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('JUAN PEREZ', '0801198012345', TipoIdentificador::Dni),
    );

    expect($factura->cliente_documento)->toBe('0801198012345')
        ->and($factura->cliente_documento_tipo)->toBe(TipoIdentificador::Dni);
});
