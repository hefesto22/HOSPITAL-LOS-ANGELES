<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\RubroDeDescuento;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\AplicadorDeDescuentoDeLaCuenta;
use App\Services\PoliticaDeDescuentoComercial;
use App\Services\RegistradorDeCargo;
use Carbon\CarbonInterface;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL DESCUENTO EN SERVICIOS (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Si se aplica un descuento por monto o por porcentaje, se aplicaría a
 * todo lo que no sea materiales y medicamentos, ya que ese es descuento
 * exclusivo de medicamentos y materiales.»
 *
 * Lo que este archivo cuida:
 *
 *   · el tope de cada servicio sale de la ley: el joven, 5 puntos
 *     debajo de la tercera edad; el adulto mayor, solo la de ley;
 *   · en porcentaje, cada renglón hasta su tope; en lempiras, el monto
 *     se reparte y lo que un renglón no aguanta pasa a los otros, al
 *     centavo, y si no cabe no se aplica;
 *   · rehacer es rehacer igual: misma fecha del servicio;
 *   · farmacia, honorarios y paquetes no se tocan.
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */
beforeEach(function (): void {
    (new DescuentosLegalesSeeder)->run();
});

/**
 * Un servicio exento de la categoría del Art. 30 que se pida.
 *
 * @param numeric-string $precio
 */
function unServicioDeLaLey(CategoriaLegalDeDescuento $categoria, string $precio): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => $categoria,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

function cargarElServicio(Cuenta $cuenta, Item $item, ?CarbonInterface $cuando = null): Cargo
{
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        ocurridoEn: $cuando,
    ))->firstOrFail();
}

/**
 * La rebaja viva de cada ítem de la cuenta.
 *
 * @return array<int, string>
 */
function lasRebajasEnServicios(Cuenta $cuenta): array
{
    /** @var array<int, string> $rebajas */
    $rebajas = $cuenta->cargos()
        ->where('estado', EstadoCargo::Pendiente->value)
        ->pluck('descuento_comercial', 'item_id')
        ->all();

    return $rebajas;
}

function unJovenDeContado(): Cuenta
{
    return unaCuentaCon(Convenio::factory()->contado()->create(), edad: 40);
}

function unPagadorQueNoDaElArt30(): Convenio
{
    return Convenio::factory()
        ->de(TipoConvenio::AseguradoraPrivada)
        ->conBase(BaseDelDescuentoLegal::NoAplica)
        ->create([
            'cobertura_fraccion'    => '0.5000',
            'cubre_por_defecto'     => true,
            'requiere_autorizacion' => false,
        ]);
}

function quienDaElDescuento(): int
{
    return User::factory()->create()->id;
}

/*
|--------------------------------------------------------------------------
| Los topes
|--------------------------------------------------------------------------
*/

it('🔴 en servicios el joven tiene de tope cinco puntos menos que la tercera edad', function (CategoriaLegalDeDescuento $categoria, string $tope): void {
    $calculado = app(PoliticaDeDescuentoComercial::class)->topeEnLaCuenta(unJovenDeContado(), $categoria, now());

    expect($calculado->redondeado(2))->toBe($tope);
})->with([
    'hospitalización'                 => [CategoriaLegalDeDescuento::ServicioHospitalario, '0.20'],
    'consulta general'                => [CategoriaLegalDeDescuento::ConsultaGeneral, '0.20'],
    'especialista'                    => [CategoriaLegalDeDescuento::ConsultaEspecializada, '0.25'],
    'cirugía'                         => [CategoriaLegalDeDescuento::IntervencionQuirurgica, '0.25'],
    'laboratorio y rayos X'           => [CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '0.25'],
    'fuera del Art. 30, como siempre' => [CategoriaLegalDeDescuento::SinDescuentoLegal, '0.30'],
    'medicamentos, como siempre'      => [CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, '0.30'],
])->note('Con el 30 % de farmacia, en la alimentación un joven pagaba igual que alguien de 85 años y menos que alguien de 65: la tercera edad lleva 25 % de ley.');

it('🔴 en servicios el adulto mayor lleva solo la de ley, y si el pagador no la da se la da el hospital', function (string $pagador, int $edad, string $tope): void {
    $convenio = $pagador === 'contado'
        ? Convenio::factory()->contado()->create()
        : unPagadorQueNoDaElArt30();

    $calculado = app(PoliticaDeDescuentoComercial::class)->topeEnLaCuenta(
        unaCuentaCon($convenio, edad: $edad),
        CategoriaLegalDeDescuento::ServicioHospitalario,
        now(),
    );

    expect($calculado->redondeado(2))->toBe($tope);
})->with([
    'tercera edad de contado'           => ['contado', 65, '0.00'],
    'cuarta edad de contado'            => ['contado', 85, '0.00'],
    'tercera edad, pagador sin Art. 30' => ['aseguradora', 65, '0.25'],
    'cuarta edad, pagador sin Art. 30'  => ['aseguradora', 85, '0.30'],
]);

it('el cargo rechaza en servicios un descuento por encima del tope del joven', function (): void {
    app(RegistradorDeCargo::class)->registrar(unJovenDeContado(), new LineaDeCargo(
        item: unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000'),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercialPorcentaje: Decimal::de('0.21'),
        motivoDescuento: 'Paciente frecuente del hospital',
    ));
})->throws(CargoException::class, 'debajo de lo que la ley le da a la tercera edad');

it('el cargo no deja agregarle descuento del hospital en servicios a la tercera edad de contado', function (): void {
    app(RegistradorDeCargo::class)->registrar(unaCuentaCon(Convenio::factory()->contado()->create(), edad: 65), new LineaDeCargo(
        item: unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000'),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        descuentoComercialPorcentaje: Decimal::de('0.05'),
        motivoDescuento: 'Paciente frecuente del hospital',
    ));
})->throws(CargoException::class, 'en servicios lleva solo el de ley');

/*
|--------------------------------------------------------------------------
| En porcentaje
|--------------------------------------------------------------------------
*/

it('en porcentaje cada servicio lleva lo pedido hasta su tope', function (): void {
    $cuenta = unJovenDeContado();
    $alimentacion = unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000');
    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '400.0000');
    $cafeteria = unServicioDeLaLey(CategoriaLegalDeDescuento::SinDescuentoLegal, '100.0000');

    cargarElServicio($cuenta, $alimentacion);
    cargarElServicio($cuenta, $laboratorio);
    cargarElServicio($cuenta, $cafeteria);

    $topadas = app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        Decimal::de('0.25'),
        null,
        'paciente frecuente del hospital',
        quienDaElDescuento(),
    );

    $rebajas = lasRebajasEnServicios($cuenta);

    expect($rebajas[$alimentacion->id])->toBe('60.00')
        ->and($rebajas[$laboratorio->id])->toBe('100.00')
        ->and($rebajas[$cafeteria->id])->toBe('25.00')
        ->and($topadas)->toBe([$alimentacion->nombre])
        ->and($cuenta->refresh()->total)->toBe('615.00')
        ->and($cuenta->motivo_descuento_servicios)->toBe('PACIENTE FRECUENTE DEL HOSPITAL');
})->note('La alimentación aguanta 20 % y queda en su tope; el laboratorio llega al 25 % pedido. 800 − 60 − 100 − 25 = 615.');

it('salir del campo dos veces no rehace la cuenta entera', function (): void {
    $cuenta = unJovenDeContado();
    cargarElServicio($cuenta, unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '400.0000'));

    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
    $aplicador->fijar($cuenta, RubroDeDescuento::Servicios, Decimal::de('0.10'), null, 'paciente frecuente del hospital', quienDaElDescuento());
    $filas = $cuenta->cargos()->count();

    $aplicador->ponerAlDia($cuenta->refresh(), RubroDeDescuento::Servicios);

    expect($cuenta->cargos()->count())->toBe($filas);
});

it('rehacer es rehacer igual: el servicio de ayer sigue siendo de ayer', function (): void {
    $cuenta = unJovenDeContado();
    $original = cargarElServicio(
        $cuenta,
        unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000'),
        now()->subDay(),
    );

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar($cuenta, RubroDeDescuento::Servicios, Decimal::de('0.10'), null, 'paciente frecuente del hospital', quienDaElDescuento());

    $vivo = $cuenta->cargos()->where('estado', EstadoCargo::Pendiente->value)->firstOrFail();

    expect($vivo->id)->not->toBe($original->id)
        ->and($vivo->descuento_comercial)->toBe('30.00')
        ->and($vivo->ocurrido_en->equalTo($original->ocurrido_en))->toBeTrue()
        ->and($vivo->fecha_operacion->toDateString())->toBe($original->fecha_operacion->toDateString());
})->note('La alimentación del lunes se cobra con el precio del lunes (§8.5-3) y cuenta en el día del lunes.');

it('la linea nueva nace con su parte, topada con su categoria', function (): void {
    $cuenta = unJovenDeContado();
    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
    $aplicador->fijar($cuenta, RubroDeDescuento::Servicios, Decimal::de('0.30'), null, 'paciente frecuente del hospital', quienDaElDescuento());
    $cuenta->refresh();

    $alimentacion = unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000');
    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '400.0000');

    expect($aplicador->fraccionParaLaLineaNueva($cuenta, $alimentacion, now())?->redondeado(2))->toBe('0.20')
        ->and($aplicador->fraccionParaLaLineaNueva($cuenta, $laboratorio, now())?->redondeado(2))->toBe('0.25');
});

it('quitarlo devuelve los servicios a su precio', function (): void {
    $cuenta = unJovenDeContado();
    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '400.0000');
    cargarElServicio($cuenta, $laboratorio);

    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
    $aplicador->fijar($cuenta, RubroDeDescuento::Servicios, Decimal::de('0.10'), null, 'paciente frecuente del hospital', quienDaElDescuento());
    $aplicador->quitar($cuenta, RubroDeDescuento::Servicios);

    expect(lasRebajasEnServicios($cuenta)[$laboratorio->id])->toBe('0.00')
        ->and($cuenta->refresh()->descuento_servicios)->toBeNull()
        ->and($cuenta->motivo_descuento_servicios)->toBeNull()
        ->and($cuenta->total)->toBe('400.00');
});

/*
|--------------------------------------------------------------------------
| En lempiras
|--------------------------------------------------------------------------
*/

it('en lempiras se reparte en proporcion y lo que un renglon no aguanta pasa a los otros', function (): void {
    $cuenta = unJovenDeContado();
    $alimentacion = unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000');
    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '200.0000');

    cargarElServicio($cuenta, $alimentacion);
    cargarElServicio($cuenta, $laboratorio);

    $topadas = app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        null,
        Decimal::de('105'),
        'paciente frecuente del hospital',
        quienDaElDescuento(),
    );

    $rebajas = lasRebajasEnServicios($cuenta);

    expect($rebajas[$alimentacion->id])->toBe('60.00')
        ->and($rebajas[$laboratorio->id])->toBe('45.00')
        ->and($topadas)->toBe([$alimentacion->nombre])
        ->and($cuenta->refresh()->total)->toBe('395.00');
})->note('En proporción le tocaban 63 a la alimentación, que aguanta 60 (20 % de 300). Los 3 que sobran van al laboratorio: 42 + 3 = 45, que aguanta 50.');

it('el centavo que no se reparte parejo va al renglon mas grande', function (): void {
    $cuenta = unJovenDeContado();
    $grande = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '101.0000');
    $chico1 = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '100.0000');
    $chico2 = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '100.0000');

    cargarElServicio($cuenta, $grande);
    cargarElServicio($cuenta, $chico1);
    cargarElServicio($cuenta, $chico2);

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar($cuenta, RubroDeDescuento::Servicios, null, Decimal::de('30'), 'paciente frecuente del hospital', quienDaElDescuento());

    $rebajas = lasRebajasEnServicios($cuenta);

    expect($rebajas[$grande->id])->toBe('10.08')
        ->and($rebajas[$chico1->id])->toBe('9.96')
        ->and($rebajas[$chico2->id])->toBe('9.96')
        ->and($cuenta->refresh()->total_descuento)->toBe('30.00');
})->note('3000 centavos entre 101/100/100: 1006 + 996 + 996 = 2998, y los 2 que sobran van al de 101. La suma da los L 30.00 al centavo.');

it('si en lempiras no cabe, no se aplica y dice hasta cuanto se puede', function (): void {
    $cuenta = unJovenDeContado();
    cargarElServicio($cuenta, unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000'));
    cargarElServicio($cuenta, unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '200.0000'));

    expect(fn () => app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        null,
        Decimal::de('120'),
        'paciente frecuente del hospital',
        quienDaElDescuento(),
    ))->toThrow(CuentaException::class, 'hasta L 110.00');

    expect($cuenta->refresh()->descuento_servicios_monto)->toBeNull();
});

it('en lempiras sin servicios cargados no hay entre que repartir', function (): void {
    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        unJovenDeContado(),
        RubroDeDescuento::Servicios,
        null,
        Decimal::de('50'),
        'paciente frecuente del hospital',
        quienDaElDescuento(),
    );
})->throws(CuentaException::class, 'no hay servicios');

it('en lempiras, si entra otro servicio, el monto se vuelve a repartir', function (): void {
    $cuenta = unJovenDeContado();
    $alimentacion = unServicioDeLaLey(CategoriaLegalDeDescuento::ServicioHospitalario, '300.0000');
    cargarElServicio($cuenta, $alimentacion);

    $aplicador = app(AplicadorDeDescuentoDeLaCuenta::class);
    $aplicador->fijar($cuenta, RubroDeDescuento::Servicios, null, Decimal::de('60'), 'paciente frecuente del hospital', quienDaElDescuento());

    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '300.0000');
    cargarElServicio($cuenta, $laboratorio);
    $aplicador->ponerAlDia($cuenta->refresh(), RubroDeDescuento::Servicios);

    $rebajas = lasRebajasEnServicios($cuenta);

    expect($rebajas[$alimentacion->id])->toBe('30.00')
        ->and($rebajas[$laboratorio->id])->toBe('30.00');
})->note('«L 60 en servicios» sigue siendo L 60 aunque entre otro servicio: no son L 60 más.');

/*
|--------------------------------------------------------------------------
| Lo que no toca
|--------------------------------------------------------------------------
*/

it('cada renglon cae en su rubro, y farmacia y el paquete en ninguno', function (TipoItem $tipo, bool $seAlmacena, ?RubroDeDescuento $rubro): void {
    $item = Item::factory()->make(['tipo' => $tipo, 'se_almacena' => $seAlmacena]);

    expect(AplicadorDeDescuentoDeLaCuenta::rubroDe($item))->toBe($rubro);
})->with([
    'un servicio'    => [TipoItem::Servicio, false, RubroDeDescuento::Servicios],
    'un estudio'     => [TipoItem::EstudioLaboratorio, false, RubroDeDescuento::Servicios],
    'un honorario'   => [TipoItem::Honorario, false, RubroDeDescuento::Honorarios],
    'lo de farmacia' => [TipoItem::Medicamento, true, null],
    'el paquete'     => [TipoItem::Paquete, false, null],
])->note('El honorario va aparte del servicio: rebajarlo es rebajarle al médico, y se decide con su propio motivo.');

/*
|--------------------------------------------------------------------------
| Honorarios (12-oct-2026)
|--------------------------------------------------------------------------
*/

/**
 * Un honorario con el precio del médico, que no está en el tarifario.
 */
function unHonorarioAcordado(Cuenta $cuenta, string $precio): Cargo
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Honorario,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ConsultaEspecializada,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a('500.0000')->create();

    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        precioAcordado: Monto::de($precio),
        referenciaAcordada: 'DR. PRUEBA · ESPECIALISTA',
    ))->firstOrFail();
}

it('el descuento en honorarios rebaja el honorario y no toca los servicios', function (): void {
    $cuenta = unJovenDeContado();
    $honorario = unHonorarioAcordado($cuenta, '600.00');
    $laboratorio = unServicioDeLaLey(CategoriaLegalDeDescuento::RadiologiaYLaboratorio, '400.0000');
    cargarElServicio($cuenta, $laboratorio);

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Honorarios,
        Decimal::de('0.10'),
        null,
        'el doctor acepto rebajar su honorario',
        quienDaElDescuento(),
    );

    $rebajas = lasRebajasEnServicios($cuenta);
    $vivo = $cuenta->cargos()
        ->where('estado', EstadoCargo::Pendiente->value)
        ->where('item_id', $honorario->item_id)
        ->firstOrFail();

    expect($rebajas[$honorario->item_id])->toBe('60.00')
        ->and($rebajas[$laboratorio->id])->toBe('0.00')
        ->and($vivo->precio_unitario)->toStartWith('600.00')
        ->and($cuenta->refresh()->motivo_descuento_honorarios)->toBe('EL DOCTOR ACEPTO REBAJAR SU HONORARIO')
        ->and($cuenta->descuento_servicios)->toBeNull();
})->note('El honorario vuelve con el precio del médico (L 600), no con el de lista del hospital (L 500): rehacer es rehacer igual.');

it('el descuento en servicios no toca los honorarios', function (): void {
    $cuenta = unJovenDeContado();
    $honorario = unHonorarioAcordado($cuenta, '600.00');

    app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Servicios,
        Decimal::de('0.10'),
        null,
        'paciente frecuente del hospital',
        quienDaElDescuento(),
    );

    expect(lasRebajasEnServicios($cuenta)[$honorario->item_id])->toBe('0.00');
});

it('el honorario se topa por su categoria, como cualquier renglon', function (): void {
    $cuenta = unJovenDeContado();
    $honorario = unHonorarioAcordado($cuenta, '600.00');

    $topadas = app(AplicadorDeDescuentoDeLaCuenta::class)->fijar(
        $cuenta,
        RubroDeDescuento::Honorarios,
        Decimal::de('0.40'),
        null,
        'el doctor acepto rebajar su honorario',
        quienDaElDescuento(),
    );

    expect(lasRebajasEnServicios($cuenta)[$honorario->item_id])->toBe('150.00')
        ->and($topadas)->toHaveCount(1);
})->note('Especialista: la tercera edad lleva 30 % de ley, así que a un joven el hospital le da hasta 25 %. 25 % de 600 = 150.');

/*
|--------------------------------------------------------------------------
| La base
|--------------------------------------------------------------------------
*/

it('la base no deja porcentaje y monto a la vez, ni un descuento sin motivo', function (string $caso): void {
    $cuenta = unJovenDeContado();

    $columnas = match ($caso) {
        'los dos a la vez' => [
            'descuento_servicios'        => '0.1000',
            'descuento_servicios_monto'  => '50.00',
            'motivo_descuento_servicios' => 'PACIENTE FRECUENTE',
            'descuento_servicios_en'     => now(),
        ],
        'sin motivo' => [
            'descuento_servicios_monto' => '50.00',
            'descuento_servicios_en'    => now(),
        ],
        default => [
            'descuento_servicios'        => '0.1000',
            'motivo_descuento_servicios' => 'OK',
            'descuento_servicios_en'     => now(),
        ],
    };

    DB::table('cuentas')->where('id', $cuenta->id)->update($columnas);
})->with([
    'los dos a la vez',
    'sin motivo',
    'motivo de una palabra',
])->throws(QueryException::class, 'cuentas_descuento_servicios_completo')
    ->note('Con DB::table y no con el modelo (§9.C4): el CHECK tiene que aguantar aunque la escritura no pase por el servicio.');
