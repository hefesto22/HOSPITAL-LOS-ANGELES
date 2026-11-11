<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\Abono;
use App\Models\AbonoMedio;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Models\Item;
use App\Models\Medico;
use App\Models\RangoCai;
use App\Models\User;
use App\Services\ReporteDeHonorarios;
use App\Services\ResumenDeFacturacion;

/*
 * EL REPORTE DEL MES (Mauricio, 6-nov-2026; 11-nov-2026) — al centavo.
 *
 * «Cuánto se facturó ese mes, cuánto se recibió en efectivo, tarjeta y
 * transferencia, y cuánto a seguros; ingresos, después de medicamentos y
 * después de honorarios de médicos. Solo de lo facturado ese mes, no
 * incluye cuentas abiertas.»
 *
 * Un mes armado a mano, cada factura con lo que pagó su paciente:
 *
 *   PALIG (sin plazo)   1,000.00 (seguro 800.00)  → efectivo 200.00
 *                         500.00 (seguro 400.00)  → tarjeta 100.00 que dejó
 *                                                   de anticipo el mes pasado
 *   MILITAR (30 días)   2,000.00 (seguro 1,500.00) → un recibo: tarjeta 300.00
 *                                                   + efectivo 200.00
 *   Particular            345.50  → transferencia 345.50, BANCO ATLANTIDA
 *   Reembolso             600.00  → efectivo 600.00 (paga todo el paciente)
 *   Anulada PALIG         999.00  → aparte; su recibo de 499.00 no entra
 *   Del mes pasado        700.00  → no es de este mes, ni su recibo de 50.00
 *                                   de este mes
 *   Cuenta abierta                → su abono de 75.00 no entra
 *
 *   Facturado 4,445.50 = seguros 2,700.00 + pacientes 1,745.50
 *   Pagado por los pacientes 1,745.50: efectivo 1,000.00 (3 recibos),
 *   tarjeta 400.00 (2), transferencia 345.50 (1). Pendiente 0.00.
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
    ?Cuenta $cuenta = null,
    string $gravado = '0.00',
    string $isv = '0.00',
): Factura {
    $exento = Decimal::de($total)->restar($gravado)->restar($isv);

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
        'cuenta_id'          => ($cuenta ?? cuentaParaElReporte())->id,
        'correlativo'        => $correlativo,
        'numero'             => sprintf('000-001-01-%08d', $correlativo),
        'convenio_id'        => $convenio?->id,
        'fecha_operacion'    => $fecha ?? diaDelMesParaElReporte(3),
        'bruto'              => $exento->sumar($gravado)->redondeado(2),
        'exento'             => $exento->redondeado(2),
        'gravado'            => $gravado,
        'gravado_15'         => $gravado,
        'isv'                => $isv,
        'isv_15'             => $isv,
        'total'              => $total,
        'a_cargo_del_seguro' => $seguro,
    ], $anulacion));
}

/**
 * Un recibo de la cuenta de esa factura; sin factura, de una cuenta que
 * sigue abierta.
 *
 * @param list<array{0: FormaDePago, 1: numeric-string, 2?: string}> $medios
 */
function reciboParaElReporte(array $medios, ?Factura $deLaFactura = null, ?string $fecha = null, bool $anulado = false): Abono
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
        'cuenta_id'       => $deLaFactura->cuenta_id ?? cuentaParaElReporte()->id,
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

    $elMesPasado = now()->startOfMonth()->subDays(3)->toDateString();

    $palig1 = facturaParaElReporte($rango, 1, '1000.00', '800.00', $palig);
    $palig2 = facturaParaElReporte($rango, 2, '500.00', '400.00', $palig);
    $delMilitar = facturaParaElReporte($rango, 3, '2000.00', '1500.00', $militar);
    $particular = facturaParaElReporte($rango, 4, '345.50');
    $deReembolso = facturaParaElReporte($rango, 5, '600.00', '0.00', $reembolso);
    $anulada = facturaParaElReporte($rango, 6, '999.00', '500.00', $palig, anulada: true);
    $delMesPasado = facturaParaElReporte($rango, 7, '700.00', fecha: $elMesPasado);

    reciboParaElReporte([[FormaDePago::Efectivo, '200.00']], $palig1);
    reciboParaElReporte([[FormaDePago::Efectivo, '999.00']], $palig1, anulado: true);
    reciboParaElReporte([[FormaDePago::Tarjeta, '100.00']], $palig2, fecha: $elMesPasado);
    reciboParaElReporte([[FormaDePago::Tarjeta, '300.00'], [FormaDePago::Efectivo, '200.00']], $delMilitar);
    reciboParaElReporte([[FormaDePago::Transferencia, '345.50', 'BANCO ATLANTIDA']], $particular);
    reciboParaElReporte([[FormaDePago::Efectivo, '600.00']], $deReembolso);
    reciboParaElReporte([[FormaDePago::Efectivo, '499.00']], $anulada);
    reciboParaElReporte([[FormaDePago::Efectivo, '50.00']], $delMesPasado);
    reciboParaElReporte([[FormaDePago::Efectivo, '75.00']]);

    return ['palig' => $palig, 'militar' => $militar];
}

/**
 * @return array{
 *     facturado: array{cuantas: int, total: string, exento: string, gravado: string, isv: string, descuentos: string, seguros: string, pacientes: string},
 *     anuladas: array{cuantas: int, total: string},
 *     cobrado: array{total: string, recibos: int, pendiente: string, formas: list<array{forma: string, etiqueta: string, monto: string, recibos: int, bancos: list<array{banco: string, monto: string}>}>},
 *     seguros: list<array{convenio_id: int, nombre: string, facturas: int, facturado: string, a_credito: string, copago: string, plazo: string}>,
 *     resultado: array{ingresos: string, isv: string, medicamentos: array{renglones: int, vendido: string, costo: string, sin_costo: int, vendido_sin_costo: string}, honorarios: array{renglones: int, total: string}, despues_de_medicamentos: string, despues_de_honorarios: string}
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

it('🔴 lo que pagaron los pacientes de las facturas del mes, por forma de pago y con el banco', function (): void {
    unMesParaElReporte();

    expect(elReporteDeEsteMes()['cobrado'])->toBe([
        'total'   => '1745.50',
        'recibos' => 5,
        'formas'  => [
            ['forma' => 'efectivo', 'etiqueta' => 'Efectivo', 'monto' => '1000.00', 'recibos' => 3, 'bancos' => []],
            ['forma' => 'tarjeta', 'etiqueta' => 'Tarjeta', 'monto' => '400.00', 'recibos' => 2, 'bancos' => []],
            [
                'forma'    => 'transferencia',
                'etiqueta' => 'Transferencia o depósito',
                'monto'    => '345.50',
                'recibos'  => 1,
                'bancos'   => [['banco' => 'BANCO ATLANTIDA', 'monto' => '345.50']],
            ],
        ],
        'pendiente' => '0.00',
    ]);
})->note('El anticipo del mes pasado sí entra: es de una factura de este mes. No entran el recibo anulado, el de la factura anulada, el de la factura del mes pasado ni el de la cuenta abierta. Lo pagado cuadra con lo que les tocó a los pacientes.');

it('si a una factura le falta su recibo, el reporte dice cuánto falta', function (): void {
    facturaParaElReporte(RangoCai::factory()->create(), 1, '345.50');

    $cobrado = elReporteDeEsteMes()['cobrado'];

    expect($cobrado['total'])->toBe('0.00')
        ->and($cobrado['pendiente'])->toBe('345.50');
});

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
        ->and($reporte['cobrado']['pendiente'])->toBe('0.00')
        ->and($reporte['cobrado']['formas'])->toHaveCount(3)
        ->and($reporte['seguros'])->toBe([])
        ->and($reporte['resultado']['ingresos'])->toBe('0.00')
        ->and($reporte['resultado']['medicamentos']['costo'])->toBe('0.00')
        ->and($reporte['resultado']['honorarios']['total'])->toBe('0.00')
        ->and($reporte['resultado']['despues_de_honorarios'])->toBe('0.00');
});

/*
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE QUEDA DESPUÉS DE MEDICAMENTOS Y HONORARIOS (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   Factura de ANA MEJIA (de verdad, por el emisor)   1,000.00  honorario
 *   Factura de la cuenta de piso (a mano)             1,265.00  ISV 15.00
 *     · medicamento  500.00   costo 320.00
 *     · insumo       150.00   costo  90.00
 *     · medicamento  200.00   SIN costo
 *     · medicamento dentro del paquete, 0.00, costo 45.00  → cuesta
 *     · medicamento anulado,  costo 60.00                  → no
 *     · medicamento tardío (va en la que sigue), costo 50  → no
 *     · honorario de médico sin médico  300.00             → resta
 *     · honorario del personal          250.00             → no, todavía
 *   Factura anulada, cuenta abierta y factura del mes pasado, con
 *   medicamentos que costaron 500, 1,000 y 700               → no
 *
 *   Facturado 2,265.00 − ISV 15.00 = ingresos 2,250.00
 *   − costo de medicamentos 455.00 (320 + 90 + 45) = 1,795.00
 *   − honorarios de médicos 1,300.00 (1,000 + 300) = 495.00
 *   Sin costo: 1 renglón, vendido en 200.00.
 *
 * Los renglones de la factura a mano no suman su total: aquí se prueba la
 * cuenta del reporte, no el emisor.
 */

/**
 * Un renglón de la cuenta, cuadrado para los CHECK de `cargos`.
 *
 * @param numeric-string $total
 * @param numeric-string|null $costo
 */
function renglonParaElReporte(
    Cuenta $cuenta,
    Item $item,
    string $total,
    ?string $costo,
    EstadoCargo $estado = EstadoCargo::Facturado,
    PoliticaCargo $politica = PoliticaCargo::Cobrable,
): Cargo {
    return Cargo::factory()->enLaCuenta($cuenta)->create([
        'item_id'          => $item->id,
        'precio_unitario'  => $total,
        'bruto'            => $total,
        'subtotal'         => $total,
        'base_exenta'      => $total,
        'total'            => $total,
        'porcion_paciente' => $total,
        'costo_unitario'   => $costo,
        'costo_total'      => $costo,
        'politica_cargo'   => $politica->value,
        'estado'           => $estado->value,
        'motivo_anulacion' => $estado === EstadoCargo::Anulado ? 'SE CARGÓ DE MÁS EN LA PRUEBA' : null,
    ]);
}

/**
 * El honorario ya facturado: el renglón y su línea en la factura, que es
 * por donde lo encuentra «Honorarios por médico».
 *
 * @param numeric-string $total
 */
function honorarioParaElReporte(Factura $factura, Cuenta $cuenta, QuienCobraElHonorario $quien, string $total, int $orden): void
{
    $item = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create(['cobra_el_honorario' => $quien]);

    $cargo = renglonParaElReporte($cuenta, $item, $total, null);

    FacturaLinea::factory()->create([
        'factura_id'      => $factura->id,
        'cargo_id'        => $cargo->id,
        'orden'           => $orden,
        'encabezado'      => false,
        'descripcion'     => 'HONORARIO DE PRUEBA',
        'precio_unitario' => $total,
        'bruto'           => $total,
        'exento'          => $total,
        'total'           => $total,
    ]);
}

function unMedicamentoParaElReporte(): Item
{
    return Item::factory()->medicamento()->create();
}

function elResultadoDelMes(): void
{
    $rango = RangoCai::factory()->create();
    $elMesPasado = now()->startOfMonth()->subDays(3)->toDateString();

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe(Medico::factory()->create(['nombre' => 'ANA MEJIA']), '1000.0000'));

    $dePiso = cuentaParaElReporte();
    $factura = facturaParaElReporte($rango, 1, '1265.00', cuenta: $dePiso, gravado: '100.00', isv: '15.00');

    renglonParaElReporte($dePiso, unMedicamentoParaElReporte(), '500.00', '320.00');
    renglonParaElReporte(
        $dePiso,
        Item::factory()->de(TipoItem::Insumo, CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico)->create(),
        '150.00',
        '90.00',
    );
    renglonParaElReporte($dePiso, unMedicamentoParaElReporte(), '200.00', null);
    renglonParaElReporte($dePiso, unMedicamentoParaElReporte(), '0.00', '45.00', EstadoCargo::Pendiente, PoliticaCargo::IncluidoEnTarifa);
    renglonParaElReporte($dePiso, unMedicamentoParaElReporte(), '80.00', '60.00', EstadoCargo::Anulado);
    renglonParaElReporte($dePiso, unMedicamentoParaElReporte(), '70.00', '50.00', EstadoCargo::Pendiente);
    renglonParaElReporte($dePiso, unServicioDe('400.0000'), '400.00', null);

    honorarioParaElReporte($factura, $dePiso, QuienCobraElHonorario::Medico, '300.00', 2);
    honorarioParaElReporte($factura, $dePiso, QuienCobraElHonorario::Personal, '250.00', 3);

    $deLaAnulada = cuentaParaElReporte();
    facturaParaElReporte($rango, 2, '900.00', anulada: true, cuenta: $deLaAnulada);
    renglonParaElReporte($deLaAnulada, unMedicamentoParaElReporte(), '0.00', '500.00', EstadoCargo::Pendiente, PoliticaCargo::GastoDelServicio);

    renglonParaElReporte(cuentaParaElReporte(), unMedicamentoParaElReporte(), '0.00', '1000.00', EstadoCargo::Pendiente, PoliticaCargo::GastoDelServicio);

    $delMesPasado = cuentaParaElReporte();
    facturaParaElReporte($rango, 3, '800.00', fecha: $elMesPasado, cuenta: $delMesPasado);
    renglonParaElReporte($delMesPasado, unMedicamentoParaElReporte(), '800.00', '700.00');
}

it('🔴 ingresos sin ISV, después de medicamentos y después de honorarios de médicos, al centavo', function (): void {
    elResultadoDelMes();

    expect(elReporteDeEsteMes()['resultado'])->toBe([
        'ingresos'     => '2250.00',
        'isv'          => '15.00',
        'medicamentos' => [
            'renglones'         => 4,
            'vendido'           => '850.00',
            'costo'             => '455.00',
            'sin_costo'         => 1,
            'vendido_sin_costo' => '200.00',
        ],
        'honorarios' => [
            'renglones' => 2,
            'total'     => '1300.00',
        ],
        'despues_de_medicamentos' => '1795.00',
        'despues_de_honorarios'   => '495.00',
    ]);
})->note('El ISV es del SAR. Lo del paquete costó aunque no se cobre aparte. El honorario del personal no se resta todavía, y el del médico va completo.');

it('🔴 los honorarios que resta son los mismos que dice «Honorarios por médico»', function (): void {
    elResultadoDelMes();

    $desde = now()->startOfMonth()->toDateString();
    $hasta = now()->endOfMonth()->toDateString();
    $honorarios = app(ReporteDeHonorarios::class);

    $sinMedico = $honorarios->sinMedico($desde, $hasta);
    $total = $sinMedico['importe'];
    $cuantos = $sinMedico['cuantos'];

    foreach ($honorarios->totalesPorMedico($desde, $hasta) as $delMedico) {
        $total = $total->sumar($delMedico['importe']);
        $cuantos += $delMedico['cuantos'];
    }

    expect(elReporteDeEsteMes()['resultado']['honorarios'])->toBe([
        'renglones' => $cuantos,
        'total'     => $total->redondeado(2),
    ]);
});
