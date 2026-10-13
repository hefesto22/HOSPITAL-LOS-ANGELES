<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Jobs\AjustarPreciosAlDescuentoPorEdad;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\MargenObjetivo;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\CalculadoraDePreciosPorForma;
use App\Services\FijadorDeDescuentoLegal;
use App\Services\FijadorDePrecio;
use App\Services\FijadorDePreciosPorForma;
use App\Services\ResumenDePrecios;
use Carbon\CarbonInterface;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * ─────────────────────────────────────────────────────────────────────
 * «PRECIOS» EN LA FICHA DEL PRODUCTO (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «¿cuál es el precio vigente? No se sabe cuáles son del
 * historial», «no se puede editar», y «si el descuento de la cuarta edad
 * sube del 40 al 45 %, que lo tome en cuenta».
 *
 * Lo que este archivo cuida:
 *
 *   · lo vigente es lo que el cargo cobraría hoy, una fila por forma, y
 *     lo demás va aparte con su razón (la fila de todas las sedes que
 *     sigue abierta pero acá le gana la de la sede);
 *   · cambiar el precio el mismo día corrige lo de hoy si nadie cobró;
 *   · si la ley sube y el ajuste automático todavía no corrió, la ficha
 *     avisa que la cuarta edad paga menos que el mínimo, y volver a fijar
 *     con el mismo mínimo lo arregla.
 *
 * GOLDEN (§9.H13), con 40 % de cuarta edad y recargos 10 / 25 %:
 *
 *   Mínimo L 120   CAJA L 200.00 · BLISTER L 22.00 · TAB L 2.50
 *   Mínimo L 87    CAJA L 145.00 · BLISTER L 16.00 · TAB L 1.85
 *   Con 45 %, la caja de L 200 deja a la cuarta edad en L 110.00, debajo
 *   del mínimo; volver a fijar L 120 da 120 ÷ 0.55 = 218.18 → L 219.00,
 *   y la cuarta edad paga L 120.45.
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

function unidadParaLaFicha(string $codigo, bool $traeEnvases = false): Unidad
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
 * Una tableta que se vende suelta, en BLISTER X 10 y en CAJA X 100 —la
 * principal—.
 */
function tabletaParaLaFicha(): Item
{
    $item = Item::factory()->medicamento()->create([
        'regimen_isv'            => RegimenIsv::Exento,
        'unidad_dispensacion_id' => unidadParaLaFicha('TAB')->id,
    ]);

    $caja = ItemPresentacion::factory()->conContenido('100', 'CAJA X 100')->create([
        'item_id'           => $item->id,
        'unidad_id'         => unidadParaLaFicha('CAJA', true)->id,
        'es_predeterminada' => true,
    ]);

    $blister = ItemPresentacion::factory()->conContenido('10', 'BLISTER X 10')->create([
        'item_id'   => $item->id,
        'unidad_id' => unidadParaLaFicha('BLISTER')->id,
    ]);

    /*
     * La primera presentación nace habitual sola (`ItemPresentacion::
     * booted()`): la estrella la pone la prueba, no el orden.
     */
    ItemPresentacion::query()->whereKey($blister->id)->update(['es_predeterminada' => false]);
    ItemPresentacion::query()->whereKey($caja->id)->update(['es_predeterminada' => true]);

    return $item->refresh();
}

/**
 * @param numeric-string $minimo
 */
function fijarLaFichaDesde(Item $item, string $minimo, ?Sede $sede, ?CarbonInterface $desde = null): void
{
    $desde ??= now();

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
        motivo: 'PRECIO DE PRUEBA DEL PRODUCTO',
        desde: $desde,
    );
}

/**
 * Lo que dice la ficha: «CAJA X 100» => «200.00».
 *
 * @return array<string, string>
 */
function listasDeLaFicha(Item $item, ?Sede $sede): array
{
    $listas = [];

    foreach (app(ResumenDePrecios::class)->de($item, $sede, now())['formas'] as $forma) {
        $listas[$forma['rotulo']] = $forma['lista']->valor();
    }

    return $listas;
}

it('🔴 lo vigente es lo que se cobra hoy: una fila por forma, de la caja a la suelta', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaLaFicha();

    /* El de arranque, para todas las sedes, de hace un mes. */
    app(FijadorDePrecio::class)->fijar(
        item: $item,
        convenio: null,
        sede: null,
        precio: Monto::de('3.00'),
        motivo: 'PRECIO DE ARRANQUE DEL SISTEMA',
        desde: now()->subDays(30),
    );

    fijarLaFichaDesde($item, '120', $sede);

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect(listasDeLaFicha($item, $sede))->toBe([
        'CAJA X 100'   => '200.00',
        'BLISTER X 10' => '22.00',
        'TAB'          => '2.50',
    ])
        ->and($resumen['otros'])->toHaveCount(1)
        ->and($resumen['otros'][0]['estado'])->toBe('tapado')
        ->and($resumen['otros'][0]['donde'])->toBe('Todas las sedes')
        ->and($resumen['otros'][0]['precios'][0]['monto']->valor())->toBe('3.00');
})->note('Antes la tabla decía «Vigente» en las dos tabletas, la de L 4.05 y la de L 3.00. Las dos lo eran; solo una se cobra.');

it('lo que paga cada edad sale con la ley de hoy, y el mínimo y los recargos se leen del motivo', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaLaFicha();

    fijarLaFichaDesde($item, '120', $sede);

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());
    $caja = $resumen['formas'][0];
    $origen = $resumen['origen'];

    expect($caja['rotulo'])->toBe('CAJA X 100')
        ->and($caja['esLaPrincipal'])->toBeTrue()
        ->and(array_map(fn (array $edad): string => $edad['paga']->valor(), $caja['porEdad']))->toBe(['150.00', '120.00'])
        ->and($resumen['porQue'])->toBe('PRECIO DE PRUEBA DEL PRODUCTO')
        ->and($resumen['debajoDelMinimo'])->toBeNull();

    assert($origen !== null);

    expect($origen['minimo']->redondeado(2))->toBe('120.00')
        ->and($origen['envase']->redondeado(2))->toBe('0.10')
        ->and($origen['suelta']->redondeado(2))->toBe('0.25');
});

it('🔴 cambiar el precio el mismo día corrige lo de hoy si nadie cobró con eso', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaLaFicha();

    fijarLaFichaDesde($item, '120', $sede);
    fijarLaFichaDesde($item, '87', $sede);

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());

    expect(listasDeLaFicha($item, $sede))->toBe([
        'CAJA X 100'   => '145.00',
        'BLISTER X 10' => '16.00',
        'TAB'          => '1.85',
    ])
        ->and(Tarifario::onlyTrashed()->where('item_id', $item->id)->count())->toBe(3)
        ->and($resumen['porQue'])->toStartWith('CORREGIDO EL MISMO DÍA.')
        ->and($resumen['otros'])->toBe([]);
})->note('Antes el segundo «Fijar» del día chocaba con el de la mañana: no había forma de corregir un error de tecleo.');

it('🔴 si el descuento de ley sube y el ajuste todavía no corrió, la ficha avisa que la cuarta edad paga menos que el mínimo', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaLaFicha();

    fijarLaFichaDesde($item, '120', $sede);

    /*
     * Sin cola: es el rato entre que se guarda el porcentaje y que corre
     * el ajuste (`AjustarPreciosAlDescuentoPorEdad`), o el día que la cola
     * está caída. La ficha tiene que decirlo igual.
     */
    Queue::fake();

    app(FijadorDeDescuentoLegal::class)->fijar(
        categoria: CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        rango: RangoEdad::Cuarta,
        porcentaje: Decimal::de('0.45'),
        fundamento: 'PRUEBA: LA LEY SUBE LA CUARTA EDAD AL 45 %',
        desde: now(),
    );

    $aviso = app(ResumenDePrecios::class)->de($item, $sede, now())['debajoDelMinimo'];

    assert($aviso !== null);

    expect($aviso['rotulo'])->toBe('CAJA X 100')
        ->and($aviso['paga']->valor())->toBe('110.00')
        ->and($aviso['minimo']->valor())->toBe('120.00');

    Queue::assertPushed(AjustarPreciosAlDescuentoPorEdad::class);
})->note('La lista de L 200 se calculó para el 40 %: con el 45 % la cuarta edad paga L 110, y el hospital pierde L 10 en cada caja. El ajuste lo arregla solo (`PreciosQueSeAjustanSolosTest`); esto es la red por si no corre.');

it('volver a fijar con el mismo mínimo después del cambio de ley sube la lista y quita el aviso', function (): void {
    $sede = Sede::factory()->create();
    $item = tabletaParaLaFicha();

    fijarLaFichaDesde($item, '120', $sede);

    Queue::fake();

    app(FijadorDeDescuentoLegal::class)->fijar(
        categoria: CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        rango: RangoEdad::Cuarta,
        porcentaje: Decimal::de('0.45'),
        fundamento: 'PRUEBA: LA LEY SUBE LA CUARTA EDAD AL 45 %',
        desde: now(),
    );

    fijarLaFichaDesde($item, '120', $sede);

    $resumen = app(ResumenDePrecios::class)->de($item, $sede, now());
    $caja = $resumen['formas'][0];

    expect($caja['lista']->valor())->toBe('219.00')
        ->and($caja['porEdad'][1]['paga']->valor())->toBe('120.45')
        ->and($resumen['debajoDelMinimo'])->toBeNull();
});
