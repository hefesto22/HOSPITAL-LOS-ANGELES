<?php

declare(strict_types=1);
use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\Monto;
use App\Models\Abono;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Persona;
use App\Models\RangoCai;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\EmisorDeFactura;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDePrestamo;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pest\Expectation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Asigna a qué TestCase apunta cada carpeta. Feature usa el TestCase
| que corre con RefreshDatabase + Laravel app boot. Unit usa el base
| (sin DB) — más rápido, ideal para Value Objects y lógica pura.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Feature/Filament');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Custom expectations específicas del dominio Olympo.
*/

/*
 * Se compara contra un STRING, no contra un float: `Monto` guarda el
 * valor en bcmath y expone el redondeado a dos decimales. Escribir
 * `toBeMonto('150.75')` en vez de `toBeMonto(150.75)` deja a la vista
 * que acá no hay punto flotante en ningún lado.
 */
expect()->extend('toBeMonto', function (string $valor, string $moneda = 'HNL'): Expectation {
    /** @var Monto $monto */
    $monto = $this->value;

    expect($monto)->toBeInstanceOf(Monto::class);
    expect($monto->valor())->toBe($valor);
    expect($monto->moneda)->toBe($moneda);

    return $this;
});

expect()->extend('toBeValidRTN', function (): Expectation {
    expect((string) $this->value)->toMatch('/^\d{14}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function actingAsAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'is_active' => true,
    ]);

    test()->actingAs($user);

    return $user;
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ESTA FUNCIÓN VIVE ACÁ Y NO EN UN ARCHIVO DE TEST
 * ─────────────────────────────────────────────────────────────────────
 *
 * La usan `MotorDeCargosTest` y `DescuentoComercialTest`. Mientras Pest
 * corría en un proceso, definirla en uno de los dos alcanzaba: las
 * funciones sueltas comparten el espacio global.
 *
 * Con `pest --parallel` eso deja de ser cierto. Cada proceso carga SOLO
 * los archivos que le tocan, así que la función existe o no existe según
 * cómo cayó el reparto —y el reparto cambia cuando se agrega un archivo
 * o cuando el timing es otro—. El síntoma es
 * `Call to undefined function` en un test que nadie tocó.
 *
 * `tests/Pest.php` lo carga TODO proceso. Todo ayudante compartido entre
 * dos archivos de test va acá; el que usa un solo archivo se queda en él.
 */
function unaCuentaCon(Convenio $convenio, int $edad = 40): Cuenta
{
    $sede = Sede::factory()->create();

    $persona = Persona::factory()->create([
        'fecha_nacimiento' => now()->subYears($edad)->subDay()->toDateString(),
    ]);

    $expediente = Expediente::factory()->create([
        'sede_id'    => $sede->id,
        'persona_id' => $persona->id,
    ]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Hospitalizacion,
        convenio: $convenio,
        sede: $sede,
    );
}

/**
 * Un pagador que cubre la fracción que se le pida.
 *
 * `0.5000` es el 50 %. Es el seguro con el que se prueba todo lo que
 * tiene que ver con el reparto entre el paciente y la aseguradora.
 */
function unSeguroQueCubre(string $fraccion): Convenio
{
    return Convenio::factory()->create([
        'tipo'                 => TipoConvenio::AseguradoraPrivada,
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,

        /*
         * Con RTN: con seguro, la factura sale con el RTN del convenio y
         * sin él no se emite (10-oct-2026). Un seguro de prueba sin RTN
         * no podría facturar nada.
         */
        'rtn'                   => '08019001234567',
        'cobertura_fraccion'    => $fraccion,
        'cubre_por_defecto'     => true,
        'requiere_autorizacion' => false,
    ]);
}

/**
 * Un servicio exento con su fila de tarifario, listo para cargarse.
 *
 * Exento y sin descuento legal a propósito: quien prueba el reparto de
 * una cuenta no quiere el ISV ni el Art. 30 metidos en el número — esos
 * tienen sus propias pruebas.
 *
 * @param numeric-string $precio
 */
function unServicioDe(string $precio): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * Le carga a la cuenta un servicio de ese precio.
 *
 * @param numeric-string $precio
 */
function conUnServicioDe(Cuenta $cuenta, string $precio): void
{
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: unServicioDe($precio),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ));
}

/**
 * Deja entrada esa plata sin pasar por caja: el abono real tiene sus
 * propias pruebas, y acá lo que se prueba es otra cosa.
 *
 * @param numeric-string $monto
 */
function abonarle(Cuenta $cuenta, string $monto): void
{
    $turno = TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id]);

    Abono::factory()->create([
        'sede_id'   => $cuenta->sede_id,
        'cuenta_id' => $cuenta->id,
        'turno_id'  => $turno->id,
        'total'     => $monto,
    ]);
}

/*
 * Préstamos de medicamento — los usan `PrestamosTest` y
 * `EstanteQueRecibePrestamoTest`. Vivían en el primero y con
 * `--parallel` el segundo reventaba con «Call to undefined function»
 * según cómo cayera el reparto (10-oct-2026).
 */
function elPrestamista(): RegistradorDePrestamo
{
    return app(RegistradorDePrestamo::class);
}

/** Un insumo que sí se almacena y no exige lote. */
function unInsumoDeBodega(): Item
{
    return Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico)
        ->create(['se_almacena' => true]);
}

function elSaldoDe(Item $item, Almacen $almacen): string
{
    $saldo = Existencia::query()
        ->where('item_id', $item->id)
        ->where('almacen_id', $almacen->id)
        ->first();

    return $saldo instanceof Existencia ? $saldo->cantidadDecimal()->redondeado(4) : '0.0000';
}

/**
 * Una cuenta de contado con un honorario de ese médico, sin facturar.
 *
 * Honorarios por médico: la usan `HonorariosPorMedicoTest` y
 * `HonorarioAnuladoEnOtroMesTest` (13-oct-2026), igual que la de abajo.
 *
 * @param numeric-string $precio
 */
function cuentaConHonorarioDe(Medico $medico, string $precio): Cuenta
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);

    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: unServicioDe($precio),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        medicoId: $medico->id,
    ));

    return $cuenta->refresh();
}

/**
 * La cobra entera y la factura.
 *
 * ⚠️ Cada cuenta abre su propia sede, y el rango de la fábrica empieza
 * siempre en el mismo número: sin un establecimiento distinto por
 * factura, la segunda chocaría con el número único de la primera.
 */
function facturarLaCuentaDeHonorarios(Cuenta $cuenta): Factura
{
    static $establecimiento = 100;

    abonarle($cuenta, Decimal::de($cuenta->total)->redondeado(2));

    RangoCai::factory()->create([
        'sede_id'         => $cuenta->sede_id,
        'establecimiento' => (string) ++$establecimiento,
    ]);

    return app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: ClienteDeFactura::consumidorFinal(),
    );
}

/*
|--------------------------------------------------------------------------
| Stock infinito y consumo de productos (13-oct-2026)
|--------------------------------------------------------------------------
|
| Acá y no en `StockInfinitoTest`: con `--parallel` cada proceso carga
| solo sus archivos, y `ConsumoDeProductosTest` no encontraría las
| funciones si vivieran en el otro.
*/

function unaCuentaDeStockInfinito(): Cuenta
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    return unaCuentaCon($contado);
}

function unMedicamentoDeStockInfinito(bool $infinito = true): Item
{
    $item = Item::factory()->medicamento()->create([
        'se_almacena'               => true,
        'stock_infinito'            => $infinito,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a('10.0000')->create();

    return $item;
}

/**
 * @param numeric-string $cantidad
 */
function recibirParaElStockInfinito(Item $item, Almacen $almacen, string $cantidad): void
{
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [new LineaRecibida(
            item: $item,
            presentacion: null,
            cantidadPresentacion: Decimal::de($cantidad),
            unidadesPorPresentacion: Decimal::de('1'),
            costoPorPresentacion: Decimal::de('5'),
            numeroLote: 'L-INF',
            vencimiento: now()->addYear(),
        )],
    );
}

/**
 * @param numeric-string $cantidad
 *
 * @return Collection<int, Cargo>
 */
function cargarConStockInfinito(Cuenta $cuenta, Item $item, string $cantidad, ?Almacen $almacen = null): Collection
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ));
}
