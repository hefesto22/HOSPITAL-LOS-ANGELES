<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Enums\RubroDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Item;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\AplicadorDeDescuentoDeLaCuenta;
use App\Services\CotizadorDePresupuesto;
use App\Services\RegistradorDeCargo;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Support\Str;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL DESCUENTO DE LA CUENTA TAMBIÉN ALCANZA A LA CIRUGÍA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «el descuento se maneja siempre de acá; en el presupuesto
 * solo se modifican los precios, pero el descuento se calcula acá».
 *
 * Antes un 5 % en servicios sobre una cuenta que solo tenía una
 * apendicectomía no hacía nada. Ahora el renglón de la cirugía se rebaja
 * en la parte de lo suyo que es de cada rubro:
 *
 *   · servicios: lo que es servicio, la holgura y lo agregado a mano;
 *   · honorarios: lo que es honorario;
 *   · farmacia sigue su propia regla, sobre lo entregado.
 *
 * En lempiras, el monto se reparte entre los renglones sueltos y la
 * porción del paquete, en proporción, y suma exacto.
 *
 * El paquete de prueba vale L 1,000.00: servicio 600 + honorario 300 +
 * holgura 100. Servicios = 700, honorarios = 300.
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones iguales
 * en archivos distintos chocan.
 */
beforeEach(function (): void {
    (new DescuentosLegalesSeeder)->run();
});

/**
 * Una cuenta de contado con una apendicectomía de L 1,000.00 agregada.
 *
 * @return array{0: Cuenta, 1: Presupuesto}
 */
function unaCuentaConLaApendicectomia(): array
{
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create(), edad: 40);
    $encuentro = Encuentro::query()->findOrFail($cuenta->encuentro_id);

    $presupuesto = Presupuesto::factory()
        ->delEncuentro($encuentro)
        ->conPagador($cuenta->convenio)
        ->create();

    unRenglonDelPaquete($presupuesto, Item::factory()->create(['se_almacena' => false]), '600.00', 10);
    unRenglonDelPaquete($presupuesto, Item::factory()->create(['tipo' => TipoItem::Honorario, 'se_almacena' => false]), '300.00', 20);

    PresupuestoLinea::factory()->holgura('100.00')->create([
        'presupuesto_id' => $presupuesto->id,
        'orden'          => 30,
    ]);

    app(CotizadorDePresupuesto::class)->recalcular($presupuesto);
    app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($presupuesto->refresh());

    return [$cuenta->refresh(), $presupuesto->refresh()];
}

function unRenglonDelPaquete(Presupuesto $presupuesto, Item $item, string $monto, int $orden): PresupuestoLinea
{
    return PresupuestoLinea::factory()->create([
        'presupuesto_id'  => $presupuesto->id,
        'item_id'         => $item->id,
        'orden'           => $orden,
        'precio_unitario' => $monto,
        'bruto'           => $monto,
        'subtotal'        => $monto,
        'base_exenta'     => $monto,
        'total'           => $monto,
    ]);
}

function elRenglonDeLaCirugia(Presupuesto $presupuesto): Cargo
{
    return app(AgregadorDePresupuestoALaCuenta::class)->cargoVigente($presupuesto)
        ?? throw new RuntimeException('La cirugía tenía que estar en la cuenta.');
}

/**
 * Un servicio cobrado suelto en la cuenta, fuera del paquete.
 *
 * @param numeric-string $precio
 */
function unServicioSueltoEnLaCuenta(Cuenta $cuenta, string $precio): Cargo
{
    $item = Item::factory()->create(['se_almacena' => false]);
    Tarifario::factory()->delItem($item)->a($precio)->create();

    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ))->firstOrFail();
}

function elDescuentoVivoDe(Cuenta $cuenta, int $itemId): ?string
{
    $valor = $cuenta->cargos()
        ->where('item_id', $itemId)
        ->where('estado', EstadoCargo::Pendiente->value)
        ->value('descuento_comercial');

    return is_string($valor) ? $valor : null;
}

it('la holgura y lo agregado a mano cuentan como servicios', function (): void {
    $holgura = new PresupuestoLinea(['item_id' => null, 'origen' => OrigenLineaPresupuesto::Holgura]);
    $manual = new PresupuestoLinea(['item_id' => null, 'origen' => OrigenLineaPresupuesto::Manual]);

    expect(AplicadorDeDescuentoDeLaCuenta::rubroDelRenglon($holgura))->toBe(RubroDeDescuento::Servicios)
        ->and(AplicadorDeDescuentoDeLaCuenta::rubroDelRenglon($manual))->toBe(RubroDeDescuento::Servicios);
});

it('🔴 un 5 % en servicios rebaja la cirugía en el 5 % de su parte de servicios', function (): void {
    [$cuenta, $presupuesto] = unaCuentaConLaApendicectomia();

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        Decimal::de('0.05'),
        null,
        'AUTORIZADO POR EL HOSPITAL',
        User::factory()->create()->id,
    );

    $cirugia = elRenglonDeLaCirugia($presupuesto);

    expect($cirugia->bruto)->toBe('1000.00')
        ->and($cirugia->descuento_comercial)->toBe('35.00')
        ->and($cirugia->total)->toBe('965.00')
        ->and($cirugia->motivo_descuento)->toContain('SERVICIOS');
});

it('servicios y honorarios se suman en el mismo renglón, y quitar uno deja el otro', function (): void {
    [$cuenta, $presupuesto] = unaCuentaConLaApendicectomia();
    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
    $quien = User::factory()->create()->id;

    $aplicador->fijar($cuenta, RubroDeDescuento::Servicios, Decimal::de('0.05'), null, 'AUTORIZADO POR EL HOSPITAL', $quien);
    $aplicador->fijar($cuenta->refresh(), RubroDeDescuento::Honorarios, Decimal::de('0.10'), null, 'AUTORIZADO POR EL DOCTOR', $quien);

    expect(elRenglonDeLaCirugia($presupuesto)->descuento_comercial)->toBe('65.00');

    $aplicador->quitar($cuenta->refresh(), RubroDeDescuento::Servicios);

    expect(elRenglonDeLaCirugia($presupuesto)->descuento_comercial)->toBe('30.00');

    $aplicador->quitar($cuenta->refresh(), RubroDeDescuento::Honorarios);

    expect(elRenglonDeLaCirugia($presupuesto)->descuento_comercial)->toBe('0.00');
});

it('🔴 en lempiras el monto se reparte entre lo suelto y la cirugía, y suma exacto', function (): void {
    [$cuenta, $presupuesto] = unaCuentaConLaApendicectomia();
    $suelto = unServicioSueltoEnLaCuenta($cuenta, '300.00');

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta->refresh(),
        RubroDeDescuento::Servicios,
        null,
        Decimal::de('100'),
        'AUTORIZADO POR EL HOSPITAL',
        User::factory()->create()->id,
    );

    /*
     * Suelto 300, servicios de la cirugía 700: 100 × 300 / 1000 = 30, y
     * 100 × 700 / 1000 = 70.
     */
    expect(elDescuentoVivoDe($cuenta, $suelto->item_id))->toBe('30.00')
        ->and(elRenglonDeLaCirugia($presupuesto)->descuento_comercial)->toBe('70.00');
});

it('si el presupuesto cambia, la rebaja de la cirugía sigue a su parte nueva', function (): void {
    [$cuenta, $presupuesto] = unaCuentaConLaApendicectomia();

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        Decimal::de('0.05'),
        null,
        'AUTORIZADO POR EL HOSPITAL',
        User::factory()->create()->id,
    );

    unRenglonDelPaquete($presupuesto, Item::factory()->create(['se_almacena' => false]), '200.00', 40);
    app(CotizadorDePresupuesto::class)->recalcular($presupuesto);
    app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($presupuesto->refresh());

    $cirugia = elRenglonDeLaCirugia($presupuesto);

    expect($cirugia->bruto)->toBe('1200.00')
        ->and($cirugia->descuento_comercial)->toBe('45.00')
        ->and($cirugia->total)->toBe('1155.00');
});

it('con solo la cirugía en la cuenta, el descuento de servicios tiene a qué alcanzar', function (): void {
    [$cuenta] = unaCuentaConLaApendicectomia();
    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);

    expect($aplicador->lineasDe($cuenta, RubroDeDescuento::Servicios))->toBeEmpty()
        ->and($aplicador->alcanzaAAlgo($cuenta, RubroDeDescuento::Servicios))->toBeTrue()
        ->and($aplicador->maximoEnLempiras($cuenta, RubroDeDescuento::Servicios)->redondeado(2))->toBe('700.00');
});
