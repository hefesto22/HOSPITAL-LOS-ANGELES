<?php

declare(strict_types=1);

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\AjusteException;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\Conteo;
use App\Models\User;
use App\Services\AbridorDeConteo;
use App\Support\AlmacenesDelUsuario;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * LA PRUEBA CON UN ROL RESTRINGIDO QUE EXIGE LA DoD (§5), para conteos
 * y ajustes.
 *
 * ⚠️ Sin policy, Filament **no deniega: deja pasar**. El camino está en
 * `vendor/filament/filament/src/helpers.php`: si no hay policy para el
 * modelo y el panel no está en modo estricto, la última línea es
 * `Response::allow()`. Por eso el primer test de este archivo es que las
 * policies existan.
 *
 * El segundo grupo prueba lo otro que no se ve: que bodega y farmacia se
 * quedan cada una en su estante. Eso NO lo hace la policy —sería una
 * consulta por fila— sino `AlmacenesDelUsuario`, aplicado en la consulta
 * del Resource y otra vez en el servicio (§9.L5).
 */
$permisosDeInventario = [
    'ViewAny:Conteo', 'View:Conteo', 'Create:Conteo', 'Update:Conteo', 'Delete:Conteo',
    'ViewAny:Ajuste', 'View:Ajuste', 'Create:Ajuste', 'Update:Ajuste', 'Delete:Ajuste',
    'ViewAny:Almacen', 'View:Almacen',
    'ViewAny:Item', 'View:Item',
    'ViewAny:Unidad', 'ViewAny:Persona',
    'ViewAny:MargenObjetivo',
];

beforeEach(function () use ($permisosDeInventario): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach ($permisosDeInventario as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function conElRolDeInventario(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/*
|--------------------------------------------------------------------------
| Que exista quien consulte los permisos
|--------------------------------------------------------------------------
*/

it('conteo y ajuste tienen su policy registrada', function (): void {
    expect(Gate::getPolicyFor(Conteo::class))->not->toBeNull(
        'Sin policy, Filament deja el conteo abierto a cualquier autenticado'
    )->and(Gate::getPolicyFor(Ajuste::class))->not->toBeNull(
        'Sin policy, Filament deja los ajustes abiertos a cualquier autenticado'
    );
})->note('Es el test que habría atrapado el agujero del bloque 3. Sin policy, Filament no deniega — permite.');

/*
|--------------------------------------------------------------------------
| Quién puede qué
|--------------------------------------------------------------------------
*/

it('bodega y farmacia cuentan y ajustan', function (): void {
    foreach (['bodega', 'farmacia'] as $rol) {
        $usuario = conElRolDeInventario($rol);

        expect($usuario->can('viewAny', Conteo::class))->toBeTrue("{$rol} debería ver conteos")
            ->and($usuario->can('create', Conteo::class))->toBeTrue("{$rol} debería abrir conteos")
            ->and($usuario->can('create', Ajuste::class))->toBeTrue("{$rol} debería registrar ajustes");
    }
})->note('Con una sola farmacia, si solo bodega pudiera ajustar, la ampolla que se rompe a las 2 am espera hasta que abra bodega — y el kardex miente mientras tanto.');

it('caja y laboratorio no tocan el inventario', function (): void {
    foreach (['caja', 'laboratorio'] as $rol) {
        $usuario = conElRolDeInventario($rol);

        expect($usuario->can('viewAny', Conteo::class))->toBeFalse("{$rol} no debería ver conteos")
            ->and($usuario->can('create', Ajuste::class))->toBeFalse("{$rol} no debería ajustar");
    }
});

it('auditoria lee conteos y ajustes pero no los escribe', function (): void {
    $auditoria = conElRolDeInventario('auditoria');

    expect($auditoria->can('viewAny', Conteo::class))->toBeTrue()
        ->and($auditoria->can('viewAny', Ajuste::class))->toBeTrue()
        ->and($auditoria->can('create', Conteo::class))->toBeFalse()
        ->and($auditoria->can('create', Ajuste::class))->toBeFalse();
})->note('Quien audita no puede ser parte de lo auditado.');

/*
|--------------------------------------------------------------------------
| Lo que NADIE puede, por más permiso que tenga
|--------------------------------------------------------------------------
*/

it('nadie edita un ajuste asentado, ni siquiera direccion', function (): void {
    $direccion = conElRolDeInventario('direccion');
    $ajuste = Ajuste::factory()->create();

    expect($direccion->can('update', $ajuste))->toBeFalse()
        ->and($direccion->can('delete', $ajuste))->toBeFalse()
        ->and($direccion->can('forceDelete', $ajuste))->toBeFalse();
})->note('Un trigger de PostgreSQL rechaza el UPDATE. Mostrar el botón sería prometer algo que la base va a rechazar.');

it('nadie borra un conteo: se anula', function (): void {
    $direccion = conElRolDeInventario('direccion');
    $conteo = Conteo::factory()->create();

    expect($direccion->can('delete', $conteo))->toBeFalse()
        ->and($direccion->can('forceDelete', $conteo))->toBeFalse();
});

it('un conteo cerrado ya no admite update aunque el permiso exista', function (): void {
    $direccion = conElRolDeInventario('direccion');

    $abierto = Conteo::factory()->create();
    $cerrado = Conteo::factory()->create([
        'estado'      => 'cerrado',
        'cerrado_en'  => now(),
        'cerrado_por' => User::factory()->create()->id,
    ]);

    expect($direccion->can('update', $abierto))->toBeTrue()
        ->and($direccion->can('update', $cerrado))->toBeFalse();
})->note('El botón no puede prometer lo que el trigger va a rechazar.');

/*
|--------------------------------------------------------------------------
| Cada quien en su estante
|--------------------------------------------------------------------------
*/

it('farmacia no puede abrir un conteo en la bodega central', function (): void {
    conElRolDeInventario('farmacia');

    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();

    app(AbridorDeConteo::class)->abrir($bodega, AlcanceDeConteo::Parcial);
})->throws(AjusteException::class, 'no es un almacén de tu área')
    ->note('Va en el servicio y no solo en la pantalla: un comando o un import llaman directo (§9.L5).');

it('bodega no puede abrir un conteo en la farmacia', function (): void {
    conElRolDeInventario('bodega');

    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    app(AbridorDeConteo::class)->abrir($farmacia, AlcanceDeConteo::Parcial);
})->throws(AjusteException::class, 'no es un almacén de tu área');

it('direccion no tiene restriccion de almacen: siempre hay quien cierre', function (): void {
    conElRolDeInventario('direccion');

    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    expect(AlmacenesDelUsuario::tiposPermitidos())->toBeNull()
        ->and(AlmacenesDelUsuario::puedeOperarEn($bodega))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($farmacia))->toBeTrue();
})->note('Si dirección también estuviera restringida, un conteo de bodega abierto por el único bodeguero no lo podría cerrar nadie — y el control de cuatro ojos se volvería un candado sin llave.');

it('bodega no ve por consulta los conteos de la farmacia', function (): void {
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    $deBodega = Conteo::factory()->enElAlmacen($bodega)->create();
    $deFarmacia = Conteo::factory()->enElAlmacen($farmacia)->create();

    conElRolDeInventario('bodega');

    $consulta = Conteo::query();
    AlmacenesDelUsuario::filtrar($consulta);

    $ids = $consulta->pluck('id')->all();

    expect($ids)->toContain($deBodega->id)
        ->and($ids)->not->toContain($deFarmacia->id);
})->note('El alcance se aplica en la consulta, no fila por fila: una subconsulta en vez de veinticinco, y de paso el registro no existe para quien intente abrirlo por URL.');
