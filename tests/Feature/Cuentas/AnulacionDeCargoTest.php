<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCargo;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const MOTIVO_VALIDO = 'Se cargó dos veces por doble escaneo del código';

function elAnulador(): AnuladorDeCargo
{
    return app(AnuladorDeCargo::class);
}

function unaCuentaParaAnular(): Cuenta
{
    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create([
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);
    $expediente = Expediente::factory()->create([
        'sede_id'    => $sede->id,
        'persona_id' => $persona->id,
    ]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Emergencia,
        convenio: Convenio::factory()->contado()->create(),
        sede: $sede,
    );
}

/**
 * @param numeric-string $precio
 */
function unServicioA(string $precio, RegimenIsv $regimen = RegimenIsv::Exento): Item
{
    $item = Item::factory()->create([
        'regimen_isv'               => $regimen,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * @param numeric-string $cantidad
 */
function cargarParaAnular(Cuenta $cuenta, Item $item, string $cantidad = '1', ?Almacen $almacen = null): Cargo
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ))->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Anular = asentar una reversa, nunca borrar
|--------------------------------------------------------------------------
*/

it('anular deja dos filas y la cuenta vuelve a cero', function (): void {
    $cuenta = unaCuentaParaAnular();
    $cargo = cargarParaAnular($cuenta, unServicioA('850.0000'));

    expect($cuenta->refresh()->total)->toBe('850.00');

    $reversa = elAnulador()->anular($cargo, MOTIVO_VALIDO);

    $cargo->refresh();
    $cuenta->refresh();

    expect($cargo->estado)->toBe(EstadoCargo::Anulado)
        ->and($cargo->total)->toBe('850.00')
        ->and($cargo->motivo_anulacion)->toBe(MOTIVO_VALIDO)
        ->and($reversa->estado)->toBe(EstadoCargo::Anulacion)
        ->and($reversa->revierte_a_id)->toBe($cargo->id)
        ->and($reversa->total)->toBe('-850.00')
        ->and($cuenta->total)->toBe('0.00')
        ->and($cuenta->total_paciente)->toBe('0.00');
})->note('🔴 §9.0.3: el original conserva su monto. Restarlo al anularlo obligaría a editarlo, y un cargo editado es evidencia alterada.');

it('la reversa copia el snapshot original, no lo recalcula', function (): void {
    $cuenta = unaCuentaParaAnular();
    $item = unServicioA('1000.0000', RegimenIsv::Gravado15);
    $cargo = cargarParaAnular($cuenta, $item);

    Tarifario::query()->where('item_id', $item->id)->update(['precio' => '2000.0000']);

    $reversa = elAnulador()->anular($cargo, MOTIVO_VALIDO);

    expect($reversa->precio_unitario)->toBe('1000.0000')
        ->and($reversa->base_gravada)->toBe('-1000.00')
        ->and($reversa->isv)->toBe('-150.00')
        ->and($reversa->total)->toBe('-1150.00')
        ->and($cuenta->refresh()->total)->toBe('0.00');
})->note('Recalcular la reversa con el tarifario de hoy dejaría un residuo cada vez que se corrige algo después de un cambio de precios.');

it('la existencia vuelve al mismo lote', function (): void {
    $cuenta = unaCuentaParaAnular();
    $almacen = Almacen::factory()->create();

    $item = Item::factory()->medicamento()->create([
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a('75.0000')->create();

    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [new LineaRecibida(
            item: $item,
            presentacion: null,
            cantidadPresentacion: Decimal::de('30'),
            unidadesPorPresentacion: Decimal::de('1'),
            costoPorPresentacion: Decimal::de('15'),
            numeroLote: 'L-9',
            vencimiento: now()->addYear(),
        )],
    );

    $cargo = cargarParaAnular($cuenta, $item, '8', $almacen);

    expect(app(ConsultorDeExistencias::class)->totalEn($item, $almacen)->redondeado(4))->toBe('22.0000');

    $reversa = elAnulador()->anular($cargo, MOTIVO_VALIDO);

    expect(app(ConsultorDeExistencias::class)->totalEn($item, $almacen)->redondeado(4))->toBe('30.0000')
        ->and($reversa->movimiento_id)->not->toBeNull()
        ->and($reversa->lote_id)->toBe($cargo->lote_id);
})->note('Anular un cargo significa «esto no se consumió». Otra cosa es la devolución de algo ya entregado: §9.F10 es tajante en que un vial reconstituido no vuelve al inventario, y eso vive en el bloque 6.');

it('exige un motivo que explique algo', function (): void {
    $cuenta = unaCuentaParaAnular();
    $cargo = cargarParaAnular($cuenta, unServicioA('100.0000'));

    elAnulador()->anular($cargo, 'error');
})->throws(CargoException::class, 'al menos diez caracteres');

it('no anula directo un cargo ya facturado', function (): void {
    $cuenta = unaCuentaParaAnular();
    $cargo = cargarParaAnular($cuenta, unServicioA('100.0000'));

    DB::table('cargos')
        ->where('id', $cargo->id)
        ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
        ->update(['estado' => EstadoCargo::Facturado->value]);

    elAnulador()->anular($cargo->refresh(), MOTIVO_VALIDO);
})->throws(CargoException::class, 'nota de crédito')
    ->note('§8.6.4: la corrección de un documento fiscal emitido es una nota de crédito, que consume su propio CAI. Eso es el bloque 7 y hoy está bloqueado por las consultas al SAR (§8.11-1, §8.11-4).');

it('no se anula dos veces el mismo cargo', function (): void {
    $cuenta = unaCuentaParaAnular();
    $cargo = cargarParaAnular($cuenta, unServicioA('100.0000'));

    elAnulador()->anular($cargo, MOTIVO_VALIDO);
    elAnulador()->anular($cargo->refresh(), MOTIVO_VALIDO);
})->throws(CargoException::class);

it('deja la cuenta cuadrada despues de anular una linea de varias', function (): void {
    $cuenta = unaCuentaParaAnular();

    cargarParaAnular($cuenta, unServicioA('500.0000'));
    $aAnular = cargarParaAnular($cuenta, unServicioA('300.0000'));
    cargarParaAnular($cuenta, unServicioA('200.0000', RegimenIsv::Gravado15));

    expect($cuenta->refresh()->total)->toBe('1030.00');

    elAnulador()->anular($aAnular, MOTIVO_VALIDO);

    $cuenta->refresh();
    $recalculado = $cuenta->recalcular();

    expect($cuenta->total)->toBe('730.00')
        ->and($recalculado['total'])->toBe($cuenta->total)
        ->and($recalculado['total_isv'])->toBe($cuenta->total_isv);
});

it('la reversa se fecha el dia de la correccion, no el del cargo original', function (): void {
    $cuenta = unaCuentaParaAnular();

    /*
     * `Carbon::setTestNow()` y no `travelTo()`: esa función no existe en
     * el espacio global —es un método del TestCase— así que el análisis
     * estático no la encuentra y el test se cae en runtime.
     */
    $haceUnaSemana = now()->subDays(7);

    Carbon::setTestNow($haceUnaSemana);
    $cargo = cargarParaAnular($cuenta, unServicioA('600.0000'));
    Carbon::setTestNow();

    $reversa = elAnulador()->anular($cargo, MOTIVO_VALIDO);

    expect($cargo->fecha_operacion->toDateString())->toBe($haceUnaSemana->toDateString())
        ->and($reversa->fecha_operacion->toDateString())->toBe(now()->toDateString())
        ->and($reversa->revierte_a_id)->toBe($cargo->id)
        ->and($cuenta->refresh()->total)->toBe('0.00');
})->note('Si la reversa se fechara en el día original, el corte de caja y el ISV de un mes ya declarado cambiarían hacia atrás, y el consumo de inventario —que sí ocurre hoy— dejaría de cuadrar contra el cargo. El enlace al día original vive en revierte_a_id.');
