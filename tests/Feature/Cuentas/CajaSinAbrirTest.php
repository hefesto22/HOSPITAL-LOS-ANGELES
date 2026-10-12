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
 *   · que el renglón sume los envases que se vendieron, ni uno más;
 *   · que una caja sin abrir no se arme con tabletas de un blíster, ni
 *     con lo que quedó en una caja abierta;
 *   · y que el blíster SÍ salga de un lote que entró en cajas (Mauricio,
 *     11-oct-2026): «si una caja tiene 100 pastillas y el blíster son
 *     10, se pueden sacar 10 blísteres de una caja».
 *
 * ⚠️ Los ayudantes tienen nombres propios: los de Pest son funciones
 * globales, y con `--parallel` dos con el mismo nombre chocan según cómo
 * caiga el reparto.
 */
/**
 * La unidad con ese código, creada una sola vez: el código es único, y
 * dos productos del mismo test se miden en la misma TABLETA.
 *
 * La CAJA trae envases adentro, como la siembra `UnidadesSeeder`.
 */
function unaUnidadLlamada(string $codigo, MagnitudDeMedida $magnitud = MagnitudDeMedida::Conteo): Unidad
{
    $existente = Unidad::query()->where('codigo', $codigo)->first();

    return $existente instanceof Unidad
        ? $existente
        : Unidad::factory()->de($magnitud)->create([
            'codigo'           => $codigo,
            'nombre'           => $codigo,
            'contiene_envases' => $codigo === 'CAJA',
        ]);
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

/**
 * Lo que el desplegable «¿De dónde sale?» ofrece para esa forma:
 * almacén => «NOMBRE · 9 BLISTER».
 *
 * Por reflexión porque es privado: es lo que se pinta, no una API.
 *
 * @return array<mixed>
 */
function estantesQueOfreceLaPantalla(Item $item, string $forma): array
{
    $estantes = (new ReflectionMethod(CuentasAbiertas::class, 'estantesConLoQueHay'))
        ->invoke(app(CuentasAbiertas::class), $item, $forma);

    return is_array($estantes) ? $estantes : [];
}

/**
 * Tabletas sueltas: es lo que «abre» una caja en el estante.
 *
 * @param numeric-string $tabletas
 */
function venderSueltas(Cuenta $cuenta, Item $item, Almacen $almacen, string $tabletas): void
{
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($tabletas),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
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

it('en pantalla cada forma dice en que se cuenta', function (): void {
    $caja = unaTabletaEnCaja();

    $rotulos = app(CuentasAbiertas::class)->unidadesDeCobro($caja->item);

    expect($rotulos[FormasDeCobro::SUELTA])->toBe('TAB')
        ->and($rotulos[FormasDeCobro::ENVASE.$caja->id])->toBe('CAJA X 100');
})->note('Cortos para que entren uno al lado del otro: con el mismo «1» escrito, una es una tableta y la otra son cien, y el control lo dice antes de apretar.');

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

it('🔴 la caja sin abrir no se arma con lo que quedó de una caja abierta', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();

    recibirLoteDe($almacen, $caja, '1', '100', 'VENCE-PRIMERO', 2);
    recibirLoteDe($almacen, $caja, '1', '100', 'VENCE-DESPUES', 12);

    /*
     * Cuarenta tabletas sueltas antes: la caja del primer lote queda
     * abierta con 60. La caja sin abrir es la del segundo.
     */
    venderSueltas($cuenta, $caja->item, $almacen, '40');

    $filas = venderSinAbrir($cuenta, $caja, $almacen, '100', '1');

    expect($filas)->toHaveCount(1)
        ->and($filas->firstOrFail()->lote?->numero)->toBe('VENCE-DESPUES')
        ->and($filas->firstOrFail()->cantidad)->toBe('100.0000')
        ->and($filas->firstOrFail()->cantidad_presentacion)->toBe('1.0000');
})->note('🔴 Antes salía 60 de la caja abierta y 40 de la cerrada: dos lotes en el cargo por una caja que el paciente se llevó entera de uno, y el kardex con 60 tabletas en un estante donde no quedaba ninguna (§9.F9).');

it('🔴 dos cajas de dos lotes son dos filas de una caja cada una', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();

    recibirLoteDe($almacen, $caja, '1', '100', 'VENCE-PRIMERO', 2);
    recibirLoteDe($almacen, $caja, '2', '100', 'VENCE-DESPUES', 12);

    $filas = venderSinAbrir($cuenta, $caja, $almacen, '200', '2');

    $envases = $filas->reduce(
        fn (Decimal $suma, Cargo $cargo): Decimal => $suma->sumar(Decimal::de((string) $cargo->cantidad_presentacion)),
        Decimal::cero(),
    );

    expect($filas)->toHaveCount(2)
        ->and($filas->firstOrFail()->cantidad_presentacion)->toBe('1.0000')
        ->and($filas->reverse()->firstOrFail()->cantidad_presentacion)->toBe('1.0000')
        ->and($envases->redondeado(4))->toBe('2.0000');
})->note('🔴 Con los envases copiados enteros a cada fila, el renglón —que los suma— decía «4 CAJA» por doscientas tabletas, en la factura que el paciente lee.');

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

/*
|--------------------------------------------------------------------------
| El blíster que sale de una caja
|--------------------------------------------------------------------------
*/

it('🔴 el blister sin abrir sale de un lote que entro en cajas', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $caja, '1', '100', 'L-CAJA', 12);

    $cargo = venderSinAbrir($cuenta, $blister, $almacen, '10', '1')->firstOrFail();

    expect($cargo->lote?->numero)->toBe('L-CAJA')
        ->and($cargo->cantidad)->toBe('10.0000')
        ->and($cargo->item_presentacion_id)->toBe($blister->id)
        ->and($cargo->cantidad_presentacion)->toBe('1.0000')
        ->and(app(ConsultorDeExistencias::class)->totalEn($caja->item, $almacen)->redondeado(4))->toBe('90.0000');
})->note('🔴 Mauricio: «el blíster es una presentación que se vende, pero casi siempre llega en cajas». El cargo dice «1 BLISTER» y el lote impreso en la caja de la que salió.');

it('de una caja abierta salen solo los blisteres que quedan enteros', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $caja, '1', '100', 'L-CAJA', 12);

    venderSueltas($cuenta, $caja->item, $almacen, '5');
    venderSinAbrir($cuenta, $blister, $almacen, '90', '9');

    expect(app(ConsultorDeExistencias::class)->totalEn($caja->item, $almacen)->redondeado(4))->toBe('5.0000')
        ->and(fn () => venderSinAbrir($cuenta, $blister, $almacen, '10', '1'))->toThrow(CargoException::class);
})->note('Las 5 que quedan son el blíster que se rompió para las sueltas: hay tabletas, pero ningún blíster entero.');

it('🔴 un frasco de tabletas no trae blisteres adentro', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();
    $frasco = otroEnvaseDe($caja->item, 'FRASCO', '100.0000', 'FRASCO X 100 TABLETAS');
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $frasco, '1', '100', 'L-FRASCO', 12);

    expect(app(FormasDeCobro::class)->envasesQueLoTraen($blister, lasPresentacionesDe($caja->item)))
        ->toEqualCanonicalizing([$blister->id, $caja->id])
        ->and(fn () => venderSinAbrir($cuenta, $blister, $almacen, '10', '1'))->toThrow(CargoException::class);
})->note('100 es múltiplo de 10, pero en el frasco las tabletas van sueltas: lo que decide es si el envase trae otros adentro, que se marca una vez en Unidades (CAJA sí, FRASCO no).');

it('una caja no trae otra caja ni blisteres que no le caben enteros', function (): void {
    $caja = unaTabletaEnCaja();
    $cajaChica = otroEnvaseDe($caja->item, 'CAJA', '20.0000', 'CAJA X 20 TABLETAS');
    $blisterDe14 = otroEnvaseDe($caja->item, 'BLISTER', '14.0000', 'BLISTER X 14 TABLETAS');
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($caja->item);

    expect($formas->envasesQueLoTraen($cajaChica, $presentaciones))->toBe([$cajaChica->id])
        ->and($formas->envasesQueLoTraen($blisterDe14, $presentaciones))->toBe([$blisterDe14->id]);
})->note('CAJA X 100 y CAJA X 20 son cajas de dos laboratorios, no una adentro de la otra. Y 100 entre 14 no da blísteres enteros.');

it('se cuentan los envases enteros de cada lote', function (): void {
    $caja = unaTabletaEnCaja();
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');
    $formas = app(FormasDeCobro::class);
    $presentaciones = lasPresentacionesDe($caja->item);

    expect($formas->sinAbrirEnElLote($blister, $caja->id, Decimal::de('95'), $presentaciones)->redondeado(4))
        ->toBe('90.0000')
        ->and($formas->sinAbrirEnElLote($caja, $caja->id, Decimal::de('95'), $presentaciones)->redondeado(4))
        ->toBe('0.0000')
        ->and($formas->sinAbrirEnElLote($caja, $caja->id, Decimal::de('160'), $presentaciones)->redondeado(4))
        ->toBe('100.0000')
        ->and($formas->sinAbrirEnElLote($caja, $blister->id, Decimal::de('100'), $presentaciones)->redondeado(4))
        ->toBe('0.0000')
        ->and($formas->sinAbrirEnElLote($blister, null, Decimal::de('100'), $presentaciones)->redondeado(4))
        ->toBe('0.0000');
})->note('De 95 tabletas en cajas salen 9 blísteres y ninguna caja; de 160, una caja —la otra es la abierta—. De un lote de blísteres no sale ninguna caja, y de uno sin envase no sale nada sin abrir.');

it('🔴 la pantalla dice los envases enteros y lo suelto', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();
    $blister = otroEnvaseDe($caja->item, 'BLISTER', '10.0000', 'BLISTER X 10 TABLETAS');

    recibirLoteDe($almacen, $caja, '2', '100', 'L-CAJA', 12);
    venderSueltas($cuenta, $caja->item, $almacen, '5');

    $blisteres = estantesQueOfreceLaPantalla($caja->item, FormasDeCobro::ENVASE.$blister->id);
    $cajas = estantesQueOfreceLaPantalla($caja->item, FormasDeCobro::ENVASE.$caja->id);
    $tabletas = estantesQueOfreceLaPantalla($caja->item, FormasDeCobro::SUELTA);

    expect($blisteres[$almacen->id] ?? null)->toBeString()->toEndWith('· 19 BLISTER + 5 TAB')
        ->and($cajas[$almacen->id] ?? null)->toBeString()->toEndWith('· 1 CAJA + 95 TAB')
        ->and($tabletas[$almacen->id] ?? null)->toBeString()->toEndWith('· 195 TAB');
})->note('🔴 Mauricio: «exacto sería 9 blíster y 5 tabletas». Dos cajas con 5 vendidas sueltas: 19 blísteres enteros y el roto, o una caja cerrada y la abierta. Antes la lista sumaba solo los lotes de blíster, y con todo en cajas decía «no hay».');

it('una caja abierta no se ofrece como caja sin abrir', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $almacen = Almacen::factory()->create();
    $caja = unaTabletaEnCaja();

    recibirLoteDe($almacen, $caja, '1', '100', 'L-CAJA', 12);
    venderSueltas($cuenta, $caja->item, $almacen, '5');

    expect(estantesQueOfreceLaPantalla($caja->item, FormasDeCobro::ENVASE.$caja->id))
        ->not->toHaveKey($almacen->id);
});
