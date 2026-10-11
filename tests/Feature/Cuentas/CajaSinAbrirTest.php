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
 * «Se puede dispensar en envase, caja o tableta» — sin meter datos que
 * no hacen falta. «Se dispensa en» es lo menos que se entrega; cada
 * presentación cargada es un envase que también se puede entregar sin
 * abrir. Al cobrar, quien atiende elige. Lo que este archivo cuida:
 *
 *   · que con la tableta y la caja el sistema NO elija solo;
 *   · que la caja multiplique y la tableta no;
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

/**
 * @param numeric-string $contenido
 */
function otroEnvaseDe(Item $item, string $envase, string $contenido, string $nombre): ItemPresentacion
{
    return ItemPresentacion::factory()
        ->conContenido($contenido, $nombre)
        ->create([
            'item_id'   => $item->id,
            'unidad_id' => unaUnidadLlamada($envase)->id,
        ]);
}

function unaTabletaEnCaja(): ItemPresentacion
{
    $item = Item::factory()->medicamento()->create([
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'unidad_dispensacion_id'    => unaUnidadLlamada('TAB')->id,
    ]);

    Tarifario::factory()->delItem($item)->a('2.0000')->create();

    return otroEnvaseDe($item, 'CAJA', '100.0000', 'CAJA X 100 TABLETAS');
}

function unJarabeEnFrasco(): ItemPresentacion
{
    $item = Item::factory()->medicamento()->create([
        'fraccionable'           => false,
        'unidad_dispensacion_id' => unaUnidadLlamada('ML', MagnitudDeMedida::Volumen)->id,
    ]);

    return otroEnvaseDe($item, 'FRASCO', '120.0000', 'FRASCO X 120 ML');
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

it('🔴 la tableta y la caja se ofrecen sin marcar nada, y el sistema no elige solo', function (): void {
    $caja = unaTabletaEnCaja();
    $formas = app(FormasDeCobro::class);

    expect($formas->de($caja->item, lasPresentacionesDe($caja->item)))
        ->toBe([FormasDeCobro::SUELTA, FormasDeCobro::ENVASE.$caja->id])
        ->and($formas->unica($caja->item, lasPresentacionesDe($caja->item)))->toBeNull();
})->note('🔴 «Escoge cómo se le va a agregar, si la caja o tabletas»: con dos formas, la pantalla pregunta y no deja seguir sin respuesta. Y no hay que configurar nada: la caja ya está cargada en la ficha.');

it('con la caja y el blister se ofrecen los tres', function (): void {
    $caja = unaTabletaEnCaja();
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    expect(app(FormasDeCobro::class)->de($caja->item, lasPresentacionesDe($caja->item)))
        ->toEqualCanonicalizing([
            FormasDeCobro::SUELTA,
            FormasDeCobro::ENVASE.$caja->id,
            FormasDeCobro::ENVASE.$blister->id,
        ]);
});

it('sin envases cargados solo queda la unidad y no hay nada que preguntar', function (): void {
    $sinEnvases = Item::factory()->medicamento()->create([
        'unidad_dispensacion_id' => unaUnidadLlamada('TAB')->id,
    ]);

    expect(app(FormasDeCobro::class)->unica($sinEnvases, lasPresentacionesDe($sinEnvases)))
        ->toBe(FormasDeCobro::SUELTA);
});

it('el envase de una sola unidad no se ofrece: es lo mismo que la unidad', function (): void {
    $ampolla = Item::factory()->medicamento()->create([
        'unidad_dispensacion_id' => unaUnidadLlamada('AMP')->id,
    ]);

    otroEnvaseDe($ampolla, 'AMP', '1.0000', 'AMPOLLA');

    expect(app(FormasDeCobro::class)->de($ampolla, lasPresentacionesDe($ampolla)))
        ->toBe([FormasDeCobro::SUELTA]);
})->note('«AMPOLLA» sin abrir y «Por AMP» cobran igual: dos botones para lo mismo es pedirle a quien cobra que elija entre nada.');

it('lo que no se almacena no ofrece envases', function (): void {
    $servicio = unServicioDe('100.0000');

    otroEnvaseDe($servicio, 'SESION', '4.0000', 'PAQUETE DE 4 SESIONES');

    expect(app(FormasDeCobro::class)->de($servicio, lasPresentacionesDe($servicio)))
        ->toBe([FormasDeCobro::SUELTA]);
})->note('En un honorario o un servicio las presentaciones son variantes, no cajas en un estante.');

it('🔴 dos cajas sin abrir son doscientas tabletas y dos envases', function (): void {
    $caja = unaTabletaEnCaja();
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

it('el jarabe se cobra por ML o por el frasco sin abrir', function (): void {
    $frasco = unJarabeEnFrasco();
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($frasco->item);
    $clave = FormasDeCobro::ENVASE.$frasco->id;

    expect($formas->de($frasco->item, $presentaciones))->toBe([FormasDeCobro::SUELTA, $clave])
        ->and($formas->aUnidadesDeDispensacion($frasco->item, FormasDeCobro::SUELTA, Decimal::de('5'), $presentaciones)?->redondeado(4))
        ->toBe('5.0000')
        ->and($formas->aUnidadesDeDispensacion($frasco->item, $clave, Decimal::de('1'), $presentaciones)?->redondeado(4))
        ->toBe('120.0000');
})->note('Regla del 3-sep-2026 intacta: se vende en lo que dice «Se dispensa en». El frasco cerrado es la otra forma, y la elige quien cobra.');

it('una forma que no es de este producto no convierte nada', function (): void {
    $caja = unaTabletaEnCaja();
    $otra = unaTabletaEnCaja();
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($caja->item);

    expect($formas->aUnidadesDeDispensacion($caja->item, FormasDeCobro::ENVASE.$otra->id, Decimal::de('1'), $presentaciones))
        ->toBeNull()
        ->and($formas->aUnidadesDeDispensacion($caja->item, FormasDeCobro::FRACCION, Decimal::de('1'), $presentaciones))
        ->toBeNull();
})->note('Nulo y no cero: el cero es una cantidad legal, y confundirlos sería cobrar la nada.');

it('en pantalla la caja dice sin abrir y la tableta dice por tableta', function (): void {
    $caja = unaTabletaEnCaja();

    $rotulos = app(CuentasAbiertas::class)->unidadesDeCobro($caja->item);

    expect($rotulos[FormasDeCobro::SUELTA])->toBe('Por TAB')
        ->and($rotulos[FormasDeCobro::ENVASE.$caja->id])->toBe('CAJA X 100 TABLETAS · sin abrir');
})->note('Con el mismo «1» escrito, una es una tableta y la otra son cien: el rótulo lo dice antes de apretar.');

/*
|--------------------------------------------------------------------------
| Lo que queda asentado
|--------------------------------------------------------------------------
*/

it('🔴 la caja sin abrir descuenta las cien y el cargo dice una caja', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();

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
    $caja = unaTabletaEnCaja();

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
    $caja = unaTabletaEnCaja();

    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $blister, '10', '10', 'BLISTER-VENCE-ANTES', 2);
    recibirLoteDe($almacen, $caja, '1', '100', 'CAJA-VENCE-DESPUES', 12);

    $cargo = venderSinAbrir($cuenta, $caja, $almacen, '100', '1')->firstOrFail();

    expect($cargo->lote?->numero)->toBe('CAJA-VENCE-DESPUES')
        ->and(app(ConsultorDeExistencias::class)->totalEn($caja->item, $almacen)->redondeado(4))->toBe('100.0000');
})->note('🔴 §9.F9: el cargo tiene que decir el lote que está impreso en la caja que se llevó el paciente. Armada con tabletas del blíster, el retiro de mercado apuntaría al paciente equivocado.');

it('sin cajas en el estante la caja sin abrir no se arma con blisteres', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();

    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $blister, '10', '10', 'SOLO-BLISTER', 6);

    expect(fn () => venderSinAbrir($cuenta, $caja, $almacen, '100', '1'))
        ->toThrow(CargoException::class);
});
