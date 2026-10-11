<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\ConsultorDeExistencias;
use App\Services\FormasDeCobro;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/*
 * «¿CÓMO SE LE AGREGA?» — LA TABLETA O LA CAJA SIN ABRIR.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA REGLA (Mauricio, 10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cuando son tabletas debería poderse poner si se dispensa en tableta o
 * caja.» Cada presentación dice si también se vende sin abrir, y al
 * cobrar quien atiende elige. Lo que este archivo cuida:
 *
 *   · que la caja solo multiplique cuando el catálogo la marcó;
 *   · que con la tableta y la caja el sistema NO elija solo;
 *   · que el renglón diga «1 CAJA» aunque FEFO la parta en dos filas;
 *   · que una caja sin abrir no se arme con tabletas de un blíster.
 *
 * ⚠️ Los ayudantes tienen nombres propios: los de Pest son funciones
 * globales, y con `--parallel` dos con el mismo nombre chocan según cómo
 * caiga el reparto.
 */
/**
 * La unidad con ese código, creada una sola vez: el código es único, y
 * dos productos del mismo test se miden en la misma TABLETA.
 */
function unaUnidadLlamada(string $codigo, MagnitudDeMedida $magnitud = MagnitudDeMedida::Conteo): Unidad
{
    $existente = Unidad::query()->where('codigo', $codigo)->first();

    return $existente instanceof Unidad
        ? $existente
        : Unidad::factory()->de($magnitud)->create(['codigo' => $codigo, 'nombre' => $codigo]);
}

function unaTabletaEnCaja(bool $seVendeEntera): ItemPresentacion
{
    $item = Item::factory()->medicamento()->create([
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'unidad_dispensacion_id'    => unaUnidadLlamada('TAB')->id,
    ]);

    Tarifario::factory()->delItem($item)->a('2.0000')->create();

    return ItemPresentacion::factory()
        ->conContenido('100.0000', 'CAJA X 100 TABLETAS')
        ->create([
            'item_id'         => $item->id,
            'unidad_id'       => unaUnidadLlamada('CAJA')->id,
            'se_vende_entera' => $seVendeEntera,
        ]);
}

function unJarabeEnFrasco(bool $seVendeEntero): ItemPresentacion
{
    $item = Item::factory()->medicamento()->create([
        'fraccionable'           => false,
        'unidad_dispensacion_id' => unaUnidadLlamada('ML', MagnitudDeMedida::Volumen)->id,
    ]);

    return ItemPresentacion::factory()
        ->conContenido('120.0000', 'FRASCO X 120 ML')
        ->create([
            'item_id'         => $item->id,
            'unidad_id'       => unaUnidadLlamada('FRASCO')->id,
            'se_vende_entera' => $seVendeEntero,
        ]);
}

/**
 * @return ColeccionDeModelos<int, ItemPresentacion>
 */
function lasPresentacionesDe(Item $item): ColeccionDeModelos
{
    return ItemPresentacion::query()->where('item_id', $item->id)->orderBy('id')->get();
}

/**
 * @param numeric-string $envases
 * @param numeric-string $contenido
 */
function recibirLoteDe(
    Almacen $almacen,
    ItemPresentacion $presentacion,
    string $envases,
    string $contenido,
    string $lote,
    int $meses,
): void {
    app(RegistradorDeRecepcion::class)->registrar(almacen: $almacen, lineas: [
        new LineaRecibida(
            item: $presentacion->item,
            presentacion: $presentacion,
            cantidadPresentacion: Decimal::de($envases),
            unidadesPorPresentacion: Decimal::de($contenido),
            costoPorPresentacion: Decimal::de('100'),
            numeroLote: $lote,
            vencimiento: now()->addMonths($meses),
        ),
    ]);
}

/**
 * Lo que manda la pantalla al vender `$envases` cajas sin abrir: la
 * cantidad ya convertida a tabletas, la caja, y cuántas.
 *
 * @param numeric-string $tabletas
 * @param numeric-string $envases
 *
 * @return Collection<int, Cargo>
 */
function venderSinAbrir(
    Cuenta $cuenta,
    ItemPresentacion $caja,
    Almacen $almacen,
    string $tabletas,
    string $envases,
): Collection {
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $caja->item,
        cantidad: Decimal::de($tabletas),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
        presentacion: $caja,
        envases: Decimal::de($envases),
    ));
}

/*
|--------------------------------------------------------------------------
| Qué formas se ofrecen
|--------------------------------------------------------------------------
*/

it('sin la marca la caja no se ofrece y la tableta se cobra sin preguntar', function (): void {
    $caja = unaTabletaEnCaja(seVendeEntera: false);
    $formas = app(FormasDeCobro::class);

    expect($formas->de($caja->item, lasPresentacionesDe($caja->item)))->toBe([FormasDeCobro::SUELTA])
        ->and($formas->unica($caja->item, lasPresentacionesDe($caja->item)))->toBe(FormasDeCobro::SUELTA);
})->note('Apagada por defecto: una caja que nadie marcó no aparece como botón que multiplica por cien.');

it('🔴 con la marca se elige entre la tableta y la caja, y el sistema no elige solo', function (): void {
    $caja = unaTabletaEnCaja(seVendeEntera: true);
    $formas = app(FormasDeCobro::class);

    expect($formas->de($caja->item, lasPresentacionesDe($caja->item)))
        ->toBe([FormasDeCobro::SUELTA, FormasDeCobro::ENVASE.$caja->id])
        ->and($formas->unica($caja->item, lasPresentacionesDe($caja->item)))->toBeNull();
})->note('🔴 «Escoge cómo se le va a agregar, si la caja o tabletas»: sin forma única, la pantalla pregunta y no deja seguir sin respuesta.');

it('🔴 dos cajas sin abrir son doscientas tabletas y dos envases', function (): void {
    $caja = unaTabletaEnCaja(seVendeEntera: true);
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($caja->item);
    $clave = FormasDeCobro::ENVASE.$caja->id;

    expect($formas->aUnidadesDeDispensacion($caja->item, $clave, Decimal::de('2'), $presentaciones)?->redondeado(4))
        ->toBe('200.0000')
        ->and($formas->envasesEnteros($clave, Decimal::de('2'), $presentaciones)?->redondeado(4))->toBe('2.0000')
        ->and($formas->aUnidadesDeDispensacion($caja->item, FormasDeCobro::SUELTA, Decimal::de('2'), $presentaciones)?->redondeado(4))
        ->toBe('2.0000')
        ->and($formas->envasesEnteros(FormasDeCobro::SUELTA, Decimal::de('2'), $presentaciones))->toBeNull();
})->note('El mismo «2» son dos tabletas o doscientas: por eso la forma la elige una persona y la conversión vive en un solo lugar.');

it('el frasco sin la marca es solo el origen y no multiplica', function (): void {
    $frasco = unJarabeEnFrasco(seVendeEntero: false);
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($frasco->item);
    $clave = FormasDeCobro::ENVASE.$frasco->id;

    expect($formas->de($frasco->item, $presentaciones))->toBe([$clave])
        ->and($formas->aUnidadesDeDispensacion($frasco->item, $clave, Decimal::de('5'), $presentaciones)?->redondeado(4))
        ->toBe('5.0000')
        ->and($formas->envasesEnteros($clave, Decimal::de('5'), $presentaciones))->toBeNull();
})->note('Regla del 3-sep-2026 intacta: «5 del frasco de 120» son 5 ML. Multiplicar era cobrar 600.');

it('con la marca el frasco se vende entero', function (): void {
    $frasco = unJarabeEnFrasco(seVendeEntero: true);
    $presentaciones = lasPresentacionesDe($frasco->item);

    expect(app(FormasDeCobro::class)->aUnidadesDeDispensacion(
        $frasco->item,
        FormasDeCobro::ENVASE.$frasco->id,
        Decimal::de('1'),
        $presentaciones,
    )?->redondeado(4))->toBe('120.0000');
});

it('una forma que no es de este producto no convierte nada', function (): void {
    $caja = unaTabletaEnCaja(seVendeEntera: true);
    $otra = unaTabletaEnCaja(seVendeEntera: true);
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($caja->item);

    expect($formas->aUnidadesDeDispensacion($caja->item, FormasDeCobro::ENVASE.$otra->id, Decimal::de('1'), $presentaciones))
        ->toBeNull()
        ->and($formas->aUnidadesDeDispensacion($caja->item, FormasDeCobro::FRACCION, Decimal::de('1'), $presentaciones))
        ->toBeNull();
})->note('Nulo y no cero: el cero es una cantidad legal, y confundirlos sería cobrar la nada.');

it('en pantalla la caja que multiplica dice sin abrir y la tableta dice por tableta', function (): void {
    $caja = unaTabletaEnCaja(seVendeEntera: true);

    $rotulos = app(CuentasAbiertas::class)->unidadesDeCobro($caja->item);

    expect($rotulos[FormasDeCobro::SUELTA])->toBe('Por TAB')
        ->and($rotulos[FormasDeCobro::ENVASE.$caja->id])->toContain('CAJA X 100')
        ->and($rotulos[FormasDeCobro::ENVASE.$caja->id])->toContain('sin abrir');
})->note('El envase que multiplica y el que solo es origen se escriben distinto: escribirlos igual es cobrar una caja creyendo que se cobró una tableta.');

/*
|--------------------------------------------------------------------------
| Lo que queda asentado
|--------------------------------------------------------------------------
*/

it('🔴 la caja sin abrir descuenta las cien y el cargo dice una caja', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja(seVendeEntera: true);

    recibirLoteDe($almacen, $caja, '3', '100', 'L-CAJA', 12);

    $cargo = venderSinAbrir($cuenta, $caja, $almacen, '100', '1')->firstOrFail();

    expect($cargo->cantidad)->toBe('100.0000')
        ->and($cargo->item_presentacion_id)->toBe($caja->id)
        ->and($cargo->cantidad_presentacion)->toBe('1.0000')
        ->and(app(ConsultorDeExistencias::class)->totalEn($caja->item, $almacen)->redondeado(4))->toBe('200.0000');
});

it('🔴 una caja que FEFO parte en dos lotes sigue sumando una caja', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja(seVendeEntera: true);

    recibirLoteDe($almacen, $caja, '1', '100', 'VENCE-PRIMERO', 2);
    recibirLoteDe($almacen, $caja, '1', '100', 'VENCE-DESPUES', 12);

    /*
     * Cuarenta tabletas sueltas antes: el primer lote queda en 60 y la
     * caja siguiente tiene que salir 60 de uno y 40 del otro.
     */
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $caja->item,
        cantidad: Decimal::de('40'),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ));

    $filas = venderSinAbrir($cuenta, $caja, $almacen, '100', '1');

    $envases = $filas->reduce(
        fn (Decimal $suma, Cargo $cargo): Decimal => $suma->sumar(Decimal::de((string) $cargo->cantidad_presentacion)),
        Decimal::cero(),
    );

    expect($filas)->toHaveCount(2)
        ->and($filas->firstOrFail()->cantidad_presentacion)->toBe('0.6000')
        ->and($filas->reverse()->firstOrFail()->cantidad_presentacion)->toBe('0.4000')
        ->and($envases->redondeado(4))->toBe('1.0000');
})->note('🔴 Con los envases copiados enteros a cada fila, el renglón —que los suma— decía «2 CAJA» por cien tabletas, en la factura que el paciente lee.');

it('🔴 la caja sin abrir no sale de un lote de blister aunque venza antes', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja(seVendeEntera: true);

    $blister = ItemPresentacion::factory()
        ->conContenido('10.0000', 'BLISTER X 10 TABLETAS')
        ->create([
            'item_id'   => $caja->item_id,
            'unidad_id' => unaUnidadLlamada('BLISTER')->id,
        ]);

    recibirLoteDe($almacen, $blister, '10', '10', 'BLISTER-VENCE-ANTES', 2);
    recibirLoteDe($almacen, $caja, '1', '100', 'CAJA-VENCE-DESPUES', 12);

    $cargo = venderSinAbrir($cuenta, $caja, $almacen, '100', '1')->firstOrFail();

    expect($cargo->lote?->numero)->toBe('CAJA-VENCE-DESPUES')
        ->and(app(ConsultorDeExistencias::class)->totalEn($caja->item, $almacen)->redondeado(4))->toBe('100.0000');
})->note('🔴 §9.F9: el cargo tiene que decir el lote que está impreso en la caja que se llevó el paciente. Armada con tabletas del blíster, el retiro de mercado apuntaría al paciente equivocado.');

it('sin cajas en el estante la caja sin abrir no se arma con blisteres', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja(seVendeEntera: true);

    $blister = ItemPresentacion::factory()
        ->conContenido('10.0000', 'BLISTER X 10 TABLETAS')
        ->create([
            'item_id'   => $caja->item_id,
            'unidad_id' => unaUnidadLlamada('BLISTER')->id,
        ]);

    recibirLoteDe($almacen, $blister, '10', '10', 'SOLO-BLISTER', 6);

    expect(fn () => venderSinAbrir($cuenta, $caja, $almacen, '100', '1'))
        ->toThrow(CargoException::class);
});
