<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Abono;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Item;
use App\Models\RangoCai;
use App\Models\Tarifario;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\EmisorDeFactura;
use App\Services\RegistradorDeCargo;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * LA FACTURA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE ARCHIVO EXISTE PARA IMPEDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que un correlativo fiscal se repita o se salte. Es la única regla del
 * sistema que no admite excepción: el SAR audita la SECUENCIA, y un
 * número repetido son dos facturas legalmente iguales circulando, un
 * hueco es una factura que alguien escondió. Las dos se explican con una
 * multa.
 *
 * Todo lo demás de acá —el saldo, el RTN, el CAI vencido— protege lo
 * mismo por otros caminos: que no salga por la impresora un papel que
 * después no valga.
 */
/**
 * @param numeric-string $precio
 */
function unItemFacturable(string $precio, RegimenIsv $regimen = RegimenIsv::Exento): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => $regimen,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

function elContado(): Convenio
{
    return Convenio::factory()->contado()->create([
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreElTotalFacturado,
    ]);
}

function cargarle(Cuenta $cuenta, Item $item, string $cantidad = '1', ?PoliticaCargo $politica = null): void
{
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        politica: $politica,
    ));
}

/**
 * Deja la cuenta saldada sin pasar por caja: el abono real ya tiene sus
 * propias pruebas, y acá lo que se prueba es la factura.
 */
function saldar(Cuenta $cuenta): void
{
    $turno = TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id]);

    Abono::factory()->create([
        'sede_id'   => $cuenta->sede_id,
        'cuenta_id' => $cuenta->id,
        'turno_id'  => $turno->id,
        'total'     => $cuenta->fresh()->total,
    ]);
}

/**
 * Anular exige autor: lo pide el CHECK de la base y lo verifica el
 * servicio antes, con un mensaje que se entiende.
 */
function quienAnula(): int
{
    return (int) User::factory()->create()->getKey();
}

function unRangoPara(Cuenta $cuenta): RangoCai
{
    return RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);
}

function facturar(
    Cuenta $cuenta,
    ?string $documento = null,
    ?TipoIdentificador $tipo = null,
): Factura {
    return app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta,
        cliente: new ClienteDeFactura('JUAN PEREZ', $documento, $tipo),
    );
}

/*
|--------------------------------------------------------------------------
| El correlativo
|--------------------------------------------------------------------------
*/

it('emite con el numero del rango y cierra la cuenta', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('1000.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    expect($factura->numero)->toBe('000-001-01-00000001')
        ->and($factura->correlativo)->toBe(1)
        ->and($factura->total)->toBe('1000.00')
        ->and($factura->lineas)->toBe(1);

    $cuenta->refresh();

    expect($cuenta->estado)->toBe(EstadoCuenta::Cerrada)
        ->and($cuenta->cerrada_en)->not->toBeNull()
        ->and($cuenta->cargos()->where('estado', EstadoCargo::Facturado->value)->count())->toBe(1);
})->note('Facturar ES cerrar: lo que llegue después es cargo tardío, que el sistema siempre acepta, y se resuelve con una factura complementaria.');

it('el numero anulado no se reutiliza', function (): void {
    /*
     * ⚠️ UN solo convenio para las dos cuentas: el código es único en
     * `convenios`, y crear «CONTADO» dos veces revienta contra el índice
     * antes de llegar a lo que este test quiere probar.
     */
    $convenio = elContado();

    $primera = unaCuentaCon($convenio);
    $rango = unRangoPara($primera);

    cargarle($primera, unItemFacturable('100.0000'));
    saldar($primera);

    $uno = facturar($primera);

    app(EmisorDeFactura::class)->anular($uno, 'Se imprimió con el cliente equivocado.', quienAnula());

    $segunda = unaCuentaCon($convenio);
    $segunda->update(['sede_id' => $primera->sede_id]);

    cargarle($segunda, unItemFacturable('100.0000'));
    saldar($segunda);

    $dos = facturar($segunda);

    expect($uno->correlativo)->toBe(1)
        ->and($dos->correlativo)->toBe(2)
        ->and($rango->refresh()->siguiente)->toBe(3);
})->note('Anular no libera el número. El SAR audita la secuencia: un hueco se explica peor que un error.');

/*
|--------------------------------------------------------------------------
| Lo que impide emitir
|--------------------------------------------------------------------------
*/

it('no factura una cuenta que todavia debe', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('500.0000'));

    facturar($cuenta);
})->throws(FacturaException::class, 'debe L 500.00');

it('arriba del umbral exige identificar al cliente', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('20000.0000'));
    saldar($cuenta);

    facturar($cuenta);
})->throws(FacturaException::class, 'CONSUMIDOR FINAL');

it('con RTN si deja facturar arriba del umbral', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('20000.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta, documento: '08019000235473', tipo: TipoIdentificador::Rtn);

    expect($factura->cliente_documento)->toBe('08019000235473')
        ->and($factura->rotuloDelDocumento())->toBe('RTN')
        ->and($factura->esConsumidorFinal())->toBeFalse();
});

it('la identidad tambien identifica al cliente', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('20000.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta, documento: '0801199012345', tipo: TipoIdentificador::Dni);

    expect($factura->cliente_documento)->toBe('0801199012345')
        ->and($factura->rotuloDelDocumento())->toBe('Identidad');
})->note('Mucha gente nunca sacó RTN. Dejar sin factura a ese paciente es peor que la duda de forma: si el contador confirma que el SAR exige RTN, se apreta una línea del emisor.');

it('el documento no puede llevar guiones', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    facturar($cuenta, documento: '0801-1990-12345', tipo: TipoIdentificador::Dni);
})->throws(FacturaException::class, 'no tiene forma de');

it('no emite con el CAI vencido', function (): void {
    $cuenta = unaCuentaCon(elContado());
    RangoCai::factory()->vencido()->create(['sede_id' => $cuenta->sede_id]);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    facturar($cuenta);
})->throws(FacturaException::class, 'venció');

it('no emite con el rango agotado', function (): void {
    $cuenta = unaCuentaCon(elContado());
    RangoCai::factory()->agotado()->create(['sede_id' => $cuenta->sede_id]);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    facturar($cuenta);
})->throws(FacturaException::class, 'último número');

it('sin rango cargado no se puede facturar', function (): void {
    $cuenta = unaCuentaCon(elContado());

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    facturar($cuenta);
})->throws(FacturaException::class, 'rango de CAI activo');

/*
|--------------------------------------------------------------------------
| Qué entra al papel
|--------------------------------------------------------------------------
*/

it('lo incluido en un paquete no se factura aparte', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('1000.0000'));
    cargarle($cuenta, unItemFacturable('300.0000'), politica: PoliticaCargo::IncluidoEnTarifa);

    saldar($cuenta);

    $factura = facturar($cuenta);

    expect($factura->lineas)->toBe(1)
        ->and($factura->total)->toBe('1000.00');
})->note('Ya está adentro del renglón del paquete (ADR-0009). Volver a imprimirlo sería cobrarlo dos veces en el mismo papel.');

it('separa el gravado del exento como lo pide el SAR', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('1000.0000'));
    cargarle($cuenta, unItemFacturable('100.0000', RegimenIsv::Gravado15));

    saldar($cuenta);

    $factura = facturar($cuenta);

    expect($factura->exento)->toBe('1000.00')
        ->and($factura->gravado)->toBe('100.00')
        ->and($factura->gravado_15)->toBe('100.00')
        ->and($factura->gravado_18)->toBe('0.00')
        ->and($factura->isv_15)->toBe('15.00')
        ->and($factura->total)->toBe('1115.00');
})->note('Las seis casillas van separadas porque el SAR las pide separadas: el impuesto en la casilla equivocada es un hallazgo con multa.');

it('no se anula sin decir quien', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    app(EmisorDeFactura::class)->anular(facturar($cuenta), 'Se arruinó el papel al imprimirlo.');
})->throws(FacturaException::class, 'quién anula');

it('no se puede anular dos veces', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    app(EmisorDeFactura::class)->anular($factura, 'Se arruinó el papel al imprimirlo.', quienAnula());
    app(EmisorDeFactura::class)->anular($factura->refresh(), 'Otra vez, por error.', quienAnula());
})->throws(FacturaException::class, 'ya está anulada');

/*
|--------------------------------------------------------------------------
| Anular deshace el cierre, no el número
|--------------------------------------------------------------------------
*/

it('anular devuelve los cargos y vuelve a abrir la cuenta', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('350.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    expect($cuenta->refresh()->estado)->toBe(EstadoCuenta::Cerrada);

    app(EmisorDeFactura::class)->anular($factura, 'Salió con el cliente equivocado.', quienAnula());

    $cuenta->refresh();

    expect($cuenta->estado)->toBe(EstadoCuenta::Abierta)
        ->and($cuenta->cerrada_en)->toBeNull()
        ->and($cuenta->cerrada_por)->toBeNull()
        ->and($cuenta->cargos()->where('estado', EstadoCargo::Facturado->value)->count())->toBe(0)
        ->and($cuenta->cargos()->where('estado', EstadoCargo::Pendiente->value)->count())->toBe(1);
})->note('Sin esto, anular dejaba la cuenta muerta: cerrada y con todo facturado. Volver a cobrarle a esa paciente obligaba a abrir una cuenta nueva y recargarle a mano lo que ya tenía.');

it('despues de anular se le vuelve a facturar a la misma paciente', function (): void {
    $cuenta = unaCuentaCon(elContado());
    $rango = unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('350.0000'));
    saldar($cuenta);

    $primera = facturar($cuenta);

    app(EmisorDeFactura::class)->anular($primera, 'Se arrugó el papel en la impresora.', quienAnula());

    /*
     * Sin volver a cobrar: el abono sigue aplicado —la plata entró de
     * verdad— así que la cuenta ya está saldada y la segunda emisión
     * pasa el control de saldo sin que nadie pague dos veces.
     */
    $segunda = facturar($cuenta->refresh());

    expect($segunda->correlativo)->toBe(2)
        ->and($segunda->numero)->toBe('000-001-01-00000002')
        ->and($segunda->total)->toBe('350.00')
        ->and($rango->refresh()->siguiente)->toBe(3)
        ->and($cuenta->refresh()->estado)->toBe(EstadoCuenta::Cerrada);
})->note('El número anulado queda consumido y la reemisión sale con el siguiente: la secuencia del SAR no tiene huecos ni repetidos.');

it('la cuenta reabierta vuelve a admitir cargos', function (): void {
    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('350.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    app(EmisorDeFactura::class)->anular($factura, 'Faltaba cargarle la curación.', quienAnula());

    /*
     * Lo que se pidió, en una línea: la cuenta vuelve a estar viva y se
     * le puede seguir cargando antes de facturarla de nuevo. Con la
     * cuenta cerrada esto tiraba `laCuentaNoEstaViva`.
     */
    cargarle($cuenta->refresh(), unItemFacturable('120.0000'));

    expect($cuenta->refresh()->total)->toBe('470.00')
        ->and($cuenta->cargos()->where('estado', EstadoCargo::Pendiente->value)->count())->toBe(2);
})->note('Anular y volver a facturar sirve de poco si en el medio no se le puede agregar lo que faltaba, que es justo por lo que se anula la mayoría de las veces.');

/*
|--------------------------------------------------------------------------
| El periodo declarado no se toca
|--------------------------------------------------------------------------
|
| El hospital declara el mes anterior el dia 10. Todo lo emitido en julio
| se puede anular hasta el 9 de agosto; el 10 se declara julio y esas
| facturas quedan firmes. Anular una despues dejaria lo emitido y lo
| declarado diciendo cosas distintas, y eso se arregla con una
| rectificativa ante el SAR, no con un boton en la caja.
*/

it('el 9 todavia se puede anular la factura del mes pasado', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 7, 15, 10));

    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    Carbon::setTestNow(Carbon::create(2026, 8, 9, 16));

    $anulada = app(EmisorDeFactura::class)->anular(
        $factura->refresh(),
        'Salió con el cliente equivocado.',
        quienAnula(),
    );

    expect($anulada->estado->value)->toBe('anulada');
})->note('Quien llama a las cuatro de la tarde del 9 todavía está a tiempo: el límite es el fin del día, no el mediodía.');

it('el 10 ya no, porque el mes se declaro', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 7, 15, 10));

    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    Carbon::setTestNow(Carbon::create(2026, 8, 10, 8));

    app(EmisorDeFactura::class)->anular($factura->refresh(), 'Salió con el cliente equivocado.', quienAnula());
})->throws(FacturaException::class, 'ya se declaró');

it('la factura del mes en curso se anula sin problema', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 8, 28, 15));

    $cuenta = unaCuentaCon(elContado());
    unRangoPara($cuenta);

    cargarle($cuenta, unItemFacturable('100.0000'));
    saldar($cuenta);

    $factura = facturar($cuenta);

    expect($factura->sePuedeAnular())->toBeTrue()
        ->and($factura->limiteParaAnular()->format('d/m/Y'))->toBe('09/09/2026');
})->note('Lo de agosto vence el 9 de septiembre: el plazo es del mes siguiente, no de los treinta días.');
