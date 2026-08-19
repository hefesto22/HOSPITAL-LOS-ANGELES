<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\RecepcionException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Recepcion;
use App\Models\User;
use App\Services\CalculadoraDeCostoPromedio;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeMovimiento;
use App\Services\RegistradorDeRecepcion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function recibidor(): RegistradorDeRecepcion
{
    return app(RegistradorDeRecepcion::class);
}

function costos(): CalculadoraDeCostoPromedio
{
    return app(CalculadoraDeCostoPromedio::class);
}

function loQueHay(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/**
 * Una línea lista para recibir, con los números como los teclea bodega.
 *
 * @param numeric-string $cajas
 * @param numeric-string $porCaja
 * @param numeric-string $costoPorCaja
 */
function unaLineaDe(
    Item $item,
    string $cajas,
    string $porCaja,
    string $costoPorCaja,
    ?string $lote = 'AB-123',
    ?string $vence = '2027-06-30',
    ?ItemPresentacion $presentacion = null,
): LineaRecibida {
    return new LineaRecibida(
        item: $item,
        presentacion: $presentacion,
        cantidadPresentacion: Decimal::de($cajas),
        unidadesPorPresentacion: Decimal::de($porCaja),
        costoPorPresentacion: Decimal::de($costoPorCaja),
        numeroLote: $lote,
        vencimiento: $vence === null ? null : Carbon::parse($vence),
    );
}

/*
|--------------------------------------------------------------------------
| El caso del acetaminofén
|--------------------------------------------------------------------------
*/

it('recibe dos presentaciones del mismo producto y saca el ponderado', function (): void {
    $acetaminofen = Item::factory()->medicamento()->create(['nombre' => 'ACETAMINOFEN 500 MG']);
    $bodega = Almacen::factory()->create();

    recibidor()->registrar(
        almacen: $bodega,
        lineas: [
            unaLineaDe($acetaminofen, '100', '100', '1000', 'AC-100', '2027-06-30'),
            unaLineaDe($acetaminofen, '50', '50', '500', 'AC-050', '2027-08-31'),
        ],
        referencia: 'Factura 000-001-01-00000657',
    );

    expect(loQueHay()->totalEn($acetaminofen, $bodega)->redondeado(0))->toBe('12500')
        ->and(costos()->vigente($acetaminofen, $bodega)->redondeado(2))->toBe('10.00');
})->note('100 cajas de 100 a L 1.000 son 10.000 tabletas a L 10; 50 cajas de 50 a L 500 son 2.500 a L 10. El kardex recibe 12.500 tabletas y el ponderado queda en L 10,00 — el mismo número que sale en una servilleta.');

it('el ponderado se mueve poco cuando entra poco y caro', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '125', '100', '1000', 'L-1')]);
    recibidor()->registrar($bodega, [unaLineaDe($item, '20', '100', '1350', 'L-2')]);

    expect(costos()->vigente($item, $bodega)->redondeado(6))->toBe('10.482759');
})->note('Con el ÚLTIMO precio, esas 2.000 tabletas caras revaluarían las 12.500 viejas y el inventario pasaría a valer L 13,50 la unidad. De ahí sale un margen que nunca existió. El ponderado dice L 10,48.');

it('el costo promedio es por almacen y no se mezcla entre bodegas', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();
    $farmacia = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '10', '100', '1000', 'L-A')]);
    recibidor()->registrar($farmacia, [unaLineaDe($item, '10', '100', '2000', 'L-B')]);

    expect(costos()->vigente($item, $bodega)->redondeado(2))->toBe('10.00')
        ->and(costos()->vigente($item, $farmacia)->redondeado(2))->toBe('20.00');
})->note('Dos sedes que le compran al mismo proveedor a precios distintos no comparten costo. El de cada estante es el suyo.');

it('el costo guardado coincide con recalcularlo desde las recepciones', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '100', '100', '1000', 'L-1')]);
    recibidor()->registrar($bodega, [unaLineaDe($item, '30', '50', '900', 'L-2')]);
    recibidor()->registrar($bodega, [unaLineaDe($item, '7', '20', '260', 'L-3')]);

    expect(costos()->vigente($item, $bodega)->redondeado(4))
        ->toBe(costos()->recalcularDesdeLasRecepciones($item, $bodega)->redondeado(4));
})->note('La tabla de costos es un caché; las líneas de recepción son la verdad. Se comparan a CUATRO decimales y no a seis a propósito: el promedio móvil redondea en cada entrada y el recálculo desde cero no, así que a partir del sexto decimal pueden diferir en una unidad. Es deriva de redondeo, no descuadre — y por eso el costo se expone a dos.');

/*
|--------------------------------------------------------------------------
| Entra sin pasos intermedios
|--------------------------------------------------------------------------
*/

it('guardar la recepcion ya movio el kardex', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    $recepcion = recibidor()->registrar($bodega, [unaLineaDe($item, '10', '100', '1000')]);

    expect($recepcion->exists)->toBeTrue()
        ->and(MovimientoKardex::query()->count())->toBe(1)
        ->and(loQueHay()->totalEn($item, $bodega)->redondeado(0))->toBe('1000');
})->note('No hay borrador ni confirmación: quien recibe aprieta guardar una vez y el inventario ya está al día. Es lo que permite hacerlo desde el teléfono frente al camión.');

it('el movimiento del kardex guarda el costo y el promedio que quedo', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '100', '100', '1000')]);

    $movimiento = MovimientoKardex::query()->firstOrFail();

    expect($movimiento->costoUnitarioDecimal()?->redondeado(2))->toBe('10.00')
        ->and($movimiento->costoPromedioDespuesDecimal()?->redondeado(2))->toBe('10.00');
})->note('Es la contracara exacta de `saldo_despues`: la foto en cada línea es lo que permite contestar cuánto valía el inventario al 31 de diciembre sin depender del costo de hoy.');

it('si una linea falla no queda ni la recepcion ni ningun movimiento', function (): void {
    $bueno = Item::factory()->medicamento()->create();
    $conflictivo = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    /*
     * El lote ya está registrado venciendo en junio. La segunda línea va
     * a decir diciembre, y eso solo se descubre DENTRO de la transacción
     * —cuando el resolutor va a buscar el lote—, que es justo lo que este
     * test necesita: una falla a mitad de camino, con la primera línea ya
     * asentada.
     */
    Lote::factory()->delItem($conflictivo)->queVence('2027-06-30')->create(['numero' => 'CHOQUE']);

    try {
        recibidor()->registrar($bodega, [
            unaLineaDe($bueno, '10', '100', '1000', 'L-1'),
            unaLineaDe($conflictivo, '5', '100', '800', 'CHOQUE', '2027-12-31'),
        ]);
    } catch (RecepcionException) {
        // Es lo que se espera.
    }

    expect(Recepcion::query()->count())->toBe(0)
        ->and(MovimientoKardex::query()->count())->toBe(0)
        ->and(loQueHay()->totalEn($bueno, $bodega)->esCero())->toBeTrue()
        ->and(costos()->vigente($bueno, $bodega)->esCero())->toBeTrue();
})->note('La primera línea ya había movido kardex y costo cuando la segunda se cayó. Todo o nada: media recepción dejaría existencias que ningún documento explica, y ese descuadre solo aparece en el conteo físico meses después.');

/*
|--------------------------------------------------------------------------
| Lo que no deja hacer
|--------------------------------------------------------------------------
*/

it('una recepcion sin lineas no se registra', function (): void {
    recibidor()->registrar(Almacen::factory()->create(), []);
})->throws(RecepcionException::class);

it('un honorario no puede entrar a una bodega', function (): void {
    $honorario = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create();

    unaLineaDe($honorario, '1', '1', '100', null, null);
})->throws(RecepcionException::class)
    ->note('Un honorario se cobra pero no mueve kardex. Si de verdad llegó algo físico, el ítem está mal clasificado.');

it('un medicamento sin numero de lote no entra', function (): void {
    unaLineaDe(Item::factory()->medicamento()->create(), '10', '100', '1000', null, null);
})->throws(RecepcionException::class);

it('una fecha de vencimiento sin lote no sirve y se rechaza', function (): void {
    $insumo = Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::SinDescuentoLegal)
        ->create(['requiere_lote' => false]);

    unaLineaDe($insumo, '10', '1', '20', null, '2027-01-31');
})->throws(RecepcionException::class)
    ->note('La fecha sola no sirve: cuando haya que ir al estante a sacar lo que vence, no hay cómo saber cuál caja es.');

it('el contenido de la presentacion no puede ser cero', function (): void {
    unaLineaDe(Item::factory()->medicamento()->create(), '10', '0', '1000');
})->throws(RecepcionException::class)
    ->note('Con cero, la conversión a unidades daría cero y la división del costo reventaría.');

it('la presentacion de otro producto se rechaza', function (): void {
    $uno = Item::factory()->medicamento()->create();
    $otro = Item::factory()->medicamento()->create();

    $ajena = ItemPresentacion::factory()->for($otro)->create();

    unaLineaDe($uno, '10', '100', '1000', 'L-1', '2027-06-30', $ajena);
})->throws(RecepcionException::class);

it('el mismo lote con otro vencimiento se rechaza', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    Lote::factory()->delItem($item)->queVence('2027-06-30')->create(['numero' => 'AB-123']);

    recibidor()->registrar($bodega, [unaLineaDe($item, '10', '100', '1000', 'AB-123', '2027-12-31')]);
})->throws(RecepcionException::class)
    ->note('Un lote no puede vencer dos veces: o el número está mal tecleado o la caja es de otro lote. Con la fecha equivocada, FEFO despacha al revés y algo se vence en el estante.');

/*
|--------------------------------------------------------------------------
| Donaciones e insumos
|--------------------------------------------------------------------------
*/

it('una donacion entra con costo cero y no ensucia el promedio', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '100', '100', '1000', 'L-COMPRA')]);
    recibidor()->registrar(
        almacen: $bodega,
        lineas: [unaLineaDe($item, '100', '100', '0', 'L-DONADO')],
        referencia: 'Donación Cruz Roja',
    );

    expect(loQueHay()->totalEn($item, $bodega)->redondeado(0))->toBe('20000')
        ->and(costos()->vigente($item, $bodega)->redondeado(2))->toBe('5.00');
})->note('Diez mil tabletas a L 10 y diez mil a cero dan L 5,00. Es correcto y es la razón de costear: lo donado abarata el promedio, y el precio de venta debería reflejarlo.');

it('un insumo sin lote entra sin lote', function (): void {
    $gasas = Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::SinDescuentoLegal)
        ->create(['requiere_lote' => false]);

    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($gasas, '20', '50', '250', null, null)]);

    expect(loQueHay()->totalEn($gasas, $bodega)->redondeado(0))->toBe('1000')
        ->and(costos()->vigente($gasas, $bodega)->redondeado(2))->toBe('5.00')
        ->and(Lote::query()->count())->toBe(0);
})->note('Gasas y jeringas no llevan lote, y obligarlas a tenerlo produciría números inventados.');

/*
|--------------------------------------------------------------------------
| La revisión posterior
|--------------------------------------------------------------------------
*/

it('otra persona marca la recepcion como revisada', function (): void {
    /** @var User $bodeguero */
    $bodeguero = User::factory()->create();

    /** @var User $jefe */
    $jefe = User::factory()->create();

    $this->actingAs($bodeguero);
    $recepcion = recibidor()->registrar(
        Almacen::factory()->create(),
        [unaLineaDe(Item::factory()->medicamento()->create(), '10', '100', '1000')],
    );

    expect($recepcion->estaRevisada())->toBeFalse();

    $this->actingAs($jefe);
    $revisada = recibidor()->marcarRevisada($recepcion->refresh());

    expect($revisada->estaRevisada())->toBeTrue()
        ->and($revisada->revisada_por)->toBe($jefe->id);
})->note('La revisión no frena nada: la mercadería ya entró. Lo que hace es sacarla del reporte de pendientes, y por eso tiene que firmarla otra persona.');

it('no se revisa uno mismo', function (): void {
    /** @var User $bodeguero */
    $bodeguero = User::factory()->create();

    $this->actingAs($bodeguero);

    $recepcion = recibidor()->registrar(
        Almacen::factory()->create(),
        [unaLineaDe(Item::factory()->medicamento()->create(), '10', '100', '1000')],
    );

    recibidor()->marcarRevisada($recepcion->refresh());
})->throws(RecepcionException::class)
    ->note('Firmarse el propio trabajo dejaría al reporte de pendientes sin significar nada.');

it('la base rechaza los cuatro ojos aunque se esquive el servicio', function (): void {
    /** @var User $unico */
    $unico = User::factory()->create();

    $this->actingAs($unico);

    $recepcion = recibidor()->registrar(
        Almacen::factory()->create(),
        [unaLineaDe(Item::factory()->medicamento()->create(), '10', '100', '1000')],
    );

    DB::table('recepciones')->where('id', $recepcion->id)->update([
        'revisada_en'  => now(),
        'revisada_por' => $unico->id,
    ]);
})->throws(QueryException::class)
    ->note('Un control que vive solo en el servicio lo saltea cualquier comando o consulta cruda. Este vale desde tinker.');

it('el reporte de sin revisar encuentra las que faltan', function (): void {
    $bodega = Almacen::factory()->create();

    /** @var User $bodeguero */
    $bodeguero = User::factory()->create();

    /** @var User $jefe */
    $jefe = User::factory()->create();

    $this->actingAs($bodeguero);

    $primera = recibidor()->registrar($bodega, [unaLineaDe(Item::factory()->medicamento()->create(), '1', '100', '100', 'L-1')]);
    recibidor()->registrar($bodega, [unaLineaDe(Item::factory()->medicamento()->create(), '1', '100', '100', 'L-2')]);

    $this->actingAs($jefe);
    recibidor()->marcarRevisada($primera->refresh());

    expect(Recepcion::query()->sinRevisar()->count())->toBe(1);
})->note('Es la pregunta que sostiene el control ahora que la entrada es directa: ¿cuáles no miró nadie todavía?');

/*
|--------------------------------------------------------------------------
| Convivencia con las salidas
|--------------------------------------------------------------------------
*/

it('despachar no cambia el costo promedio', function (): void {
    $item = Item::factory()->medicamento()->create();
    $bodega = Almacen::factory()->create();

    recibidor()->registrar($bodega, [unaLineaDe($item, '100', '100', '1000', 'AB-9')]);

    $lote = Lote::query()->where('item_id', $item->id)->firstOrFail();

    app(RegistradorDeMovimiento::class)->registrar(
        $item,
        $lote,
        $bodega,
        TipoMovimiento::SalidaPorDispensacion,
        Decimal::de('3000')
    );

    expect(loQueHay()->totalEn($item, $bodega)->redondeado(0))->toBe('7000')
        ->and(costos()->vigente($item, $bodega)->redondeado(2))->toBe('10.00');
})->note('El promedio ponderado solo se mueve cuando ENTRA algo. Una salida baja la cantidad y deja el costo unitario donde estaba — si no, cada dispensación revaluaría el estante.');
