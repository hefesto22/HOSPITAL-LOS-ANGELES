<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\CambiosDeFormasDeVenta;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\Monto;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\MargenObjetivo;
use App\Models\PrecioMinimo;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\CalculadoraDePreciosPorForma;
use App\Services\FijadorDeDescuentoLegal;
use App\Services\FijadorDePrecio;
use App\Services\FijadorDePreciosPorForma;
use App\Services\FormasDeCobro;
use App\Services\ReajustadorDePrecios;
use App\Services\RegistradorDeRecepcion;
use App\Services\ResumenDePrecios;
use App\Services\SincronizadorDeFormasDeVenta;
use Carbon\CarbonInterface;
use Database\Seeders\DescuentosLegalesSeeder;

/*
 * ─────────────────────────────────────────────────────────────────────
 * LOS PRECIOS DE FARMACIA SE AJUSTAN SOLOS (Mauricio, 13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Si cambio el blíster a 12 tabletas tiene que actualizar el precio para
 * no vender a pérdida, y eso automático. Lo mismo si el descuento cambia,
 * a más o a menos: si baja al 30, bajan los precios para seguir vendiendo
 * con ganancia. Y que quede en el historial que el descuento o la
 * cantidad cambió y por eso se modificó el precio.»
 *
 * GOLDEN (§9.H13), mínimo L 120 por la CAJA X 100, recargos 10 / 25 %:
 *
 *                       CAJA X 100   BLISTER X 10   TAB
 *   cuarta edad 40 %     L 200.00       L 22.00    L 2.50
 *   cuarta edad 45 %     L 219.00       L 24.00    L 2.75   (120 ÷ 0.55 = 218.18)
 *   cuarta edad 30 %     L 172.00       L 19.00    L 2.15   (120 ÷ 0.70 = 171.43)
 *
 *   BLISTER X 12 (40 %)   1.20 × 12 × 1.10 = 15.84 ÷ 0.60 = 26.40 → L 26.50
 *   CAJA X 120 (40 %)     1.20 × 120 = 144 ÷ 0.60          → L 240.00
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */
beforeEach(function (): void {
    (new DescuentosLegalesSeeder)->run();

    MargenObjetivo::factory()->para(TipoItem::Medicamento)->create([
        'recargo_envase' => '0.1000',
        'recargo_suelta' => '0.2500',
    ]);
});

function unidadParaElAjuste(string $codigo, bool $traeEnvases = false): Unidad
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

/**
 * @param numeric-string $contenido
 *
 * @return array{presentacion_id: int|null, unidad_id: int, unidades_por_presentacion: string, principal: bool}
 */
function formaParaElAjuste(string $envase, string $contenido, ?int $presentacion = null, bool $principal = false): array
{
    return [
        'presentacion_id'           => $presentacion,
        'unidad_id'                 => unidadParaElAjuste($envase, $envase === 'CAJA')->id,
        'unidades_por_presentacion' => $contenido,
        'principal'                 => $principal,
    ];
}

/**
 * Una tableta con CAJA X 100 —la principal— y BLISTER X 10, cargada
 * como se carga en la ficha.
 */
function tabletaParaElAjuste(): Item
{
    $item = Item::factory()->medicamento()->create([
        'regimen_isv'            => RegimenIsv::Exento,
        'unidad_dispensacion_id' => unidadParaElAjuste('TAB')->id,
    ]);

    app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, [
        formaParaElAjuste('CAJA', '100', principal: true),
        formaParaElAjuste('BLISTER', '10'),
    ]);

    return $item->refresh();
}

function envaseParaElAjuste(Item $item, string $envase): ItemPresentacion
{
    return ItemPresentacion::query()
        ->where('item_id', $item->id)
        ->where('unidad_id', unidadParaElAjuste($envase)->id)
        ->firstOrFail();
}

/**
 * «Cambiar el precio» como lo hace la ficha: el mínimo de la principal.
 *
 * @param numeric-string $minimo
 */
function fijarMinimoParaElAjuste(Item $item, string $minimo, ?Sede $sede, CarbonInterface $desde): void
{
    $precios = app(CalculadoraDePreciosPorForma::class)->desdeElMinimo(
        item: $item,
        presentaciones: ItemPresentacion::query()->with('unidad')->where('item_id', $item->id)->get(),
        minimoDeLaPrincipal: Decimal::de($minimo),
        fecha: $desde,
    );

    app(FijadorDePreciosPorForma::class)->fijar(
        item: $item,
        precios: $precios,
        sede: $sede,
        motivo: 'PRECIO DEL PROVEEDOR DE OCTUBRE',
        desde: $desde,
    );
}

/**
 * @param numeric-string $porcentaje fracción
 */
function cuartaEdadParaElAjuste(string $porcentaje, CarbonInterface $desde): void
{
    app(FijadorDeDescuentoLegal::class)->fijar(
        categoria: CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        rango: RangoEdad::Cuarta,
        porcentaje: Decimal::de($porcentaje),
        fundamento: 'PRUEBA: CAMBIA LA CUARTA EDAD',
        desde: $desde,
    );
}

/**
 * Lo que dice la ficha hoy: «CAJA X 100 TAB» => «200.00».
 *
 * @return array<string, string>
 */
function listasParaElAjuste(Item $item, ?Sede $sede): array
{
    $listas = [];

    foreach (app(ResumenDePrecios::class)->de($item, $sede, now())['formas'] as $forma) {
        $listas[$forma['rotulo']] = $forma['lista']->valor();
    }

    return $listas;
}

function entraBlisterParaElAjuste(ItemPresentacion $envase): void
{
    app(RegistradorDeRecepcion::class)->registrar(almacen: Almacen::factory()->create(), lineas: [
        new LineaRecibida(
            item: $envase->item,
            presentacion: $envase,
            cantidadPresentacion: Decimal::de('3'),
            unidadesPorPresentacion: Decimal::de($envase->unidades_por_presentacion),
            costoPorPresentacion: null,
            numeroLote: 'L-AJUSTE',
            vencimiento: now()->addYear(),
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| Cambia el descuento por edad
|--------------------------------------------------------------------------
*/

it('🔴 si la cuarta edad sube al 45 %, la caja sube sola a L 219 y la cuarta edad sigue pagando el mínimo', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));

    expect(listasParaElAjuste($item, $sede))->toBe([
        'CAJA X 100 TAB'   => '200.00',
        'BLISTER X 10 TAB' => '22.00',
        'TAB'              => '2.50',
    ]);

    cuartaEdadParaElAjuste('0.45', now());

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect(listasParaElAjuste($item, $sede))->toBe([
        'CAJA X 100 TAB'   => '219.00',
        'BLISTER X 10 TAB' => '24.00',
        'TAB'              => '2.75',
    ])
        ->and($resumen['formas'][0]['porEdad'][1]['paga']->valor())->toBe('120.45')
        ->and($resumen['debajoDelMinimo'])->toBeNull()
        ->and($resumen['porQue'])->toBe('AJUSTE AUTOMÁTICO: CAMBIÓ EL DESCUENTO POR EDAD (CUARTA EDAD 40 % → 45 %)')
        ->and(Tarifario::query()->where('item_id', $item->id)->whereDate('vigencia_hasta', now()->subDay()->toDateString())->count())->toBe(3)
        ->and(PrecioMinimo::query()->where('item_id', $item->id)->count())->toBe(1);
})->note('Con la lista de L 200 y el 45 %, la cuarta edad pagaba L 110 por una caja que no se puede vender a menos de L 120.');

it('🔴 si la cuarta edad baja al 30 %, la lista baja: se sigue ganando lo mismo con mejores precios', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    cuartaEdadParaElAjuste('0.30', now());

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect(listasParaElAjuste($item, $sede))->toBe([
        'CAJA X 100 TAB'   => '172.00',
        'BLISTER X 10 TAB' => '19.00',
        'TAB'              => '2.15',
    ])
        ->and($resumen['formas'][0]['porEdad'][1]['paga']->valor())->toBe('120.40')
        ->and($resumen['porQue'])->toBe('AJUSTE AUTOMÁTICO: CAMBIÓ EL DESCUENTO POR EDAD (CUARTA EDAD 40 % → 30 %)');
})->note('Con el 30 %, el divisor pasa de 0.60 a 0.70: la caja de L 200 bajaba a L 172 y la cuarta edad paga L 120.40, arriba del mínimo.');

it('ajustar otra vez lo que ya cuadra no escribe nada', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    cuartaEdadParaElAjuste('0.45', now());

    $filas = Tarifario::withTrashed()->where('item_id', $item->id)->count();

    expect(app(ReajustadorDePrecios::class)->alDescuentoPorEdad(now()))->toBe(0)
        ->and(Tarifario::withTrashed()->where('item_id', $item->id)->count())->toBe($filas);
})->note('El job puede correr dos veces —un reintento, el comando de la noche—: el historial no se llena de cambios que no cambian nada.');

it('un descuento con fecha de más adelante se aplica solo ese día, con el comando de la noche', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    cuartaEdadParaElAjuste('0.45', now()->addDays(5));

    expect(listasParaElAjuste($item, $sede)['CAJA X 100 TAB'])->toBe('200.00');

    $this->travelTo(now()->addDays(5)->setTime(0, 10));
    $this->artisan('precios:seguir-el-descuento')->assertSuccessful();

    expect(listasParaElAjuste($item, $sede)['CAJA X 100 TAB'])->toBe('219.00');

    $this->travelBack();
});

it('🔴 un precio cargado a mano después del mínimo no se pisa', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));

    app(FijadorDePrecio::class)->fijar(
        item: $item,
        convenio: null,
        sede: $sede,
        precio: Monto::de('3.00'),
        motivo: 'LA DIRECCIÓN LO PUSO A MANO',
        desde: now()->subDays(5),
    );

    cuartaEdadParaElAjuste('0.45', now());

    expect(listasParaElAjuste($item, $sede))->toBe([
        'CAJA X 100 TAB'   => '200.00',
        'BLISTER X 10 TAB' => '22.00',
        'TAB'              => '3.00',
    ]);
})->note('Si alguien decidió otra cosa después del mínimo, el sistema no le pasa por encima: la ficha lo avisa y se cambia a mano.');

/*
|--------------------------------------------------------------------------
| Cambia un envase
|--------------------------------------------------------------------------
*/

it('🔴 el blíster que pasa de 10 a 12 se reemplaza, y su precio sube solo a L 26.50', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();
    $caja = envaseParaElAjuste($item, 'CAJA');
    $blister = envaseParaElAjuste($item, 'BLISTER');

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    entraBlisterParaElAjuste($blister);

    $cambios = app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, [
        formaParaElAjuste('CAJA', '100', $caja->id, principal: true),
        formaParaElAjuste('BLISTER', '12', $blister->id),
    ]);

    app(ReajustadorDePrecios::class)->reajustar($item, now(), $cambios->porQue());

    $nuevo = envaseParaElAjuste($item, 'BLISTER');
    $lote = Lote::query()->where('item_id', $item->id)->sole();
    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect($cambios)->toBeInstanceOf(CambiosDeFormasDeVenta::class)
        ->and($cambios->cambios)->toBe(['BLISTER X 10 TAB PASÓ A BLISTER X 12 TAB'])
        ->and($cambios->avisos)->toHaveCount(1)
        ->and($blister->refresh()->trashed())->toBeTrue()
        ->and($nuevo->unidades_por_presentacion)->toBe('12.0000')
        ->and($lote->presentacion->unidades_por_presentacion)->toBe('10.0000')
        ->and(listasParaElAjuste($item, $sede))->toBe([
            'CAJA X 100 TAB'   => '200.00',
            'BLISTER X 12 TAB' => '26.50',
            'TAB'              => '2.50',
        ])
        ->and($resumen['porQue'])->toBe('AJUSTE AUTOMÁTICO: BLISTER X 10 TAB PASÓ A BLISTER X 12 TAB')
        ->and(Tarifario::query()->where('item_presentacion_id', $blister->id)->whereNull('vigencia_hasta')->count())->toBe(0);

    expect($nuevo->id)->not->toBe($blister->id);
})->note('🔴 §9.G11: el lote que entró en blísteres de 10 se sigue leyendo de 10. Lo que cambia es lo que se recibe y se vende desde hoy.');

it('🔴 la caja que pasa de 100 a 120 conserva el mínimo por tableta, y lo que quedó en cajas viejas sigue dando blísteres', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();
    $caja = envaseParaElAjuste($item, 'CAJA');
    $blister = envaseParaElAjuste($item, 'BLISTER');

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    entraBlisterParaElAjuste($caja);

    $cambios = app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, [
        formaParaElAjuste('CAJA', '120', $caja->id, principal: true),
        formaParaElAjuste('BLISTER', '10', $blister->id),
    ]);

    app(ReajustadorDePrecios::class)->reajustar($item, now(), $cambios->porQue());

    $presentaciones = ItemPresentacion::query()->with('unidad')->where('item_id', $item->id)->get();
    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect(listasParaElAjuste($item, $sede))->toBe([
        'CAJA X 120 TAB'   => '240.00',
        'BLISTER X 10 TAB' => '22.00',
        'TAB'              => '2.50',
    ])
        ->and($resumen['minimo']?->valor())->toBe('144.00')
        ->and(app(FormasDeCobro::class)->envasesQueLoTraen($blister->refresh(), $presentaciones))->toContain($caja->id);
})->note('Lo mínimo era L 1.20 por tableta: la caja de 120 no puede salir a L 120 de mínimo. Y las cajas de 100 del estante siguen trayendo blísteres de 10.');

it('mover la estrella al blíster recalcula con el mismo mínimo por tableta', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();
    $caja = envaseParaElAjuste($item, 'CAJA');
    $blister = envaseParaElAjuste($item, 'BLISTER');

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));

    $cambios = app(SincronizadorDeFormasDeVenta::class)->sincronizar($item, [
        formaParaElAjuste('CAJA', '100', $caja->id),
        formaParaElAjuste('BLISTER', '10', $blister->id, principal: true),
    ]);

    app(ReajustadorDePrecios::class)->reajustar($item, now(), $cambios->porQue());

    expect($cambios->cambios)->toBe(['LA PRINCIPAL PASÓ DE CAJA X 100 TAB A BLISTER X 10 TAB'])
        ->and(listasParaElAjuste($item, $sede))->toBe([
            'CAJA X 100 TAB'   => '200.00',
            'BLISTER X 10 TAB' => '20.00',
            'TAB'              => '2.50',
        ]);
})->note('El blíster deja de llevar recargo porque ahora es el envase de promoción: L 12 de mínimo, L 20 de lista.');

/*
|--------------------------------------------------------------------------
| El mínimo guardado
|--------------------------------------------------------------------------
*/

it('el mínimo se guarda por día: corregirlo el mismo día lo reemplaza, otro día cierra el anterior', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaElAjuste();

    fijarMinimoParaElAjuste($item, '120', $sede, now()->subDays(10));
    fijarMinimoParaElAjuste($item, '87', $sede, now());
    fijarMinimoParaElAjuste($item, '90', $sede, now());

    $vigente = PrecioMinimo::queRigePara($item, $sede->id, now());
    $anterior = PrecioMinimo::queRigePara($item, $sede->id, now()->subDays(3));

    expect($vigente?->minimo)->toBe('90.0000')
        ->and($vigente?->contenido)->toBe('100.0000')
        ->and($anterior?->minimo)->toBe('120.0000')
        ->and($anterior?->vigencia_hasta?->toDateString())->toBe(now()->subDay()->toDateString())
        ->and(PrecioMinimo::onlyTrashed()->where('item_id', $item->id)->count())->toBe(1);
});
