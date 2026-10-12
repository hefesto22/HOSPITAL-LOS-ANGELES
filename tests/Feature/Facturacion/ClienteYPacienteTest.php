<?php

declare(strict_types=1);

use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\RangoCai;
use App\Services\EmisorDeFactura;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * EL CLIENTE ES QUIEN PAGA; EL PACIENTE VA APARTE (Mauricio, 11-oct-2026).
 *
 * «Debería haber uno para cliente y otro para paciente. Si tiene seguro,
 * el cliente es el seguro y el paciente siempre está; si no tiene
 * seguro, el cliente es consumidor final.» Y después: «en código de
 * cliente, el código que tiene el paciente en el sistema; si el paciente
 * tiene identidad registrada, que se imprima».
 *
 * Lo que este archivo cuida:
 *
 *   · que la caja proponga de cliente a quien paga, sin el paciente
 *     pegado al nombre;
 *   · que el paciente quede congelado en la factura —nombre, identidad y
 *     póliza—, con seguro o sin él;
 *   · que el «Código de Cliente» sea el expediente;
 *   · que arriba del umbral del SAR y con reembolso el cliente sea el
 *     paciente, que es quien tiene que identificarse;
 *   · y que nada de eso se edite una vez emitida.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

/**
 * Lo que la pantalla propone de cliente al abrir «Facturar».
 *
 * Por reflexión porque es privado: es lo que se precarga en el modal,
 * no una API.
 *
 * @return array{nombre: string, tipo: string|null, numero: string|null}
 */
function clienteQueProponeLaCaja(Cuenta $cuenta): array
{
    $pagina = app(CuentasAbiertas::class);
    $pagina->cuentaAFacturar = $cuenta->id;

    /** @var array{nombre: string, tipo: string|null, numero: string|null} $propuesto */
    $propuesto = (new ReflectionMethod(CuentasAbiertas::class, 'clientePropuesto'))->invoke($pagina);

    return $propuesto;
}

function elPacienteDeLaCuenta(Cuenta $cuenta): string
{
    return trim(Persona::query()->findOrFail($cuenta->encuentro->persona_id)->nombreCompleto());
}

function elExpedienteDeLaCuenta(Cuenta $cuenta): string
{
    return Expediente::query()->findOrFail($cuenta->encuentro->expediente_id)->numero;
}

function conIdentidad(Cuenta $cuenta, string $dni): void
{
    PersonaIdentificador::query()->create([
        'persona_id' => $cuenta->encuentro->persona_id,
        'tipo'       => TipoIdentificador::Dni->value,
        'valor'      => $dni,
    ]);
}

it('🔴 con seguro el cliente es la aseguradora y el paciente sale aparte', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('1.0000'));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');

    $propuesto = clienteQueProponeLaCaja($cuenta);

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura($propuesto['nombre']),
    );

    expect($propuesto['nombre'])->toBe($cuenta->convenio->nombre)
        ->and($propuesto['numero'])->toBe('08019001234567')
        ->and($factura->cliente_nombre)->toBe($cuenta->convenio->nombre)
        ->and($factura->paciente_nombre)->toBe(elPacienteDeLaCuenta($cuenta));
})->note('Antes: «ASEGURADORA / PACIENTE» en el renglón del cliente. Ahora cada uno en el suyo.');

it('🔴 sin seguro el cliente es consumidor final y el paciente igual sale', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '1000.00');

    $propuesto = clienteQueProponeLaCaja($cuenta);

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: ClienteDeFactura::consumidorFinal(),
    );

    expect($propuesto)->toBe(['nombre' => 'CONSUMIDOR FINAL', 'tipo' => null, 'numero' => null])
        ->and($factura->cliente_nombre)->toBe('CONSUMIDOR FINAL')
        ->and($factura->paciente_nombre)->toBe(elPacienteDeLaCuenta($cuenta));
});

it('🔴 el codigo de cliente es el expediente y la identidad del paciente sale impresa', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conIdentidad($cuenta, '0801199012345');
    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '1000.00');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('CONSUMIDOR FINAL', codigo: 'LO-QUE-SEA'),
    );

    expect($factura->cliente_codigo)->toBe(elExpedienteDeLaCuenta($cuenta))
        ->and($factura->paciente_documento)->toBe('0801199012345')
        ->and($factura->paciente_documento_tipo)->toBe(TipoIdentificador::Dni)
        ->and($factura->rotuloDelDocumentoDelPaciente())->toBe('Identidad')
        ->and($factura->documentoDelPacienteImpreso())->toBe('0801-1990-12345');
})->note('El código lo pone el motor: lo que llegue de la pantalla no cuenta, igual que el RTN del seguro.');

it('sin identidad registrada el paciente sale solo con su nombre', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '1000.00');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: ClienteDeFactura::consumidorFinal(),
    );

    expect($factura->paciente_documento)->toBeNull()
        ->and($factura->paciente_documento_tipo)->toBeNull()
        ->and($factura->documentoDelPacienteImpreso())->toBeNull();
});

it('la poliza sale con el paciente: la escrita al facturar o la de la cuenta', function (): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('1.0000'));
    $cuenta->forceFill(['numero_poliza' => 'POL-DE-LA-CUENTA'])->save();
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('ASEGURADORA DE PRUEBA'),
    );

    expect($factura->paciente_poliza)->toBe('POL-DE-LA-CUENTA')
        ->and($factura->cliente_codigo)->toBe(elExpedienteDeLaCuenta($cuenta));
});

it('arriba del umbral del sar se propone al paciente y no consumidor final', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    conUnServicioDe($cuenta, '12000.0000');

    expect(clienteQueProponeLaCaja($cuenta)['nombre'])->toBe(elPacienteDeLaCuenta($cuenta));
})->note('Más de L 10,000 sin identificar al cliente no se emite (Acuerdo 481-2017). Proponer «CONSUMIDOR FINAL» ahí era proponer un rechazo.');

it('🔴 con reembolso el paciente paga todo y la factura sale a nombre del seguro', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->reembolso()->create(['rtn' => '08019001234567']));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');

    $propuesto = clienteQueProponeLaCaja($cuenta);

    expect($cuenta->refresh()->saldoPendienteDelPaciente()->redondeado(2))->toBe('1000.00')
        ->and($propuesto['nombre'])->toBe($cuenta->convenio->nombre)
        ->and($propuesto['numero'])->toBe('08019001234567');

    abonarle($cuenta, '1000.00');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura($propuesto['nombre']),
    );

    expect($factura->cliente_nombre)->toBe($cuenta->convenio->nombre)
        ->and($factura->cliente_documento)->toBe('08019001234567')
        ->and($factura->cliente_documento_tipo)->toBe(TipoIdentificador::Rtn)
        ->and($factura->paciente_nombre)->toBe(elPacienteDeLaCuenta($cuenta));
})->note('Mauricio, 11-oct-2026: «el paciente nos paga todo; solo nos aseguramos de que la factura salga a nombre del seguro».');

it('con reembolso y sin rtn del seguro no se emite', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->reembolso()->create(['rtn' => null]));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conUnServicioDe($cuenta, '1000.0000');
    abonarle($cuenta, '1000.00');

    expect(fn () => app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura($cuenta->convenio->nombre),
    ))->toThrow(FacturaException::class, 'no está cargado');
});

it('🔴 el paciente de una factura emitida no se edita', function (string $columna, string $valor): void {
    $cuenta = unaCuentaCon(unSeguroQueCubre('1.0000'));
    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
    conIdentidad($cuenta, '0801199012345');
    conUnServicioDe($cuenta, '1000.0000');

    $factura = app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: new ClienteDeFactura('ASEGURADORA DE PRUEBA'),
        poliza: 'POL-123',
    );

    expect(fn () => DB::table('facturas')
        ->where('id', $factura->id)
        ->update([$columna => $valor]))
        ->toThrow(QueryException::class, 'ya está emitida');
})->with([
    'el nombre'    => ['paciente_nombre', 'OTRA PERSONA'],
    'la identidad' => ['paciente_documento', '0801199099999'],
    'la póliza'    => ['paciente_poliza', 'OTRA-POLIZA'],
])->note('Se corrige en el expediente; la factura del año pasado sigue diciendo lo que decía.');

/**
 * El cliente con el que la pantalla emitiría, con lo que mande el
 * formulario.
 *
 * @param array<string, mixed> $formulario
 */
function clienteConElQueSeEmite(Cuenta $cuenta, array $formulario): ClienteDeFactura
{
    $pagina = app(CuentasAbiertas::class);
    $pagina->cuentaAFacturar = $cuenta->id;

    /** @var ClienteDeFactura $cliente */
    $cliente = (new ReflectionMethod(CuentasAbiertas::class, 'clienteParaEmitir'))->invoke($pagina, $formulario);

    return $cliente;
}

it('🔴 el cliente no se escribe: sale el de la regla aunque el formulario traiga otro', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    conUnServicioDe($cuenta, '1000.0000');

    $cliente = clienteConElQueSeEmite($cuenta, [
        'otro_cliente'   => false,
        'cliente_nombre' => 'ALGUIEN QUE NO VA',
    ]);

    expect($cliente->nombre)->toBe('CONSUMIDOR FINAL')
        ->and($cliente->documento)->toBeNull();
})->note('Mauricio: «¿por qué hay que escribirlo?». Con el interruptor apagado los campos ni se muestran.');

it('a nombre de alguien sale lo que se escribio', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    conUnServicioDe($cuenta, '1000.0000');

    $cliente = clienteConElQueSeEmite($cuenta, [
        'otro_cliente'           => true,
        'cliente_nombre'         => 'EMPRESA DEL PACIENTE S.A.',
        'cliente_documento'      => '08019001234567',
        'cliente_documento_tipo' => TipoIdentificador::Rtn->value,
    ]);

    expect($cliente->nombre)->toBe('EMPRESA DEL PACIENTE S.A.')
        ->and($cliente->documento)->toBe('08019001234567')
        ->and($cliente->tipoDocumento)->toBe(TipoIdentificador::Rtn);
});

it('desde L 10,000 exactos ya sale a nombre del paciente', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    conUnServicioDe($cuenta, '10000.0000');

    expect(clienteQueProponeLaCaja($cuenta)['nombre'])->toBe(elPacienteDeLaCuenta($cuenta));
})->note('Mauricio: «si es 10,000 o más sale a nombre del paciente».');
