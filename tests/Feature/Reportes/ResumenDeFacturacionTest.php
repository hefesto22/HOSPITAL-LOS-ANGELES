<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoConvenio;
use App\Domain\ValueObjects\Decimal;
use App\Models\Abono;
use App\Models\AbonoMedio;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\RangoCai;
use App\Models\User;
use App\Services\ResumenDeFacturacion;

/*
 * EL REPORTE DEL MES (Mauricio, 6-nov-2026) — al centavo.
 *
 * «Cuánto se facturó ese mes, cuánto se recibió en transferencias,
 * tarjetas y efectivo, y cuánto se dio a cada seguro.»
 *
 * Un mes armado a mano:
 *
 *   PALIG (sin plazo)   1,000.00 (seguro 800.00) + 500.00 (seguro 400.00)
 *   MILITAR (30 días)   2,000.00 (seguro 1,500.00)
 *   Particular            345.50
 *   Reembolso             600.00  → paga el paciente: no es crédito de nadie
 *   Anulada PALIG         999.00  → aparte, no suma
 *   Del mes pasado        700.00  → no es de este mes
 *
 *   Facturado 4,445.50 = seguros 2,700.00 + pacientes 1,745.50
 *
 *   Recibos: efectivo 200.00 + tarjeta 300.00 (un recibo),
 *            transferencia 645.50 en BANCO ATLANTIDA,
 *            uno anulado de 999.00 y uno del mes pasado de 50.00.
 *   Cobrado 1,145.50 en dos recibos.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */

function diaDelMesParaElReporte(int $dia): string
{
    return now()->startOfMonth()->addDays($dia - 1)->toDateString();
}

/*
 * Una cuenta de contado por factura y por recibo, todas con el MISMO
 * convenio CONTADO. Sin esto cada factory armaba su propia cuenta con
 * `Convenio::factory()->contado()`, y la segunda chocaba con el único
 * de `convenios.codigo`.
 */
function cuentaParaElReporte(): Cuenta
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    return Cuenta::factory()->conPagador($contado)->create();
}

/**
 * @param numeric-string $total
 * @param numeric-string $seguro
 */
function facturaParaElReporte(
    RangoCai $rango,
    int $correlativo,
    string $total,
    string $seguro = '0.00',
    ?Convenio $convenio = null,
    ?string $fecha = null,
    bool $anulada = false,
): Factura {
    $anulacion = $anulada
        ? [
            'estado'           => EstadoFactura::Anulada->value,
            'anulada_en'       => now(),
            'anulada_por'      => User::factory()->create()->id,
            'motivo_anulacion' => 'FACTURA DE PRUEBA ANULADA',
        ]
        : [];

    return Factura::factory()->create(array_merge([
        'rango_cai_id'       => $rango->id,
        'cuenta_id'          => cuentaParaElReporte()->id,
        'correlativo'        => $correlativo,
        'numero'             => sprintf('000-001-01-%08d', $correlativo),
        'convenio_id'        => $convenio?->id,
        'fecha_operacion'    => $fecha ?? diaDelMesParaElReporte(3),
        'bruto'              => $total,
        'exento'             => $total,
        'total'              => $total,
        'a_cargo_del_seguro' => $seguro,
    ], $anulacion));
}

/**
 * @param list<array{0: FormaDePago, 1: numeric-string, 2?: string}> $medios
 */
function reciboParaElReporte(array $medios, ?string $fecha = null, bool $anulado = false): Abono
{
    $total = Decimal::cero();

    foreach ($medios as $medio) {
        $total = $total->sumar($medio[1]);
    }

    $anulacion = $anulado
        ? [
            'estado'           => EstadoAbono::Anulado->value,
            'anulado_en'       => now(),
            'anulado_por'      => User::factory()->create()->id,
            'motivo_anulacion' => 'RECIBO DE PRUEBA ANULADO',
        ]
        : [];

    $abono = Abono::factory()->create(array_merge([
        'cuenta_id'       => cuentaParaElReporte()->id,
        'total'           => $total->redondeado(2),
        'fecha_operacion' => $fecha ?? diaDelMesParaElReporte(4),
    ], $anulacion));

    foreach ($medios as $medio) {
        AbonoMedio::factory()->create([
            'abono_id' => $abono->id,
            'forma'    => $medio[0]->value,
            'monto'    => $medio[1],
            'banco'    => $medio[2] ?? null,
        ]);
    }

    return $abono;
}

/**
 * @return array{palig: Convenio, militar: Convenio}
 */
function unMesParaElReporte(): array
{
    $rango = RangoCai::factory()->create();

    $palig = Convenio::factory()->create([
        'codigo'       => 'PALIG',
        'nombre'       => 'PAN-AMERICAN LIFE',
        'tipo'         => TipoConvenio::AseguradoraPrivada,
        'dias_credito' => null,
    ]);

    $militar = Convenio::factory()->create([
        'codigo'       => 'MILITAR',
        'nombre'       => 'HOSPITAL MILITAR',
        'tipo'         => TipoConvenio::Institucional,
        'dias_credito' => 30,
    ]);

    $reembolso = Convenio::factory()->reembolso()->create(['codigo' => 'REEMB']);

    facturaParaElReporte($rango, 1, '1000.00', '800.00', $palig);
    facturaParaElReporte($rango, 2, '500.00', '400.00', $palig);
    facturaParaElReporte($rango, 3, '2000.00', '1500.00', $militar);
    facturaParaElReporte($rango, 4, '345.50');
    facturaParaElReporte($rango, 5, '600.00', '0.00', $reembolso);
    facturaParaElReporte($rango, 6, '999.00', '500.00', $palig, anulada: true);
    facturaParaElReporte($rango, 7, '700.00', fecha: now()->startOfMonth()->subDays(3)->toDateString());

    reciboParaElReporte([[FormaDePago::Efectivo, '200.00'], [FormaDePago::Tarjeta, '300.00']]);
    reciboParaElReporte([[FormaDePago::Transferencia, '645.50', 'BANCO ATLANTIDA']]);
    reciboParaElReporte([[FormaDePago::Efectivo, '999.00']], anulado: true);
    reciboParaElReporte([[FormaDePago::Efectivo, '50.00']], fecha: now()->startOfMonth()->subDays(2)->toDateString());

    return ['palig' => $palig, 'militar' => $militar];
}

/**
 * @return array{
 *     facturado: array{cuantas: int, total: string, exento: string, gravado: string, isv: string, descuentos: string, seguros: string, pacientes: string},
 *     anuladas: array{cuantas: int, total: string},
 *     cobrado: array{total: string, recibos: int, formas: list<array{forma: string, etiqueta: string, monto: string, recibos: int, bancos: list<array{banco: string, monto: string}>}>},
 *     seguros: list<array{convenio_id: int, nombre: string, facturas: int, facturado: string, a_credito: string, copago: string, plazo: string}>
 * }
 */
function elReporteDeEsteMes(): array
{
    return app(ResumenDeFacturacion::class)->delPeriodo(
        now()->startOfMonth()->toDateString(),
        now()->endOfMonth()->toDateString(),
    );
}

it('🔴 lo facturado del mes, repartido entre pacientes y seguros, al centavo', function (): void {
    unMesParaElReporte();

    expect(elReporteDeEsteMes()['facturado'])->toBe([
        'cuantas'    => 5,
        'total'      => '4445.50',
        'exento'     => '4445.50',
        'gravado'    => '0.00',
        'isv'        => '0.00',
        'descuentos' => '0.00',
        'seguros'    => '2700.00',
        'pacientes'  => '1745.50',
    ]);
})->note('La anulada y la del mes pasado no suman. El reembolso suma a pacientes: el paciente le paga todo al hospital y él le reclama a su seguro.');

it('las anuladas se cuentan aparte', function (): void {
    unMesParaElReporte();

    expect(elReporteDeEsteMes()['anuladas'])->toBe(['cuantas' => 1, 'total' => '999.00']);
});

it('🔴 lo cobrado en caja va por forma de pago y la transferencia dice el banco', function (): void {
    unMesParaElReporte();

    expect(elReporteDeEsteMes()['cobrado'])->toBe([
        'total'   => '1145.50',
        'recibos' => 2,
        'formas'  => [
            ['forma' => 'efectivo', 'etiqueta' => 'Efectivo', 'monto' => '200.00', 'recibos' => 1, 'bancos' => []],
            ['forma' => 'tarjeta', 'etiqueta' => 'Tarjeta', 'monto' => '300.00', 'recibos' => 1, 'bancos' => []],
            [
                'forma'    => 'transferencia',
                'etiqueta' => 'Transferencia o depósito',
                'monto'    => '645.50',
                'recibos'  => 1,
                'bancos'   => [['banco' => 'BANCO ATLANTIDA', 'monto' => '645.50']],
            ],
        ],
    ]);
})->note('Va por la fecha del recibo: el anulado y el del mes pasado no entran.');

it('🔴 cada seguro con lo que quedo a su credito, el que mas debe arriba', function (): void {
    ['palig' => $palig, 'militar' => $militar] = unMesParaElReporte();

    expect(elReporteDeEsteMes()['seguros'])->toBe([
        [
            'convenio_id' => $militar->id,
            'nombre'      => 'HOSPITAL MILITAR',
            'facturas'    => 1,
            'facturado'   => '2000.00',
            'a_credito'   => '1500.00',
            'copago'      => '500.00',
            'plazo'       => '30 días',
        ],
        [
            'convenio_id' => $palig->id,
            'nombre'      => 'PAN-AMERICAN LIFE',
            'facturas'    => 2,
            'facturado'   => '1500.00',
            'a_credito'   => '1200.00',
            'copago'      => '300.00',
            'plazo'       => 'Sin plazo fijo',
        ],
    ]);
})->note('El reembolso no aparece: no le deja nada a cobrar a ningún seguro. La anulada de PALIG tampoco.');

it('un mes sin movimiento da ceros, no errores', function (): void {
    $reporte = elReporteDeEsteMes();

    expect($reporte['facturado']['total'])->toBe('0.00')
        ->and($reporte['cobrado']['total'])->toBe('0.00')
        ->and($reporte['cobrado']['formas'])->toHaveCount(3)
        ->and($reporte['seguros'])->toBe([]);
});
