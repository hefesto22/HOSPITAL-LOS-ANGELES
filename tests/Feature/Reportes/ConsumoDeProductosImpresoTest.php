<?php

declare(strict_types=1);

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\TipoAlmacen;
use App\Filament\Pages\ConsumoDeProductos;
use App\Models\AccesoExpediente;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El consumo de productos, en papel (11-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio, sobre «Consumo de productos»: «que se pueda imprimir».
|
| Los números del papel salen de `ConsumoDelPeriodo`, igual que la
| pantalla, y esos ya se prueban en `ConsumoDeProductosTest`. Acá se prueba
| que el papel tenga el mismo candado que la pantalla, que diga lo que se
| estaba mirando, que los pacientes salgan solo con «Con cada uso» y que
| cada impresión quede anotada.
|
| Los ayudantes de stock infinito (`unaCuentaDeStockInfinito()`, …) viven
| en `tests/Pest.php`. Los de acá tienen nombre propio (§9.M5).
*/

/*
 * Los permisos de cada Resource (`ViewAny:Almacen`, `ViewAny:Cargo`, …) los
 * crea Shield en el servidor, no un seeder: en la base de tests no existen,
 * y `MatrizDePermisosSeeder` solo concede los que existen. Sin crearlos
 * antes, ni dirección pasa el candado. Se crean TODOS los que la matriz
 * nombra, para que el candado se pruebe con la matriz de verdad.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (MatrizDePermisosSeeder::permisosDeclarados() as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Un usuario del rol parado en esa sede.
 *
 * ⚠️ SIEMPRE con sede: la bitácora de lectura la exige
 * (`accesos_expediente.sede_id` NOT NULL), y los cargos se filtran por la
 * sede de quien mira.
 */
function imprimeElConsumoComo(string $rol, ?int $sedeId = null): User
{
    $sedeId ??= Sede::query()->orderBy('id')->value('id') ?? Sede::factory()->create()->id;

    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true, 'sede_id' => $sedeId]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

it('🔴 el papel tiene el mismo candado que la pantalla', function (): void {
    $dejan = [];
    $noDejan = [];

    foreach (array_keys(RoleSeeder::ROLES) as $rol) {
        imprimeElConsumoComo($rol);

        $puede = ConsumoDeProductos::canAccess();

        if ($puede) {
            $dejan[] = $rol;
        } else {
            $noDejan[] = $rol;
        }

        test()->get(route('reportes.consumo'))->assertStatus($puede ? 200 : 403);
        test()->get(route('reportes.consumo', ['detalle' => 1]))->assertStatus($puede ? 200 : 403);
    }

    expect($dejan)->not->toBe([])
        ->and($noDejan)->not->toBe([]);
})->note('«Con cada uso» lleva nombres de pacientes: una ruta de impresión sin candado es esa lista servida por URL.');

it('sin sesión, el papel no se abre ni queda anotado', function (): void {
    expect(test()->get(route('reportes.consumo'))->isOk())->toBeFalse()
        ->and(AccesoExpediente::query()->count())->toBe(0);
});

it('el papel lleva lo que se usó, y los pacientes solo con «Con cada uso»', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create(['sede_id' => $cuenta->sede_id]);

    $cargo = cargarConStockInfinito($cuenta, $item, '3', $farmacia)->firstOrFail();
    cargarConStockInfinito($cuenta, $item, '2', $farmacia);

    $paciente = $cargo->encuentro->persona->nombreCompleto();

    imprimeElConsumoComo('direccion', $cuenta->sede_id);

    test()->get(route('reportes.consumo'))
        ->assertOk()
        ->assertSee('Consumo de productos')
        ->assertSeeInOrder(['Usos', '2', 'Productos', '1', 'Lo que se usó', $item->nombre, $farmacia->nombre.' 5'])
        ->assertSee('Stock infinito')
        ->assertDontSee('Cada uso, uno por uno')
        ->assertDontSee($paciente);

    test()->get(route('reportes.consumo', ['detalle' => 1]))
        ->assertOk()
        ->assertSee('Cada uso, uno por uno')
        ->assertSee($paciente)
        ->assertSee((string) $cuenta->numero);
});

it('🔴 elegir un estante deja en el papel solo lo que salió de ahí', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $deFarmacia = unMedicamentoDeStockInfinito();
    $deEmergencia = unMedicamentoDeStockInfinito();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create(['sede_id' => $cuenta->sede_id]);
    $emergencia = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create(['sede_id' => $cuenta->sede_id]);

    cargarConStockInfinito($cuenta, $deFarmacia, '3', $farmacia);
    cargarConStockInfinito($cuenta, $deEmergencia, '2', $emergencia);

    imprimeElConsumoComo('direccion', $cuenta->sede_id);

    test()->get(route('reportes.consumo', ['almacen' => $emergencia->id, 'detalle' => 1]))
        ->assertOk()
        ->assertSee('Consumo de productos · '.$emergencia->nombre)
        ->assertSee((string) $deEmergencia->codigo)
        ->assertDontSee((string) $deFarmacia->codigo);
});

it('🔴 cada impresión queda en la bitácora de lectura, con el período', function (): void {
    $usuario = imprimeElConsumoComo('direccion');

    test()->get(route('reportes.consumo'))->assertOk();
    test()->get(route('reportes.consumo', ['detalle' => 1]))->assertOk();

    $impresiones = AccesoExpediente::query()
        ->where('user_id', $usuario->id)
        ->where('accion', AccionDeLectura::Imprimir->value)
        ->where('recurso_tipo', Cargo::class)
        ->orderBy('id')
        ->get();

    expect($impresiones)->toHaveCount(2)
        ->and($impresiones[0]->motivo_texto)->toStartWith('CONSUMO DE PRODUCTOS · ')
        ->and($impresiones[0]->motivo_texto)->not->toContain('CON CADA USO')
        ->and($impresiones[1]->motivo_texto)->toEndWith('CON CADA USO');
})->note('Con cada uso, el papel lleva nombres de pacientes (§9.E8, §9.L12).');

it('un período de más de un año no se imprime', function (): void {
    imprimeElConsumoComo('direccion');

    test()->get(route('reportes.consumo', ['desde' => '2025-01-01', 'hasta' => '2026-06-30']))->assertStatus(422);
    test()->get(route('reportes.consumo', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']))->assertOk();
});

it('incrustado en el modal, el papel sale sin su barra de botones', function (): void {
    imprimeElConsumoComo('direccion');

    test()->get(route('reportes.consumo'))->assertOk()->assertSee('Volver a la pantalla');
    test()->get(route('reportes.consumo', ['incrustada' => 1]))->assertOk()->assertDontSee('Volver a la pantalla');
});

it('el enlace de la pantalla lleva lo que se está mirando, «Sin estante» incluido', function (): void {
    $cuenta = unaCuentaDeStockInfinito();
    $item = unMedicamentoDeStockInfinito();

    cargarConStockInfinito($cuenta, $item, '1');

    $hoy = now()->toDateString();

    $pantalla = app(ConsumoDeProductos::class);
    $pantalla->desde = $hoy;
    $pantalla->hasta = $hoy;
    $pantalla->almacen = ConsumoDeProductos::SIN_ALMACEN;
    $pantalla->tableFilters = ['origen' => ['value' => 'sin_inventario']];

    expect($pantalla->enlaceDelReporte(incrustada: true, conDetalle: true))
        ->toBe(route('reportes.consumo', [
            'desde'      => $hoy,
            'hasta'      => $hoy,
            'almacen'    => 0,
            'origen'     => 'sin_inventario',
            'detalle'    => 1,
            'incrustada' => 1,
        ]))
        ->and($pantalla->sePuedeImprimir())->toBeTrue();
})->note('El estante «Sin estante» es el 0: un `array_filter` sin función lo habría quitado del enlace.');
