<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\Monto;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Ayudantes — con nombres propios, que Pest carga todo en un solo proceso
|--------------------------------------------------------------------------
*/

function elMotor(): RegistradorDeCargo
{
    return app(RegistradorDeCargo::class);
}

function existenciaDelCargo(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/**
 * @param numeric-string $precio
 */
function unItemAPrecio(
    string $precio,
    RegimenIsv $regimen = RegimenIsv::Exento,
    TipoItem $tipo = TipoItem::Servicio,
    ?Convenio $convenio = null,
    bool $elegible = true,
): Item {
    $item = Item::factory()->create([
        'tipo'                      => $tipo,
        'regimen_isv'               => $regimen,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    if ($convenio instanceof Convenio) {
        Tarifario::factory()
            ->delItem($item)
            ->paraElConvenio($convenio)
            ->a($precio)
            ->create(['elegible' => $elegible]);
    }

    return $item;
}

/**
 * @param numeric-string $precio
 */
function unMedicamentoAPrecio(string $precio, ?Convenio $convenio = null): Item
{
    $item = Item::factory()->medicamento()->create([
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    if ($convenio instanceof Convenio) {
        Tarifario::factory()->delItem($item)->paraElConvenio($convenio)->a($precio)->create();
    }

    return $item;
}

/**
 * ⚠️ `LineaRecibida` habla de PRESENTACIONES, no de unidades sueltas: la
 * conversión caja → tableta ocurre adentro, en un solo lugar. Con
 * `unidadesPorPresentacion` en 1, una «presentación» es una unidad y el
 * test se lee sin sacar la calculadora.
 *
 * @param numeric-string $unidades
 * @param numeric-string $costoUnitario
 */
function unaLineaRecibida(
    Item $item,
    string $unidades,
    string $costoUnitario,
    string $lote,
    CarbonInterface $vence,
): LineaRecibida {
    return new LineaRecibida(
        item: $item,
        presentacion: null,
        cantidadPresentacion: Decimal::de($unidades),
        unidadesPorPresentacion: Decimal::de('1'),
        costoPorPresentacion: Decimal::de($costoUnitario),
        numeroLote: $lote,
        vencimiento: $vence,
    );
}

/**
 * @param list<LineaRecibida> $lineas
 */
function recibirEn(Almacen $almacen, array $lineas): void
{
    app(RegistradorDeRecepcion::class)->registrar(almacen: $almacen, lineas: $lineas);
}

/**
 * @param numeric-string $cantidad
 *
 * @return Collection<int, Cargo>
 */
function cargar(Cuenta $cuenta, Item $item, string $cantidad = '1', ?Almacen $almacen = null): Collection
{
    return elMotor()->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ));
}

/**
 * El primer cargo, ya sin nulo.
 *
 * `Collection::first()` devuelve `Cargo|null` y en nivel 7 eso hace
 * fallar cada acceso encadenado. `firstOrFail()` dice lo mismo que el
 * test asume —que el cargo se asentó— y lo dice en un solo lugar.
 *
 * @param numeric-string $cantidad
 */
function unCargo(Cuenta $cuenta, Item $item, string $cantidad = '1', ?Almacen $almacen = null): Cargo
{
    return cargar($cuenta, $item, $cantidad, $almacen)->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| 🔴 GOLDEN TEST §9.H13.1 — la cuenta mixta, al céntimo
|--------------------------------------------------------------------------
|
| Los números están escritos a mano en el catálogo anti-errores. NO se
| tocan sin recalcularlos a mano: si este test cambia de valores para
| pasar, lo que se rompió es el sistema, no el test.
|
*/

it('arma la cuenta mixta del catalogo anti-errores al centimo', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $estancia = unItemAPrecio('2400.0000', RegimenIsv::Exento, TipoItem::Estancia);
    $hemograma = unItemAPrecio('380.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio);
    $quimica = unItemAPrecio('640.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio);
    $medicamentos = unItemAPrecio('115.5000', RegimenIsv::Exento, TipoItem::Servicio);
    $cafeteria = unItemAPrecio('300.0000', RegimenIsv::Gravado15, TipoItem::Otro);

    cargar($cuenta, $estancia, '2');
    cargar($cuenta, $hemograma);
    cargar($cuenta, $quimica);
    cargar($cuenta, $medicamentos, '15');
    cargar($cuenta, $cafeteria);

    $cuenta->refresh();

    expect($cuenta->total_exento)->toBe('7552.50')
        ->and($cuenta->total_gravado)->toBe('300.00')
        ->and($cuenta->total_isv)->toBe('45.00')
        ->and($cuenta->total)->toBe('7897.50')
        ->and($cuenta->lineas)->toBe(5);

    /*
     * La verificación cruzada que exige el §9.H13.1: exento + base
     * gravada + ISV tiene que dar el total, exacto. La base lo verifica
     * con un CHECK en cada escritura, y este test lo verifica otra vez
     * desde afuera.
     */
    expect(
        bcadd(bcadd($cuenta->total_exento, $cuenta->total_gravado, 2), $cuenta->total_isv, 2)
    )->toBe($cuenta->total);
})->note('§9.H13.1. La estancia, el laboratorio y los medicamentos son EXENTOS por el Art. 15 incisos b y d de la Ley del ISV; la cafetería del acompañante va gravada. Los tres conviven en la misma cuenta, y por eso el ISV es por línea y nunca por factura.');

it('el isv se calcula por linea y no por cuenta', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $exento = unItemAPrecio('1000.0000', RegimenIsv::Exento);
    $gravado = unItemAPrecio('1000.0000', RegimenIsv::Gravado15);

    $unExento = unCargo($cuenta, $exento);
    $unGravado = unCargo($cuenta, $gravado);

    expect($unExento->isv)->toBe('0.00')
        ->and($unExento->base_exenta)->toBe('1000.00')
        ->and($unExento->base_gravada)->toBe('0.00')
        ->and($unExento->total)->toBe('1000.00')
        ->and($unGravado->isv)->toBe('150.00')
        ->and($unGravado->base_exenta)->toBe('0.00')
        ->and($unGravado->base_gravada)->toBe('1000.00')
        ->and($unGravado->total)->toBe('1150.00');
});

/*
|--------------------------------------------------------------------------
| 🔴 El snapshot — la razón de ser de la tabla
|--------------------------------------------------------------------------
*/

it('congela el precio: subir el tarifario no cambia lo ya cobrado', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $item = unItemAPrecio('500.0000');

    $cargo = unCargo($cuenta, $item);

    Tarifario::query()->where('item_id', $item->id)->update(['precio' => '900.0000']);

    $cargo->refresh();
    $cuenta->refresh();

    expect($cargo->precio_unitario)->toBe('500.0000')
        ->and($cargo->total)->toBe('500.00')
        ->and($cuenta->total)->toBe('500.00');
})->note('§8.5-5. Sin snapshot, renegociar con una aseguradora reimprime las facturas del mes pasado con precios nuevos: rechazo del reclamo y hallazgo fiscal.');

it('guarda de donde salio el precio para poder explicarlo despues', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'PALIG']);
    $cuenta = unaCuentaCon($aseguradora);

    $item = unItemAPrecio('500.0000', convenio: $aseguradora);

    $cargo = unCargo($cuenta, $item);

    expect($cargo->tarifario_id)->not->toBeNull()
        ->and($cargo->convenio_id)->toBe($aseguradora->id)
        ->and($cargo->origen_precio->value)->toBe('precio_negociado')
        ->and($cargo->texto)->toBe($item->nombre);
});

it('se niega a cobrar un item sin precio para ese pagador', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $item = Item::factory()->create();

    cargar($cuenta, $item);
})->throws(CargoException::class, 'no tiene precio definido');

/*
|--------------------------------------------------------------------------
| La division paciente / aseguradora, calculada AL MOMENTO del cargo
|--------------------------------------------------------------------------
*/

it('divide cada linea entre paciente y aseguradora al momento de cargarla', function (): void {
    $aseguradora = Convenio::factory()->create([
        'codigo'             => 'PALIG',
        'cobertura_fraccion' => '0.8000',
        'cubre_por_defecto'  => true,
    ]);

    $cuenta = unaCuentaCon($aseguradora);

    $estancia = unItemAPrecio('2400.0000', RegimenIsv::Exento, TipoItem::Estancia, $aseguradora);
    $hemograma = unItemAPrecio('380.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio, $aseguradora);
    $quimica = unItemAPrecio('640.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio, $aseguradora);
    $medicamentos = unItemAPrecio('115.5000', RegimenIsv::Exento, TipoItem::Servicio, $aseguradora);

    /* La cafetería NO es elegible: la póliza no cubre al acompañante. */
    $cafeteria = unItemAPrecio('300.0000', RegimenIsv::Gravado15, TipoItem::Otro, $aseguradora, elegible: false);

    cargar($cuenta, $estancia, '2');
    cargar($cuenta, $hemograma);
    cargar($cuenta, $quimica);
    cargar($cuenta, $medicamentos, '15');
    cargar($cuenta, $cafeteria);

    $cuenta->refresh();

    expect($cuenta->total)->toBe('7897.50')
        ->and($cuenta->total_aseguradora)->toBe('6042.00')
        ->and($cuenta->total_paciente)->toBe('1855.50');

    expect(bcadd($cuenta->total_paciente, $cuenta->total_aseguradora, 2))->toBe($cuenta->total);
})->note('§8.6.3: la división se calcula en el momento del cargo, no al cierre. Calcularla al final significa que nunca se supo cuánto debía el paciente mientras estaba internado — y ya se fue. El deducible anual es del bloque 4b, porque necesita acumuladores por persona y año póliza (§9.H9).');

it('el tope por evento corta la cobertura y el excedente lo paga el paciente', function (): void {
    $aseguradora = Convenio::factory()->create([
        'codigo'             => 'PALIG',
        'cobertura_fraccion' => '0.8000',
        'tope_por_evento'    => '5000.00',
        'cubre_por_defecto'  => true,
    ]);

    $cuenta = unaCuentaCon($aseguradora);

    $estancia = unItemAPrecio('2400.0000', RegimenIsv::Exento, TipoItem::Estancia, $aseguradora);
    $hemograma = unItemAPrecio('380.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio, $aseguradora);
    $quimica = unItemAPrecio('640.0000', RegimenIsv::Exento, TipoItem::EstudioLaboratorio, $aseguradora);
    $medicamentos = unItemAPrecio('115.5000', RegimenIsv::Exento, TipoItem::Servicio, $aseguradora);
    $cafeteria = unItemAPrecio('300.0000', RegimenIsv::Gravado15, TipoItem::Otro, $aseguradora, elegible: false);

    cargar($cuenta, $estancia, '2');
    cargar($cuenta, $hemograma);
    cargar($cuenta, $quimica);
    cargar($cuenta, $medicamentos, '15');
    cargar($cuenta, $cafeteria);

    $cuenta->refresh();

    expect($cuenta->total_aseguradora)->toBe('5000.00')
        ->and($cuenta->total_paciente)->toBe('2897.50')
        ->and($cuenta->total)->toBe('7897.50');
})->note('El tope se lee bajo el candado de la cuenta y se consume línea por línea. Aplicarlo al cerrar obligaría a EDITAR cargos ya asentados, y eso el §9.0.3 no lo permite.');

it('el contado nunca deja porcion de aseguradora', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $cargo = unCargo($cuenta, unItemAPrecio('1000.0000'));

    expect($cargo->elegible)->toBeFalse()
        ->and($cargo->porcion_aseguradora)->toBe('0.00')
        ->and($cargo->porcion_paciente)->toBe('1000.00');
});

/*
|--------------------------------------------------------------------------
| El descuento de ley, resuelto por la edad A LA FECHA DEL SERVICIO
|--------------------------------------------------------------------------
*/

it('aplica el descuento de ley del adulto mayor sobre el total facturado', function (): void {
    $contado = Convenio::factory()->contado()->create([
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreElTotalFacturado,
    ]);

    $cuenta = unaCuentaCon($contado, edad: 65);

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    /*
     * ⚠️ Un estudio de laboratorio y no un medicamento, a propósito.
     *
     * El descuento de ley se resuelve por `categoria_legal_descuento`, no
     * por el tipo, así que cualquier categoría con derecho sirve para
     * probarlo. Y un medicamento arrastraría dos cosas que este test no
     * está probando: el CHECK `items_unidad_obligatoria_si_es_fisico`
     * —todo lo físico necesita unidad de dispensación— y la exigencia de
     * almacén, porque los medicamentos mueven kardex.
     */
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::EstudioLaboratorio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
    ]);

    Tarifario::factory()->delItem($item)->a('400.0000')->create();

    $cargo = unCargo($cuenta, $item);

    expect($cargo->bruto)->toBe('400.00')
        ->and($cargo->descuento_legal)->toBe('100.00')
        ->and($cargo->subtotal)->toBe('300.00')
        ->and($cargo->total)->toBe('300.00')
        ->and($cargo->categoria_legal)->toBe(RangoEdad::Tercera);
})->note('El rango de edad se resuelve contra la fecha del SERVICIO, no contra hoy: el paciente que cumplió sesenta el mes pasado no tenía derecho en la cirugía de marzo.');

/*
|--------------------------------------------------------------------------
| Idempotencia — el cinturón, no el botón deshabilitado
|--------------------------------------------------------------------------
*/

it('el doble clic no cobra dos veces', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $item = unItemAPrecio('250.0000');

    $clave = (string) Str::uuid();

    $linea = fn (): LineaDeCargo => new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: $clave,
    );

    $primera = elMotor()->registrar($cuenta, $linea());
    $segunda = elMotor()->registrar($cuenta, $linea());

    $cuenta->refresh();

    expect($primera->firstOrFail()->id)->toBe($segunda->firstOrFail()->id)
        ->and($cuenta->lineas)->toBe(1)
        ->and($cuenta->total)->toBe('250.00');
})->note('§8.6.2-3: el botón deshabilitado es cortesía; el cinturón es la restricción única en la base.');

/*
|--------------------------------------------------------------------------
| El inventario
|--------------------------------------------------------------------------
*/

it('descuenta del estante por fefo y parte el cargo cuando cruza dos lotes', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $almacen = Almacen::factory()->create();

    $item = unMedicamentoAPrecio('50.0000');

    recibirEn($almacen, [
        unaLineaRecibida($item, '6', '10', 'VENCE-PRIMERO', now()->addMonths(2)),
        unaLineaRecibida($item, '20', '12', 'VENCE-DESPUES', now()->addYear()),
    ]);

    $cargos = cargar($cuenta, $item, '10', $almacen);

    $primero = $cargos->firstOrFail();
    $ultimo = $cargos->reverse()->firstOrFail();

    $cuenta->refresh();

    expect($cargos)->toHaveCount(2)
        ->and($primero->lote?->numero)->toBe('VENCE-PRIMERO')
        ->and($primero->cantidad)->toBe('6.0000')
        ->and($ultimo->lote?->numero)->toBe('VENCE-DESPUES')
        ->and($ultimo->cantidad)->toBe('4.0000')
        ->and($cuenta->total)->toBe('500.00')
        ->and($cuenta->lineas)->toBe(2)
        ->and(existenciaDelCargo()->totalEn($item, $almacen)->redondeado(4))->toBe('16.0000');
})->note('🔴 §9.F9: ante un retiro de mercado el sistema tiene que responder en segundos a qué pacientes se les administró el lote X. Con un solo cargo apuntando a un solo lote, la mitad de esas tabletas quedaría sin trazabilidad.');

it('congela el costo del lote que salio', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $almacen = Almacen::factory()->create();

    $item = unMedicamentoAPrecio('50.0000');

    recibirEn($almacen, [unaLineaRecibida($item, '10', '20', 'L-1', now()->addYear())]);

    $cargo = unCargo($cuenta, $item, '3', $almacen);

    expect($cargo->costo_unitario)->toBe('20.000000')
        ->and($cargo->costo_total)->toBe('60.00')
        ->and($cargo->movimiento_id)->not->toBeNull();
})->note('§8.7-10: el costo se guarda por movimiento y no se recalcula. El promedio móvil cambia con cada entrada, y el margen de un caso de marzo se mide con el costo de marzo.');

it('exige almacen para un item que mueve inventario', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    cargar($cuenta, unMedicamentoAPrecio('50.0000'), '1');
})->throws(CargoException::class, 'de qué almacén sale');

/*
|--------------------------------------------------------------------------
| Append-only
|--------------------------------------------------------------------------
*/

it('un cargo asentado no se puede editar', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $cargo = unCargo($cuenta, unItemAPrecio('100.0000'));

    DB::table('cargos')
        ->where('id', $cargo->id)
        ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
        ->update(['total' => '999.00']);
})->throws(QueryException::class, 'ya está asentado')
    ->note('🔴 §9.0.3. El día que un abogado pida «la cuenta como estaba el 12 de marzo», una tabla mutable convierte al hospital en indefendible.');

it('un cargo no se puede borrar', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $cargo = unCargo($cuenta, unItemAPrecio('100.0000'));

    DB::table('cargos')
        ->where('id', $cargo->id)
        ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
        ->delete();
})->throws(QueryException::class, 'no se borra');

it('el estado solo transiciona por caminos legales', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $cargo = unCargo($cuenta, unItemAPrecio('100.0000'));

    DB::table('cargos')
        ->where('id', $cargo->id)
        ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
        ->update(['estado' => EstadoCargo::Facturado->value]);

    DB::table('cargos')
        ->where('id', $cargo->id)
        ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
        ->update(['estado' => EstadoCargo::Pendiente->value]);
})->throws(QueryException::class, 'Transición no permitida');

/*
|--------------------------------------------------------------------------
| La cuenta y el encuentro mandan
|--------------------------------------------------------------------------
*/

it('una cuenta cerrada no admite cargos nuevos', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $cuenta->forceFill([
        'estado'     => EstadoCuenta::Cerrada,
        'cerrada_en' => now(),
    ])->save();

    cargar($cuenta->refresh(), unItemAPrecio('100.0000'));
})->throws(CuentaException::class, 'no admite cargos nuevos');

it('la cuenta congelada si admite cargos, y los marca como tardios', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    $cuenta->forceFill([
        'estado'       => EstadoCuenta::Congelada,
        'congelada_en' => now(),
    ])->save();

    $cargo = unCargo($cuenta->refresh(), unItemAPrecio('100.0000'));

    expect($cargo->es_tardio)->toBeTrue();
})->note('🔴 §8.6.3: el cargo tardío SIEMPRE debe poder registrarse. Un sistema que rechaza la transfusión de las 23:50 porque la cuenta cerró a las 23:00 genera un expediente falso.');

it('los totales materializados cuadran contra los cargos, uno por uno', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    cargar($cuenta, unItemAPrecio('333.3300'), '3');
    cargar($cuenta, unItemAPrecio('1234.5600', RegimenIsv::Gravado15));
    cargar($cuenta, unItemAPrecio('0.9900'), '7');

    $cuenta->refresh();
    $recalculado = $cuenta->recalcular();

    expect($recalculado['total'])->toBe($cuenta->total)
        ->and($recalculado['total_exento'])->toBe($cuenta->total_exento)
        ->and($recalculado['total_isv'])->toBe($cuenta->total_isv)
        ->and($recalculado['total_paciente'])->toBe($cuenta->total_paciente)
        ->and($recalculado['lineas'])->toBe($cuenta->lineas);
})->note('Misma regla que el kardex: el saldo materializado tiene que poder recalcularse desde cero y dar exacto (§8.7-1).');

/*
|--------------------------------------------------------------------------
| Lo que solo pasa cuando el cargo se parte entre lotes
|--------------------------------------------------------------------------
*/

it('el tope por evento no se pasa aunque la cantidad se sirva de tres lotes', function (): void {
    $aseguradora = Convenio::factory()->create([
        'codigo'             => 'PALIG',
        'cobertura_fraccion' => '0.8000',
        'tope_por_evento'    => '1000.00',
        'cubre_por_defecto'  => true,
    ]);

    $cuenta = unaCuentaCon($aseguradora);
    $almacen = Almacen::factory()->create();

    $item = unMedicamentoAPrecio('500.0000', $aseguradora);

    recibirEn($almacen, [
        unaLineaRecibida($item, '1', '10', 'L-A', now()->addMonth()),
        unaLineaRecibida($item, '1', '10', 'L-B', now()->addMonths(2)),
        unaLineaRecibida($item, '1', '10', 'L-C', now()->addMonths(3)),
    ]);

    $cargos = cargar($cuenta, $item, '3', $almacen);

    $cuenta->refresh();

    expect($cargos)->toHaveCount(3)
        ->and($cuenta->total)->toBe('1500.00')
        ->and($cuenta->total_aseguradora)->toBe('1000.00')
        ->and($cuenta->total_paciente)->toBe('500.00');
})->note('🔴 Si los totales se refrescaran al final del bucle en vez de después de cada fila, las tres líneas leerían el mismo acumulado y cada una se llevaría el tope entero: L 3,000 cubiertos sobre un tope de L 1,000. La glosa llega a los sesenta días, cuando ya no se cobra.');

it('el descuento autorizado se aplica una sola vez aunque haya dos lotes', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $almacen = Almacen::factory()->create();

    $item = unMedicamentoAPrecio('100.0000');

    recibirEn($almacen, [
        unaLineaRecibida($item, '3', '10', 'L-A', now()->addMonth()),
        unaLineaRecibida($item, '7', '10', 'L-B', now()->addYear()),
    ]);

    $cargos = elMotor()->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('10'),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
        descuentoComercial: Monto::de('50.00'),
        motivoDescuento: 'Cortesía autorizada por dirección para paciente referido',
    ));

    $cuenta->refresh();

    expect($cargos)->toHaveCount(2)
        ->and($cuenta->total_descuento)->toBe('50.00')
        ->and($cuenta->total)->toBe('950.00');
})->note('Repartir diez ampollas entre dos lotes no autoriza dos descuentos: sería regalar el doble de lo que alguien firmó.');

it('no cobra nada si no hay existencia suficiente', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);
    $almacen = Almacen::factory()->create();

    $item = unMedicamentoAPrecio('100.0000');

    recibirEn($almacen, [unaLineaRecibida($item, '2', '10', 'L-A', now()->addYear())]);

    expect(fn () => cargar($cuenta, $item, '5', $almacen))
        ->toThrow(CargoException::class, 'No hay suficiente');

    $cuenta->refresh();

    expect($cuenta->lineas)->toBe(0)
        ->and($cuenta->total)->toBe('0.00')
        ->and(existenciaDelCargo()->totalEn($item, $almacen)->redondeado(4))->toBe('2.0000');
})->note('No se escribe la mitad: ni cargo, ni movimiento de kardex. La mitad de un caso de uso ejecutada es peor que ninguna (§9.A13).');

it('no deja cargar una cantidad que se redondea a cero', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $cuenta = unaCuentaCon($contado);

    /*
     * `0.00004` y no `0.00005`: `Decimal::redondeado()` es half-up, así
     * que un cinco en la quinta posición sube a `0.0001` y NO es el caso
     * que este test quiere probar. Cuatro sí baja a `0.0000`.
     */
    cargar($cuenta, unItemAPrecio('100.0000'), '0.00004');
})->throws(CargoException::class, 'mayor que cero')
    ->note('La columna es NUMERIC(14,4). Sin esta validación, una cantidad que redondea a cero pasaría el «mayor que cero» de escala 12 y moriría en el CHECK de la base con un error crudo, ya con el movimiento de kardex creado.');
