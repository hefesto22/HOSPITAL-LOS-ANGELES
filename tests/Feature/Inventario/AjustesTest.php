<?php

declare(strict_types=1);

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoDeAjuste;
use App\Domain\Exceptions\AjusteException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaAjustada;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Models\User;
use App\Services\CalculadoraDeCostoPromedio;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeAjuste;
use App\Services\RegistradorDeRecepcion;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ayudantes — con nombres propios, que Pest carga todo en un solo proceso
|--------------------------------------------------------------------------
*/

function elAjustador(): RegistradorDeAjuste
{
    return app(RegistradorDeAjuste::class);
}

function costoParaAjuste(): CalculadoraDeCostoPromedio
{
    return app(CalculadoraDeCostoPromedio::class);
}

function existenciaDeAjuste(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/**
 * @param numeric-string $unidades
 * @param numeric-string $costoUnitario
 */
function entradaParaAjuste(
    Item $item,
    Almacen $almacen,
    string $unidades,
    string $costoUnitario,
    string $lote = 'AB-123',
): void {
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [
            new LineaRecibida(
                item: $item,
                presentacion: null,
                cantidadPresentacion: Decimal::de($unidades),
                unidadesPorPresentacion: Decimal::de('1'),
                costoPorPresentacion: Decimal::de($costoUnitario),
                numeroLote: $lote,
                vencimiento: now()->addYear(),
            ),
        ],
    );
}

/**
 * @param numeric-string $cantidad
 */
function unaMermaDe(Item $item, ?Lote $lote, string $cantidad, MotivoDeAjuste $motivo = MotivoDeAjuste::Rotura): LineaAjustada
{
    return new LineaAjustada(
        item: $item,
        lote: $lote,
        motivo: $motivo,
        cantidad: Decimal::de($cantidad),
        esEntrada: false,
        texto: 'Se cayó al piso',
    );
}

function medicamentoParaAjuste(): Item
{
    return Item::factory()->medicamento()->create(['requiere_lote' => true]);
}

/*
|--------------------------------------------------------------------------
| Lo esencial: la cantidad se mueve, el costo no
|--------------------------------------------------------------------------
*/

it('una merma resta existencia y NO mueve el costo promedio', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();

    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    expect($elCostoAntes = costoParaAjuste()->vigente($item, $bodega)->redondeado(4))->toBe('10.0000');

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    expect(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('95')
        ->and(costoParaAjuste()->vigente($item, $bodega)->redondeado(4))->toBe($elCostoAntes);
})->note('Un ajuste dice cuántos hay, no cuánto valen: nadie le pagó nada a nadie por las cinco que se rompieron.');

it('la cantidad base del costo sigue a la existencia real', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();

    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '40')],
        motivo: 'Excursión térmica en el refrigerador de la noche',
    );

    $fila = DB::table('costos_promedio')
        ->where('item_id', $item->id)
        ->where('almacen_id', $bodega->id)
        ->first();

    expect($fila?->cantidad_base)->toBe('60.0000')
        ->and($fila?->costo_unitario)->toBe('10.000000');
})->note('Si `cantidad_base` no bajara, la próxima compra se ponderaría contra 100 unidades que ya no existen — y el promedio móvil dejaría de ser móvil.');

/*
|--------------------------------------------------------------------------
| 🔴 §9.F11 — los controlados no se ajustan
|--------------------------------------------------------------------------
*/

it('no se puede ajustar directamente la existencia de un controlado', function (): void {
    actingAsAdmin();

    $fentanilo = Item::factory()->medicamento()->controlado()->create(['requiere_lote' => true]);
    $lote = Lote::factory()->create(['item_id' => $fentanilo->id]);

    unaMermaDe($fentanilo, $lote, '2');
})->throws(AjusteException::class, 'medicamento controlado')
    ->note('Se para en el value object y no en la pantalla, para que valga igual si el ajuste viene de un import, de un comando o de una pantalla que todavía no existe.');

/*
|--------------------------------------------------------------------------
| El tope y quién autoriza
|--------------------------------------------------------------------------
*/

it('un ajuste que pasa el tope exige autorizacion de direccion', function (): void {
    actingAsAdmin();
    config()->set('sihla.inventario.tope_ajuste_sin_autorizacion', '1000.00');

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();

    entradaParaAjuste($item, $bodega, '100', '50');
    $lote = Lote::query()->firstOrFail();

    // 30 × L 50 = L 1.500, por encima del tope.
    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '30')],
        motivo: 'Se rompió la caja completa al descargar',
    );
})->throws(AjusteException::class, 'el tope sin autorización')
    ->note('En lempiras y no en unidades: 500 gasas y 2 ampollas de inmunoglobulina son el mismo número y no son el mismo hecho.');

it('con un autorizador de direccion, el mismo ajuste pasa', function (): void {
    test()->seed(RoleSeeder::class);
    actingAsAdmin();
    config()->set('sihla.inventario.tope_ajuste_sin_autorizacion', '1000.00');

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '50');
    $lote = Lote::query()->firstOrFail();

    /** @var User $jefa */
    $jefa = User::factory()->create(['is_active' => true]);
    $jefa->assignRole('direccion');

    $ajuste = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '30')],
        motivo: 'Se rompió la caja completa al descargar',
        autorizador: $jefa,
    );

    expect($ajuste->estaAutorizado())->toBeTrue()
        ->and($ajuste->autorizado_por)->toBe($jefa->id)
        ->and($ajuste->valor_absoluto)->toBe('1500.00')
        ->and(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('70');
});

it('nadie autoriza su propio ajuste', function (): void {
    test()->seed(RoleSeeder::class);

    /** @var User $yo */
    $yo = User::factory()->create(['is_active' => true]);
    $yo->assignRole('direccion');
    test()->actingAs($yo);

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '50');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '30')],
        motivo: 'Se rompió la caja completa al descargar',
        autorizador: $yo,
    );
})->throws(AjusteException::class, 'tu propio ajuste')
    ->note('Un tope que se levanta uno mismo no es un tope.');

it('el autorizador tiene que tener el rol, no solo existir', function (): void {
    test()->seed(RoleSeeder::class);
    actingAsAdmin();

    /** @var User $bodeguero */
    $bodeguero = User::factory()->create(['is_active' => true]);
    $bodeguero->assignRole('bodega');

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '50');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '30')],
        motivo: 'Se rompió la caja completa al descargar',
        autorizador: $bodeguero,
    );
})->throws(AjusteException::class, 'no tiene rol para autorizar');

/*
|--------------------------------------------------------------------------
| Motivos: la lista cerrada es el control
|--------------------------------------------------------------------------
*/

it('una rotura no puede sumar existencia', function (): void {
    actingAsAdmin();

    $item = medicamentoParaAjuste();
    $lote = Lote::factory()->create(['item_id' => $item->id]);

    new LineaAjustada(
        item: $item,
        lote: $lote,
        motivo: MotivoDeAjuste::Rotura,
        cantidad: Decimal::de('5'),
        esEntrada: true,
    );
})->throws(AjusteException::class, 'no puede sumar existencia')
    ->note('Dejar que cualquier motivo vaya en cualquier dirección es cómo un faltante se asienta como sobrante y desaparece del reporte.');

it('un error de registro si va en las dos direcciones', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Correccion,
        lineas: [
            new LineaAjustada(
                item: $item,
                lote: $lote,
                motivo: MotivoDeAjuste::ErrorDeRegistro,
                cantidad: Decimal::de('20'),
                esEntrada: true,
                texto: 'Se recibieron 120 y se cargaron 100',
            ),
        ],
        motivo: 'La recepción del lunes se digitó con 20 de menos',
    );

    expect(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('120');
});

it('el motivo tiene que corresponder al tipo del documento', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Vencimiento,
        lineas: [unaMermaDe($item, $lote, '5', MotivoDeAjuste::Rotura)],
        motivo: 'Se dio de baja el lote que venció el mes pasado',
    );
})->throws(AjusteException::class, 'no corresponde a un ajuste de tipo');

it('una diferencia de conteo no se puede escribir a mano', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::DiferenciaDeConteo,
        lineas: [
            new LineaAjustada(
                item: $item,
                lote: $lote,
                motivo: MotivoDeAjuste::FaltanteDeConteo,
                cantidad: Decimal::de('40'),
                esEntrada: false,
            ),
        ],
        motivo: 'Me faltan cuarenta ampollas',
    );
})->throws(AjusteException::class, 'no se crea a mano')
    ->note('Sería poder declarar un faltante sin haber contado nada.');

it('el motivo del documento exige diez caracteres', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '1')],
        motivo: 'se rompió',
    );
})->throws(AjusteException::class, 'al menos diez caracteres');

/*
|--------------------------------------------------------------------------
| Append-only
|--------------------------------------------------------------------------
*/

it('un ajuste asentado no se puede editar ni con SQL crudo', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    $ajuste = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    DB::table('ajustes')->where('id', $ajuste->id)->update(['motivo' => 'ya no importa qué pasó']);
})->throws(QueryException::class)
    ->note('Un ajuste editable dejaría el documento diciendo «se rompieron 2» y el kardex diciendo −40, sin nada que delate cuál miente.');

it('tampoco se borra', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    $ajuste = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    DB::table('ajustes')->where('id', $ajuste->id)->delete();
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Trazabilidad y concurrencia
|--------------------------------------------------------------------------
*/

it('cada linea del ajuste apunta a su linea del kardex', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    $ajuste = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    $linea = $ajuste->lineas()->firstOrFail();

    expect($linea->movimiento_id)->not->toBeNull()
        ->and($linea->cantidad)->toBe('-5.0000')
        ->and($linea->costo_unitario)->toBe('10.000000')
        ->and($linea->valor)->toBe('50.00')
        ->and($linea->movimiento->motivo)->toContain('Rotura');
})->note('Cualquier número raro del kardex se puede seguir hasta acá, y desde acá hasta la persona.');

it('no se puede mermar mas de lo que hay', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '10', '10');
    $lote = Lote::query()->firstOrFail();

    try {
        elAjustador()->registrar(
            almacen: $bodega,
            tipo: TipoDeAjuste::Merma,
            lineas: [unaMermaDe($item, $lote, '50')],
            motivo: 'Se rompieron cincuenta que nunca existieron',
        );
    } catch (Throwable) {
        // El error es lo esperado; lo que importa es que no quedó rastro.
    }

    expect(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('10')
        ->and(Ajuste::query()->count())->toBe(0);
})->note('Todo o nada: si la salida no cabe, no queda ni el documento ni el movimiento.');

/*
|--------------------------------------------------------------------------
| Idempotencia — el cinturón contra el doble clic
|--------------------------------------------------------------------------
*/

it('el mismo formulario enviado dos veces asienta UN solo ajuste', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    $clave = '11111111-1111-4111-8111-111111111111';

    $primero = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Vencimiento,
        lineas: [unaMermaDe($item, $lote, '30', MotivoDeAjuste::Vencido)],
        motivo: 'El lote venció el mes pasado y se saca del estante',
        claveIdempotencia: $clave,
    );

    $segundo = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Vencimiento,
        lineas: [unaMermaDe($item, $lote, '30', MotivoDeAjuste::Vencido)],
        motivo: 'El lote venció el mes pasado y se saca del estante',
        claveIdempotencia: $clave,
    );

    expect($segundo->id)->toBe($primero->id)
        ->and(Ajuste::query()->count())->toBe(1)
        // Y lo que importa de verdad: se dio de baja UNA vez, no dos.
        ->and(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('70');
})->note('Dar de baja dos veces un lote vencido no se puede deshacer: todo esto es append-only, y «corregirlo» significa un tercer documento que compense.');

it('sin clave, dos ajustes iguales son dos ajustes distintos', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó otra bandeja, en el turno siguiente',
    );

    expect(Ajuste::query()->count())->toBe(2)
        ->and(existenciaDeAjuste()->totalEn($item, $bodega)->redondeado(0))->toBe('90');
})->note('La clave la pone la pantalla. Un comando o un import no la traen, y dos mermas parecidas el mismo día son un caso normal.');

it('guarda quien asento el ajuste', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaAjuste();
    entradaParaAjuste($item, $bodega, '100', '10');
    $lote = Lote::query()->firstOrFail();

    $ajuste = elAjustador()->registrar(
        almacen: $bodega,
        tipo: TipoDeAjuste::Merma,
        lineas: [unaMermaDe($item, $lote, '5')],
        motivo: 'Se cayó la bandeja al trasladar al quirófano',
    );

    expect($ajuste->created_by)->toBe($bodeguero->id);
})->note('`Ajuste` no usa HasAuditFields —no tiene updated_by ni deleted_by— así que `created_by` se pasa a mano, y sin estar en $fillable Eloquent lo descartaba EN SILENCIO.');
