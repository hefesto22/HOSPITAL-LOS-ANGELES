<?php

declare(strict_types=1);

use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Models\Item;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Models\Producto;
use App\Models\RangoCai;
use App\Models\Sede;
use App\Services\DesglosadorDePaquete;
use App\Services\EmisorDeFactura;

/**
 * ABRIR EL PAQUETE EN LA FACTURA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE ARCHIVO EXISTE PARA IMPEDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que desglosar mueva un centavo. La factura de una cirugía puede salir
 * como un renglón o como diecinueve, y **el total tiene que ser el mismo
 * papel a papel**. Si la suma de las líneas no da el total del cargo, la
 * factura sale descuadrada contra la cuenta, contra el abono que ya se
 * recibió y contra lo que la aseguradora va a adjudicar.
 *
 * Y que se le facture a nadie algo que no se le hizo: lo que farmacia no
 * despachó no aparece, aunque estuviera presupuestado (§8.7-8).
 */

/**
 * Una cuenta y su presupuesto, sobre el MISMO convenio.
 *
 * ⚠️ El convenio se crea UNA vez y se les pasa a las dos factories.
 * `CuentaFactory` y `PresupuestoFactory` crean cada una su
 * `Convenio::factory()->contado()`, y `convenios.codigo` es único:
 * dejarlas hacerlo solas revienta con «duplicate key CONTADO» en la
 * segunda. Lo mismo vale para los cargos, que van todos a esta cuenta.
 *
 * @return array{0: Cuenta, 1: Presupuesto}
 */
function unaCirugiaEnCurso(?Convenio $convenio = null, ?Sede $sede = null): array
{
    $convenio ??= Convenio::factory()->contado()->create();

    /*
     * ⚠️ La sede se puede compartir, y hace falta: el número de factura
     * sale del rango del SAR de la sede y `facturas_numero_unico` es
     * global. Dos cuentas en sedes distintas, cada una con su rango
     * recién creado, generan las dos el 000-001-01-00000001.
     */
    $cuenta = Cuenta::factory()->create(array_filter([
        'convenio_id' => $convenio->id,
        'sede_id'     => $sede?->id,
    ]));

    $presupuesto = Presupuesto::factory()->agregado()->create(['convenio_id' => $convenio->id]);

    return [$cuenta, $presupuesto];
}

/** Un renglón que se PRESTA: no sale de un estante, así que no espera despacho. */
function unServicioPresupuestado(
    Presupuesto $presupuesto,
    string $texto,
    string $cantidad,
    string $precio,
    RegimenIsv $regimen = RegimenIsv::Exento,
): PresupuestoLinea {
    $bruto = Decimal::de($cantidad)->por($precio)->redondeado(2);

    return PresupuestoLinea::factory()->create([
        'presupuesto_id'  => $presupuesto->id,
        'item_id'         => Item::factory()->create(['se_almacena' => false])->id,
        'texto'           => $texto,
        'cantidad'        => $cantidad,
        'precio_unitario' => $precio,
        'regimen_isv'     => $regimen,
        'bruto'           => $bruto,
        'subtotal'        => $bruto,
        'base_exenta'     => $bruto,
        'total'           => $bruto,
    ]);
}

/** Un renglón que SALE DE FARMACIA: espera despacho real. */
function unMedicamentoPresupuestado(
    Presupuesto $presupuesto,
    string $texto,
    string $cantidad,
    string $precio,
): PresupuestoLinea {
    $bruto = Decimal::de($cantidad)->por($precio)->redondeado(2);

    return PresupuestoLinea::factory()->create([
        'presupuesto_id'  => $presupuesto->id,
        'item_id'         => Producto::factory()->create()->id,
        'texto'           => $texto,
        'cantidad'        => $cantidad,
        'precio_unitario' => $precio,
        'bruto'           => $bruto,
        'subtotal'        => $bruto,
        'base_exenta'     => $bruto,
        'total'           => $bruto,
    ]);
}

/** Lo que farmacia entregó de verdad contra ese renglón. */
function seEntregaron(Cuenta $cuenta, PresupuestoLinea $linea, string $cantidad): Cargo
{
    return Cargo::factory()->enLaCuenta($cuenta)->create([
        'presupuesto_id'       => $linea->presupuesto_id,
        'presupuesto_linea_id' => $linea->id,
        'item_id'              => $linea->item_id,
        'texto'                => $linea->texto,
        'cantidad'             => $cantidad,
        'politica_cargo'       => PoliticaCargo::IncluidoEnTarifa->value,
    ]);
}

/**
 * El renglón cobrable de la cirugía: `presupuesto_id` sin
 * `presupuesto_linea_id` (ADR-0009).
 */
function elCargoDeLaCirugia(
    Cuenta $cuenta,
    Presupuesto $presupuesto,
    string $bruto,
    string $descuento = '0.00',
    RegimenIsv $regimen = RegimenIsv::Exento,
): Cargo {
    $subtotal = Decimal::de($bruto)->restar($descuento)->redondeado(2);
    $grava = $regimen !== RegimenIsv::Exento && $regimen !== RegimenIsv::Exonerado;

    $isv = $grava
        ? Decimal::de($subtotal)->por($regimen->tasaComoTexto())->redondeado(2)
        : '0.00';

    $total = Decimal::de($subtotal)->sumar($isv)->redondeado(2);

    return Cargo::factory()->enLaCuenta($cuenta)->create([
        'presupuesto_id'       => $presupuesto->id,
        'presupuesto_linea_id' => null,
        'texto'                => 'APENDICECTOMIA',
        'cantidad'             => '1.0000',
        'precio_unitario'      => $bruto,
        'bruto'                => $bruto,
        'descuento_comercial'  => $descuento,
        'subtotal'             => $subtotal,
        'regimen_isv'          => $regimen->value,
        'tasa_isv'             => $regimen->tasaComoTexto(),
        'base_exenta'          => $grava ? '0.00' : $subtotal,
        'base_gravada'         => $grava ? $subtotal : '0.00',
        'isv'                  => $isv,
        'total'                => $total,
        'porcion_paciente'     => $total,
    ]);
}

/**
 * El desglose, ya sin el nulo encima.
 *
 * `desglosar()` devuelve `?array` a propósito —así el emisor se degrada
 * al renglón de siempre— pero acá siempre tiene que haber líneas. Se
 * afirma una vez y el resto del archivo trabaja con un array de verdad,
 * que además es lo que el nivel 7 necesita para dejar indexar.
 *
 * @return list<array<string, string|null>>
 */
function desgloseDe(Cargo $paquete): array
{
    $lineas = app(DesglosadorDePaquete::class)->desglosar($paquete);

    expect($lineas)->not->toBeNull();

    return $lineas ?? [];
}

/** @param list<array<string, string|null>> $lineas */
function sumaDe(array $lineas, string $columna): string
{
    $suma = Decimal::cero();

    foreach ($lineas as $linea) {
        $suma = $suma->sumar((string) $linea[$columna]);
    }

    return $suma->redondeado(2);
}

it('reparte el paquete sin perder un centavo y el residuo cae en la línea mayor', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'SALA DE RECUPERACION', '1.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'HONORARIOS MEDICO GENERAL', '1.0000', '1000.0000');

    /*
     * Diez mil entre tres no da exacto: 3,333.33 tres veces suman
     * 9,999.99 y falta un centavo. Ese centavo es todo el punto del test
     * — sin despejar la línea mayor, la factura sale por 9,999.99 y no
     * cuadra con la cuenta.
     */
    $lineas = desgloseDe(elCargoDeLaCirugia($cuenta, $presupuesto, '10000.00'));

    expect($lineas)->toHaveCount(3)
        ->and(sumaDe($lineas, 'total'))->toBe('10000.00')
        ->and(sumaDe($lineas, 'bruto'))->toBe('10000.00')
        ->and($lineas[0]['total'])->toBe('3333.34')
        ->and($lineas[1]['total'])->toBe('3333.33')
        ->and($lineas[2]['total'])->toBe('3333.33');
})->note('Las tres valen lo mismo, así que la mayor es la primera. El centavo se despeja ahí y la suma cierra por construcción, no por suerte del redondeo.');

it('lista lo entregado y no lo presupuestado', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'HABITACION POR DIA', '3.0000', '1000.0000');
    PresupuestoLinea::factory()->holgura('800.00')->create(['presupuesto_id' => $presupuesto->id]);

    $entregado = unMedicamentoPresupuestado($presupuesto, 'ACETAMINOFEN TABLETA', '10.0000', '100.0000');
    seEntregaron($cuenta, $entregado, '6.0000');

    /* Presupuestado y jamás despachado: no se le puede cobrar. */
    unMedicamentoPresupuestado($presupuesto, 'OMEPRAZOL VIAL', '5.0000', '200.0000');

    $lineas = desgloseDe(elCargoDeLaCirugia($cuenta, $presupuesto, '5000.00', '500.00'));

    /** @var array<string, array<string, string|null>> $porNombre */
    $porNombre = collect($lineas)->keyBy('descripcion')->all();

    expect($lineas)->toHaveCount(3)
        ->and($porNombre)->not->toHaveKey('OMEPRAZOL VIAL')
        ->and($porNombre['ACETAMINOFEN TABLETA']['cantidad'])->toBe('6.0000')
        ->and($porNombre['HABITACION POR DIA']['cantidad'])->toBe('3.0000')
        ->and($porNombre['HOLGURA DEL PRESUPUESTO']['cantidad'])->toBe('1.0000')
        ->and(sumaDe($lineas, 'total'))->toBe('4500.00')
        ->and(sumaDe($lineas, 'bruto'))->toBe('5000.00')
        ->and(sumaDe($lineas, 'descuento_comercial'))->toBe('500.00')
        ->and(sumaDe($lineas, 'exento'))->toBe('4500.00');
})->note('El omeprazol estaba presupuestado y no salió del estante: facturárselo a una aseguradora que audita el expediente es fraude aunque haya sido descuido (§8.7-8). La habitación y la holgura no se despachan, así que van por lo presupuestado.');

it('el precio unitario impreso hace cuadrar cantidad por precio con el importe', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'HABITACION POR DIA', '3.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '2000.0000');

    $lineas = desgloseDe(elCargoDeLaCirugia($cuenta, $presupuesto, '5000.00'));

    foreach ($lineas as $linea) {
        $recalculado = Decimal::de($linea['cantidad'])->por($linea['precio_unitario'])->redondeado(2);

        expect($recalculado)->toBe($linea['bruto']);
    }
})->note('Si se imprimiera el precio del presupuesto en vez del derivado, cantidad × precio no daría el importe y el papel no cerraría a la vista de quien lo revisa.');

it('se niega a desglosar un paquete que mezcla regímenes de ISV', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'CAFETERIA', '1.0000', '345.0000', RegimenIsv::Gravado15);

    app(DesglosadorDePaquete::class)
        ->desglosar(elCargoDeLaCirugia($cuenta, $presupuesto, '1345.00'));
})->throws(FacturaException::class);

it('devuelve nulo cuando el cargo no es de un paquete', function (): void {
    [$cuenta] = unaCirugiaEnCurso();

    $suelto = Cargo::factory()->enLaCuenta($cuenta)->create();

    expect(app(DesglosadorDePaquete::class)->desglosar($suelto))->toBeNull();
})->note('Degradarse al renglón de siempre y no reventar: con la familia esperando el papel, un desglose que no se puede hacer no puede trabar la factura.');

it('devuelve nulo cuando el presupuesto no dejó nada que listar', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unMedicamentoPresupuestado($presupuesto, 'OMEPRAZOL VIAL', '5.0000', '200.0000');

    expect(app(DesglosadorDePaquete::class)->desglosar(elCargoDeLaCirugia($cuenta, $presupuesto, '5000.00')))
        ->toBeNull();
})->note('Todo pendiente de despacho: no hay sobre qué prorratear, así que la factura sale con el renglón de la cirugía.');

it('cada renglón cumple por sí solo las ecuaciones que la base exige', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '3200.0000');
    unServicioPresupuestado($presupuesto, 'HABITACION POR DIA', '3.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'HONORARIOS MEDICO GENERAL', '1.0000', '800.0000');
    PresupuestoLinea::factory()->holgura('700.00')->create(['presupuesto_id' => $presupuesto->id]);

    $lineas = desgloseDe(elCargoDeLaCirugia($cuenta, $presupuesto, '8000.00', '1200.00'));

    foreach ($lineas as $linea) {
        $subtotal = Decimal::de((string) $linea['bruto'])
            ->restar((string) $linea['descuento_legal'])
            ->restar((string) $linea['descuento_comercial'])
            ->redondeado(2);

        $bases = Decimal::de((string) $linea['exento'])
            ->sumar((string) $linea['gravado'])
            ->redondeado(2);

        /* factura_lineas_bruto_cuadra */
        expect($bases)->toBe($subtotal);

        /* factura_lineas_totales_cuadran */
        expect(Decimal::de($bases)->sumar((string) $linea['isv'])->redondeado(2))
            ->toBe($linea['total']);
    }
})->note('Acá es donde la primera versión se rompió: los siete montos redondeados por separado hacían cerrar cada columna y no la ecuación de adentro de la línea. La base lo rechazó con factura_lineas_bruto_cuadra por un centavo.');

it('un paquete gravado reparte el ISV y no deja nada en exento', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '3200.0000', RegimenIsv::Gravado15);
    unServicioPresupuestado($presupuesto, 'HABITACION POR DIA', '3.0000', '1000.0000', RegimenIsv::Gravado15);
    unServicioPresupuestado($presupuesto, 'HONORARIOS MEDICO GENERAL', '1.0000', '800.0000', RegimenIsv::Gravado15);

    $cargo = elCargoDeLaCirugia($cuenta, $presupuesto, '5000.00', '750.00', RegimenIsv::Gravado15);

    $lineas = desgloseDe($cargo);

    expect(sumaDe($lineas, 'isv'))->toBe($cargo->isv)
        ->and(sumaDe($lineas, 'gravado'))->toBe($cargo->base_gravada)
        ->and(sumaDe($lineas, 'exento'))->toBe('0.00')
        ->and(sumaDe($lineas, 'total'))->toBe($cargo->total);
})->note('El ISV se reparte y no se recalcula por línea: round(gravado × tasa) sumado no da el ISV del cargo, y ahí la factura saldría con el impuesto descuadrado contra la cuenta.');

/*
|--------------------------------------------------------------------------
| El cableado: que desglosar no mueva el total de la factura
|--------------------------------------------------------------------------
*/

/** Los mismos renglones en las dos cuentas, para poder comparar peras con peras. */
function laMismaCirugia(Cuenta $cuenta, Presupuesto $presupuesto): Cargo
{
    unServicioPresupuestado($presupuesto, 'USO SALA DE OPERACIONES', '1.0000', '3200.0000');
    unServicioPresupuestado($presupuesto, 'HABITACION POR DIA', '3.0000', '1000.0000');
    unServicioPresupuestado($presupuesto, 'HONORARIOS MEDICO GENERAL', '1.0000', '800.0000');
    PresupuestoLinea::factory()->holgura('700.00')->create(['presupuesto_id' => $presupuesto->id]);

    $entregado = unMedicamentoPresupuestado($presupuesto, 'ACETAMINOFEN TABLETA', '10.0000', '100.0000');
    seEntregaron($cuenta, $entregado, '7.0000');

    return elCargoDeLaCirugia($cuenta, $presupuesto, '8000.00', '1200.00');
}

/**
 * ⚠️ UNO por sede, no uno por factura. Dos rangos recién creados en la
 * misma sede arrancan los dos en el correlativo 1 y la segunda factura
 * choca contra `facturas_numero_unico`. Compartiendo el rango, la
 * segunda toma el 2, que es lo que pasa en el mostrador.
 */
function unRangoParaLaCirugia(Cuenta $cuenta): RangoCai
{
    return RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
}

function emitirLaFactura(Cuenta $cuenta, ?bool $desglosar): Factura
{
    return app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta,
        cliente: new ClienteDeFactura('JUAN PEREZ'),
        desglosar: $desglosar,
    );
}

it('la factura desglosada cobra exactamente lo mismo que la que no lo está', function (): void {
    $convenio = Convenio::factory()->contado()->create();
    $sede = Sede::factory()->create();

    [$cerrada, $presupuestoCerrado] = unaCirugiaEnCurso($convenio, $sede);
    [$abierta, $presupuestoAbierto] = unaCirugiaEnCurso($convenio, $sede);

    unRangoParaLaCirugia($cerrada);

    laMismaCirugia($cerrada, $presupuestoCerrado);
    laMismaCirugia($abierta, $presupuestoAbierto);

    $paquete = emitirLaFactura($cerrada, desglosar: false);
    $detallada = emitirLaFactura($abierta, desglosar: true);

    /*
     * 🔴 LA GARANTÍA. Si algún día esto falla, el desglose dejó de ser
     * presentación y se volvió aritmética: la factura ya no cuadra
     * contra la cuenta ni contra el abono que se recibió.
     */
    expect($detallada->total)->toBe($paquete->total)
        ->and($detallada->bruto)->toBe($paquete->bruto)
        ->and($detallada->descuento_comercial)->toBe($paquete->descuento_comercial)
        ->and($detallada->exento)->toBe($paquete->exento)
        ->and($detallada->isv)->toBe($paquete->isv);

    /* Y la suma de los renglones impresos da el total del papel. */
    $sumado = Decimal::cero();

    foreach ($detallada->detalle as $linea) {
        $sumado = $sumado->sumar($linea->total);
    }

    expect($sumado->redondeado(2))->toBe($detallada->total);
})->note('El mismo paciente, la misma cirugía y los mismos consumos: lo único distinto es cómo se imprime. El total tiene que ser el mismo número.');

it('guarda si desglosó de verdad, no si se lo pidieron', function (): void {
    [$cuenta, $presupuesto] = unaCirugiaEnCurso();

    unRangoParaLaCirugia($cuenta);
    laMismaCirugia($cuenta, $presupuesto);

    $factura = emitirLaFactura($cuenta, desglosar: true);

    /* Cinco renglones cobrables más el título que nombra la cirugía. */
    expect($factura->paquetes_desglosados)->toBeTrue()
        ->and($factura->lineas)->toBe(5)
        ->and($factura->detalle)->toHaveCount(6);

    /** @var FacturaLinea|null $encabezado */
    $encabezado = $factura->detalle->firstWhere('encabezado', true);

    expect($encabezado)->toBeInstanceOf(FacturaLinea::class)
        ->and($encabezado?->descripcion)->toContain('APENDICECTOMIA')
        ->and($encabezado?->descripcion)->toContain($presupuesto->numero)
        ->and($encabezado?->total)->toBe('0.00');
})->note('`lineas` cuenta lo que cobra: el título no es una cosa que se le hizo al paciente.');

it('sin paquete que abrir, la factura sale como siempre y lo deja anotado', function (): void {
    [$cuenta] = unaCirugiaEnCurso();

    unRangoParaLaCirugia($cuenta);

    Cargo::factory()->enLaCuenta($cuenta)->create(['texto' => 'CONSULTA EXTERNA']);

    $factura = emitirLaFactura($cuenta, desglosar: true);

    expect($factura->paquetes_desglosados)->toBeFalse()
        ->and($factura->lineas)->toBe(1)
        ->and($factura->detalle->first()?->encabezado)->toBeFalse();
})->note('Se pidió desglose y no había nada que desglosar. La columna guarda lo que OCURRIÓ: un true que no se corresponde con las líneas sería peor que no tener la columna.');
