<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\PrecioDeForma;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\MargenObjetivo;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\CalculadoraDePreciosPorForma;
use App\Services\FijadorDeMargenObjetivo;
use App\Services\FijadorDePreciosPorForma;
use App\Services\RedondeoDePrecio;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * EL PRECIO SALE DEL MÍNIMO DEL ENVASE PRINCIPAL (Mauricio, 11-oct-2026).
 *
 * «La caja a 120 es lo mínimo que puede pagar la cuarta edad; menos de
 * ahí se considera pérdida.» Y la caja es el precio de promoción: el
 * blíster +10 % y la tableta suelta +25 % por unidad, configurables. La
 * lista para todos es mínimo ÷ (1 − 40 %), redondeada HACIA ARRIBA: a
 * L 0.05 debajo de L 10, a L 0.50 hasta L 100 y a L 1 de ahí para arriba.
 *
 * GOLDEN TESTS (§9.H13): los números están hechos a mano y no se tocan
 * sin rehacer la cuenta.
 *
 *   Caja L 120    CAJA L 200.00 · BLÍSTER L 22.00 · TABLETA L 2.50
 *   Caja L 87     CAJA L 145.00 · BLÍSTER L 16.00 · TABLETA L 1.85
 *
 * ⚠️ Los ayudantes tienen nombres propios: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan.
 */
beforeEach(function (): void {
    (new DescuentosLegalesSeeder)->run();

    MargenObjetivo::factory()->para(TipoItem::Medicamento)->create([
        'recargo_envase' => '0.1000',
        'recargo_suelta' => '0.2500',
    ]);
});

function envaseParaPrecio(string $codigo, bool $traeEnvases = false): Unidad
{
    $existente = Unidad::query()->where('codigo', $codigo)->first();

    return $existente instanceof Unidad
        ? $existente
        : Unidad::factory()->create([
            'codigo'           => $codigo,
            'nombre'           => $codigo,
            'contiene_envases' => $traeEnvases,
        ]);
}

function unaTabletaConPrecioPorForma(string $suelta = 'TAB'): Item
{
    return Item::factory()->medicamento()->create([
        'regimen_isv'            => RegimenIsv::Exento,
        'unidad_dispensacion_id' => envaseParaPrecio($suelta)->id,
    ]);
}

/**
 * @param numeric-string $contenido
 */
function envaseDelProducto(Item $item, string $envase, string $contenido, bool $principal = false): ItemPresentacion
{
    $presentacion = ItemPresentacion::factory()
        ->conContenido($contenido, $envase.' X '.ItemPresentacion::sinCerosDeMas($contenido))
        ->create([
            'item_id'           => $item->id,
            'unidad_id'         => envaseParaPrecio($envase, $envase === 'CAJA')->id,
            'es_predeterminada' => $principal,
        ]);

    /*
     * La primera presentación nace habitual sola (`ItemPresentacion::
     * booted()`). Acá la estrella la pone la prueba, no el orden.
     */
    if (! $principal) {
        ItemPresentacion::query()->whereKey($presentacion->id)->update(['es_predeterminada' => false]);
    }

    return $presentacion->refresh();
}

/**
 * @param numeric-string $minimo
 * @param numeric-string|null $recargoEnvase
 * @param numeric-string|null $recargoSuelta
 */
function preciosDesdeElMinimo(
    Item $item,
    string $minimo,
    ?string $recargoEnvase = null,
    ?string $recargoSuelta = null,
): PreciosPorForma {
    return app(CalculadoraDePreciosPorForma::class)->desdeElMinimo(
        item: $item,
        presentaciones: ItemPresentacion::query()->with('unidad')->where('item_id', $item->id)->get(),
        minimoDeLaPrincipal: Decimal::de($minimo),
        fecha: now(),
        recargoEnvase: $recargoEnvase === null ? null : Decimal::de($recargoEnvase),
        recargoSuelta: $recargoSuelta === null ? null : Decimal::de($recargoSuelta),
    );
}

function laFormaDe(PreciosPorForma $precios, string $rotulo): PrecioDeForma
{
    foreach ($precios->formas as $forma) {
        if ($forma->rotulo === $rotulo) {
            return $forma;
        }
    }

    throw new RuntimeException("No hay forma {$rotulo}");
}

function loQuePagaA(PrecioDeForma $forma, RangoEdad $rango): string
{
    foreach ($forma->porEdad as $porEdad) {
        if ($porEdad['rango'] === $rango) {
            return $porEdad['paga']->valor();
        }
    }

    throw new RuntimeException("No hay precio para {$rango->value}");
}

/**
 * Una caja de 100 tabletas con su blíster de 10, la caja principal.
 *
 * @return array{item: Item, caja: ItemPresentacion, blister: ItemPresentacion}
 */
function laCajaYElBlister(): array
{
    $item = unaTabletaConPrecioPorForma();
    $blister = envaseDelProducto($item, 'BLISTER', '10.0000');
    $caja = envaseDelProducto($item, 'CAJA', '100.0000', principal: true);

    return ['item' => $item, 'caja' => $caja, 'blister' => $blister];
}

function fijarDesdeElMinimo(Item $item, PreciosPorForma $precios): void
{
    app(FijadorDePreciosPorForma::class)->fijar(
        item: $item,
        precios: $precios,
        sede: null,
        motivo: 'Precio de la lista del hospital',
        desde: now(),
    );
}

/**
 * Lo que se le cobró a la cuenta por esa forma.
 *
 * @param numeric-string $cantidad en unidades del kardex
 * @param numeric-string|null $envases cuántos sin abrir, o nulo si es suelto
 */
function cobrarComo(
    Cuenta $cuenta,
    Item $item,
    Almacen $almacen,
    string $cantidad,
    ?ItemPresentacion $envase = null,
    ?string $envases = null,
): Cargo {
    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de($cantidad),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
        presentacion: $envase,
        envases: $envases === null ? null : Decimal::de($envases),
    ))->firstOrFail();
}

function entranCajas(Almacen $almacen, ItemPresentacion $caja, string $cuantas): void
{
    app(RegistradorDeRecepcion::class)->registrar(almacen: $almacen, lineas: [
        new LineaRecibida(
            item: $caja->item,
            presentacion: $caja,
            cantidadPresentacion: Decimal::de($cuantas),
            unidadesPorPresentacion: Decimal::de($caja->unidades_por_presentacion),
            costoPorPresentacion: Decimal::de('50'),
            numeroLote: 'L-PRECIO',
            vencimiento: now()->addYear(),
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| El redondeo
|--------------------------------------------------------------------------
*/

it('🔴 el redondeo de la lista siempre sube, por escalones', function (): void {
    $redondeo = app(RedondeoDePrecio::class);

    expect($redondeo->haciaArriba(Decimal::de('1.8125'))->redondeado(2))->toBe('1.85')
        ->and($redondeo->haciaArriba(Decimal::de('1.85'))->redondeado(2))->toBe('1.85')
        ->and($redondeo->haciaArriba(Decimal::de('9.97'))->redondeado(2))->toBe('10.00')
        ->and($redondeo->haciaArriba(Decimal::de('15.95'))->redondeado(2))->toBe('16.00')
        ->and($redondeo->haciaArriba(Decimal::de('99.80'))->redondeado(2))->toBe('100.00')
        ->and($redondeo->haciaArriba(Decimal::de('144.1667'))->redondeado(2))->toBe('145.00')
        ->and($redondeo->haciaArriba(Decimal::de('145'))->redondeado(2))->toBe('145.00');
})->note('Hacia arriba y nunca al más cercano: hacia abajo dejaría a la cuarta edad pagando menos que el mínimo, que es pérdida.');

/*
|--------------------------------------------------------------------------
| Los precios que salen del mínimo — GOLDEN
|--------------------------------------------------------------------------
*/

it('🔴 GOLDEN caja de L 120: caja L 200, blister L 22, tableta L 2.50', function (): void {
    ['item' => $item] = laCajaYElBlister();

    $precios = preciosDesdeElMinimo($item, '120');
    $caja = laFormaDe($precios, 'CAJA X 100');
    $blister = laFormaDe($precios, 'BLISTER X 10');
    $tableta = laFormaDe($precios, 'TAB');

    expect($caja->esLaPrincipal)->toBeTrue()
        ->and($caja->lista->valor())->toBe('200.00')
        ->and(loQuePagaA($caja, RangoEdad::Tercera))->toBe('150.00')
        ->and(loQuePagaA($caja, RangoEdad::Cuarta))->toBe('120.00')
        ->and($blister->minimo->valor())->toBe('13.20')
        ->and($blister->lista->valor())->toBe('22.00')
        ->and(loQuePagaA($blister, RangoEdad::Tercera))->toBe('16.50')
        ->and(loQuePagaA($blister, RangoEdad::Cuarta))->toBe('13.20')
        ->and($tableta->minimo->valor())->toBe('1.50')
        ->and($tableta->lista->valor())->toBe('2.50')
        ->and(loQuePagaA($tableta, RangoEdad::Tercera))->toBe('1.87')
        ->and(loQuePagaA($tableta, RangoEdad::Cuarta))->toBe('1.50');
})->note('La tercera edad paga L 1.87 y no 1.88 por tableta: el descuento de L 0.625 se redondea a 0.63 y se resta, igual que en la cuenta.');

it('🔴 GOLDEN caja de L 87: caja L 145, blister L 16, tableta L 1.85', function (): void {
    ['item' => $item] = laCajaYElBlister();

    $precios = preciosDesdeElMinimo($item, '87');
    $caja = laFormaDe($precios, 'CAJA X 100');
    $blister = laFormaDe($precios, 'BLISTER X 10');
    $tableta = laFormaDe($precios, 'TAB');

    expect($caja->lista->valor())->toBe('145.00')
        ->and(loQuePagaA($caja, RangoEdad::Cuarta))->toBe('87.00')
        ->and($blister->lista->valor())->toBe('16.00')
        ->and(loQuePagaA($blister, RangoEdad::Cuarta))->toBe('9.60')
        ->and($tableta->lista->valor())->toBe('1.85')
        ->and(loQuePagaA($tableta, RangoEdad::Tercera))->toBe('1.39')
        ->and(loQuePagaA($tableta, RangoEdad::Cuarta))->toBe('1.11');
})->note('Los mínimos son L 9.57 el blíster y L 1.0875 la tableta: con el redondeo hacia arriba la cuarta edad paga L 9.60 y L 1.11, nunca menos.');

it('🔴 ninguna forma deja a la cuarta edad debajo de su minimo', function (): void {
    ['item' => $item] = laCajaYElBlister();

    foreach (['120', '87', '33.33', '1', '999.99'] as $minimo) {
        foreach (preciosDesdeElMinimo($item, $minimo)->formas as $forma) {
            expect(Decimal::de(loQuePagaA($forma, RangoEdad::Cuarta))->menorQue($forma->minimo->valor()))
                ->toBeFalse("{$forma->rotulo} con mínimo {$minimo}");
        }
    }
});

it('los recargos se cambian para un producto', function (): void {
    ['item' => $item] = laCajaYElBlister();

    $precios = preciosDesdeElMinimo($item, '120', recargoEnvase: '0', recargoSuelta: '0.5');

    expect(laFormaDe($precios, 'BLISTER X 10')->lista->valor())->toBe('20.00')
        ->and(laFormaDe($precios, 'TAB')->lista->valor())->toBe('3.00');
})->note('Mauricio: «por defecto, pero que se pueda cambiar». Sin recargo el blíster es una décima parte de la caja; con 50 % la tableta pasa de L 2.00 a L 3.00.');

it('sin recargos escritos se usan los de la politica del tipo', function (): void {
    ['item' => $item] = laCajaYElBlister();

    MargenObjetivo::query()->delete();
    MargenObjetivo::factory()->para(TipoItem::Medicamento)->create([
        'recargo_envase' => '0.2000',
        'recargo_suelta' => '0.5000',
    ]);

    $precios = preciosDesdeElMinimo($item, '120');

    expect($precios->recargoEnvase->redondeado(2))->toBe('0.20')
        ->and(laFormaDe($precios, 'BLISTER X 10')->lista->valor())->toBe('24.00')
        ->and(laFormaDe($precios, 'TAB')->lista->valor())->toBe('3.00');
});

it('🔴 la suelta puede ser la principal: el suero que llega en caja', function (): void {
    $suero = unaTabletaConPrecioPorForma('BOLSA');
    envaseDelProducto($suero, 'CAJA', '12.0000');

    $precios = preciosDesdeElMinimo($suero, '30');

    expect(laFormaDe($precios, 'BOLSA')->esLaPrincipal)->toBeTrue()
        ->and(laFormaDe($precios, 'BOLSA')->lista->valor())->toBe('50.00')
        ->and(laFormaDe($precios, 'CAJA X 12')->recargo->esCero())->toBeTrue()
        ->and(laFormaDe($precios, 'CAJA X 12')->lista->valor())->toBe('600.00');
})->note('La bolsa de L 30 mínimo es L 50 de lista. La caja de 12 no lleva recargo: es más grande que la principal, y no se cobra más por llevarse más.');

it('un minimo en cero no se calcula', function (): void {
    ['item' => $item] = laCajaYElBlister();

    expect(fn () => preciosDesdeElMinimo($item, '0'))
        ->toThrow(PrecioNoFijableException::class);
})->note('Un cero sería un producto regalado con cara de precio cargado.');

/*
|--------------------------------------------------------------------------
| Lo que queda en el tarifario
|--------------------------------------------------------------------------
*/

it('🔴 se guarda la suelta por unidad y cada envase entero y exacto', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = laCajaYElBlister();

    fijarDesdeElMinimo($item, preciosDesdeElMinimo($item, '120'));

    $filas = Tarifario::query()->where('item_id', $item->id)->get();
    $suelta = $filas->firstWhere('item_presentacion_id', null);
    $deLaCaja = $filas->firstWhere('item_presentacion_id', $caja->id);
    $delBlister = $filas->firstWhere('item_presentacion_id', $blister->id);

    expect($filas)->toHaveCount(3)
        ->and($suelta?->precio)->toBe('2.5000')
        ->and($suelta?->precio_envase)->toBeNull()
        ->and($deLaCaja?->precio)->toBe('2.0000')
        ->and($deLaCaja?->precio_envase)->toBe('200.00')
        ->and($delBlister?->precio)->toBe('2.2000')
        ->and($delBlister?->precio_envase)->toBe('22.00')
        ->and($suelta?->motivo)->toContain('Mínimo L. 120.00 por CAJA X 100');
})->note('El motivo guarda de dónde salió cada número: dentro de dos años es lo único que explica un L 2.50.');

it('la fila de la suelta no puede tener precio de envase', function (): void {
    $item = unaTabletaConPrecioPorForma();

    expect(fn () => DB::table('tarifarios')->insert([
        'item_id'        => $item->id,
        'precio'         => '2.5000',
        'precio_envase'  => '250.00',
        'motivo'         => 'Fila imposible a propósito',
        'elegible'       => true,
        'vigencia_desde' => now()->toDateString(),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]))->toThrow(QueryException::class, 'tarifarios_precio_envase_de_un_envase');
})->note('CHECK `tarifarios_precio_envase_de_un_envase`: en la fila de lo suelto no hay envase entero del que hablar.');

/*
|--------------------------------------------------------------------------
| Lo que se cobra — GOLDEN
|--------------------------------------------------------------------------
*/

it('🔴 GOLDEN se cobra la caja L 200, el blister L 22 y la tableta L 2.50', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = laCajaYElBlister();
    $almacen = Almacen::factory()->create();
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());

    fijarDesdeElMinimo($item, preciosDesdeElMinimo($item, '120'));
    entranCajas($almacen, $caja, '3');

    $laCaja = cobrarComo($cuenta, $item, $almacen, '100', $caja, '1');
    $elBlister = cobrarComo($cuenta, $item, $almacen, '10', $blister, '1');
    $laTableta = cobrarComo($cuenta, $item, $almacen, '1');

    expect($laCaja->bruto)->toBe('200.00')
        ->and($elBlister->bruto)->toBe('22.00')
        ->and($laTableta->bruto)->toBe('2.50');
})->note('🔴 La tableta suelta sale de un lote de cajas y se cobra al precio de la suelta: el precio es de la forma en que se vende, no del lote del que sale.');

it('🔴 GOLDEN la cuarta edad paga la caja al minimo', function (): void {
    ['item' => $item, 'caja' => $caja] = laCajaYElBlister();
    $almacen = Almacen::factory()->create();
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create(), edad: 85);

    fijarDesdeElMinimo($item, preciosDesdeElMinimo($item, '120'));
    entranCajas($almacen, $caja, '1');

    $cargo = cobrarComo($cuenta, $item, $almacen, '100', $caja, '1');

    expect($cargo->bruto)->toBe('200.00')
        ->and($cargo->total)->toBe('120.00');
})->note('Lista L 200 menos el 40 % de ley: L 120, exactamente el mínimo que Mauricio escribió.');

it('🔴 la caja sin abrir se cobra exacta aunque el unitario no cierre', function (): void {
    $item = unaTabletaConPrecioPorForma();
    $caja = envaseDelProducto($item, 'CAJA', '300.0000', principal: true);
    $almacen = Almacen::factory()->create();
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());

    $precios = preciosDesdeElMinimo($item, '139.80');
    fijarDesdeElMinimo($item, $precios);
    entranCajas($almacen, $caja, '1');

    $cargo = cobrarComo($cuenta, $item, $almacen, '300', $caja, '1');

    expect(laFormaDe($precios, 'CAJA X 300')->lista->valor())->toBe('233.00')
        ->and($cargo->bruto)->toBe('233.00');
})->note('L 233 entre 300 es 0.77666…; guardado con cuatro decimales y multiplicado de vuelta daría L 233.01, un centavo que nadie sabría explicar en la factura.');

it('🔴 el descuento del hospital rehace cada linea en su forma y su lote', function (): void {
    ['item' => $item, 'caja' => $caja, 'blister' => $blister] = laCajaYElBlister();
    $almacen = Almacen::factory()->create();
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());

    fijarDesdeElMinimo($item, preciosDesdeElMinimo($item, '120'));
    entranCajas($almacen, $caja, '2');

    $antes = [
        cobrarComo($cuenta, $item, $almacen, '100', $caja, '1'),
        cobrarComo($cuenta, $item, $almacen, '10', $blister, '1'),
        cobrarComo($cuenta, $item, $almacen, '1'),
    ];

    $cuenta->forceFill([
        'descuento_hospital'        => '0.1000',
        'motivo_descuento_hospital' => 'Cliente de la casa, lo autoriza la administración',
        'descuento_hospital_en'     => now(),
    ])->save();

    (new ReflectionMethod(CuentasAbiertas::class, 'rehacerLasLineasConElDescuento'))
        ->invoke(app(CuentasAbiertas::class), $cuenta->refresh());

    $despues = Cargo::query()
        ->where('cuenta_id', $cuenta->id)
        ->where('estado', EstadoCargo::Pendiente->value)
        ->orderBy('id')
        ->get();

    expect($despues)->toHaveCount(3)
        ->and($despues->pluck('bruto')->all())->toBe(['200.00', '22.00', '2.50'])
        ->and($despues->pluck('descuento_comercial')->all())->toBe(['20.00', '2.20', '0.25'])
        ->and($despues->pluck('item_presentacion_id')->all())->toBe(array_map(
            static fn (Cargo $cargo): ?int => $cargo->item_presentacion_id,
            $antes,
        ))
        ->and($despues->pluck('cantidad_presentacion')->all())->toBe(['1.0000', '1.0000', null])
        ->and($despues->pluck('lote_id')->all())->toBe(array_map(
            static fn (Cargo $cargo): ?int => $cargo->lote_id,
            $antes,
        ));
})->note('Se vio en pantalla el 11-oct-2026: al poner el 10 % el blíster de L 38.50 volvió como diez tabletas de L 4.05. La rebaja es lo único que el descuento tiene permiso de cambiar.');

/*
|--------------------------------------------------------------------------
| La política del tipo
|--------------------------------------------------------------------------
*/

it('cambiar el margen conserva los recargos que ya tenia el tipo', function (): void {
    MargenObjetivo::query()->delete();
    $fijador = app(FijadorDeMargenObjetivo::class);

    $fijador->fijar(
        tipo: TipoItem::Medicamento,
        fraccion: Decimal::de('1.2'),
        motivo: 'Margen del hospital con recargos propios',
        desde: now()->subDays(10),
        recargoEnvase: Decimal::de('0.15'),
        recargoSuelta: Decimal::de('0.30'),
    );

    $nuevo = $fijador->fijar(
        tipo: TipoItem::Medicamento,
        fraccion: Decimal::de('1.3'),
        motivo: 'Sube el margen y nada más',
        desde: now(),
    );

    expect($nuevo->recargo_envase)->toBe('0.1500')
        ->and($nuevo->recargo_suelta)->toBe('0.3000');
})->note('Cambiar el margen no puede borrar en silencio lo que se decidió del blíster.');
