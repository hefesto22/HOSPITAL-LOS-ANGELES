<?php

declare(strict_types=1);

use App\Filament\Pages\BasesDePrecios;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\Cargos\CargoResource;
use App\Filament\Resources\CategoriasItem\CategoriaItemResource;
use App\Filament\Resources\Convenios\ConvenioResource;
use App\Filament\Resources\Cuentas\CuentaResource;
use App\Filament\Resources\Encuentros\EncuentroResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Filament\Resources\PrincipiosActivos\PrincipioActivoResource;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\RangosCai\RangoCaiResource;
use App\Filament\Resources\Unidades\UnidadResource;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * LO QUE CADA ROL VE EN EL MENÚ (Mauricio, 21-oct-2026).
 *
 * «Consulta externa ¿debería ver todo eso?». No: laboratorio y consulta
 * externa ven Cuentas abiertas, Pacientes y Cuentas; convenios, unidades
 * y la bitácora son de dirección y auditoría.
 *
 * Se prueba con `canAccess()` del Resource, que es lo que Filament
 * consulta para dibujar la opción del menú y para dejar entrar por URL.
 * Probar solo el permiso del rol no alcanzaba: la bitácora estaba bien en
 * la matriz y se veía igual, porque su policy no estaba conectada.
 */

/**
 * Los sujetos de cada Resource que toca este archivo, como los genera
 * Shield. `MatrizDePermisosSeeder` solo concede los que existen.
 */
const SUJETOS_DEL_MENU = [
    'Activity', 'Cargo', 'CategoriaItem', 'Convenio', 'Cuenta', 'Encuentro',
    'FusionDePersona', 'Item', 'Persona', 'Producto', 'RangoCai', 'Unidad',
];

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (SUJETOS_DEL_MENU as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function entraAlMenuComo(string $rol): User
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
| 🔴 Sin policy, Filament permite
|--------------------------------------------------------------------------
*/

it('🔴 cada Resource del panel tiene su policy', function (): void {
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! is_subclass_of($resource, Resource::class)) {
            continue;
        }

        $modelo = $resource::getModel();

        expect(Gate::getPolicyFor($modelo))->not->toBeNull(
            "{$resource} ({$modelo}) no tiene policy: Filament lo deja abierto a cualquiera con usuario"
        );
    }
})->note('La bitácora es `Spatie\Activitylog\Models\Activity` y Laravel buscaba su policy en el namespace de Spatie: nunca encontró `App\Policies\ActivityPolicy`, y «Registros de actividad» lo veía cualquiera. Se registra en `AppServiceProvider` con `Gate::policy()`.');

it('🔴 la bitacora usa la policy del hospital', function (): void {
    expect(Gate::getPolicyFor(Activity::class))->toBeInstanceOf(ActivityPolicy::class);
});

/*
|--------------------------------------------------------------------------
| Convenios, unidades y la bitácora
|--------------------------------------------------------------------------
*/

it('🔴 convenios, unidades y la bitacora: solo direccion y auditoria', function (): void {
    foreach (array_keys(RoleSeeder::ROLES) as $rol) {
        entraAlMenuComo($rol);

        $ve = in_array($rol, ['direccion', 'auditoria'], true);

        foreach ([ConvenioResource::class, UnidadResource::class, ActivityLogResource::class] as $resource) {
            expect($resource::canAccess())->toBe($ve, "{$rol} y {$resource}");
        }
    }
})->note('Mauricio: «seguros y convenios solo el administrador y super admin deberían verlo, unidades de medida igual, registro de actividad igual». Auditoría los lee porque revisarlos es su trabajo, y no puede cambiar ninguno.');

it('🔴 rangos de CAI: solo direccion y auditoria, y caja tampoco', function (): void {
    foreach (array_keys(RoleSeeder::ROLES) as $rol) {
        entraAlMenuComo($rol);

        expect(RangoCaiResource::canAccess())->toBe(in_array($rol, ['direccion', 'auditoria'], true), $rol);
    }

    entraAlMenuComo('auditoria');

    expect(RangoCaiResource::canCreate())->toBeFalse();
})->note('Mauricio, 22-oct-2026: «rangos de CAI solo debería poder verlo administrador y super admin». Caja lo veía para saber por qué no le dejaba facturar; eso ahora está en su escritorio («Facturas que quedan»). Auditoría lo lee, como los convenios: revisar con qué CAI se facturó es su trabajo.');

/*
|--------------------------------------------------------------------------
| Laboratorio y consulta externa
|--------------------------------------------------------------------------
*/

it('🔴 laboratorio y consulta externa ven cuentas abiertas, pacientes y cuentas', function (string $rol): void {
    entraAlMenuComo($rol);

    expect(CuentasAbiertas::canAccess())->toBeTrue()
        ->and(PacienteResource::canAccess())->toBeTrue()
        ->and(CuentaResource::canAccess())->toBeTrue();
})->with(['laboratorio', 'consulta_externa']);

it('🔴 laboratorio y consulta externa ya no ven el catalogo, los precios, los encuentros ni los cargos', function (string $rol): void {
    entraAlMenuComo($rol);

    foreach ([
        ItemResource::class, ProductoResource::class, PrincipioActivoResource::class,
        CategoriaItemResource::class, EncuentroResource::class, CargoResource::class,
    ] as $resource) {
        expect($resource::canAccess())->toBeFalse("{$rol} no debería ver {$resource}");
    }

    expect(BasesDePrecios::canAccess())->toBeFalse("{$rol} no debería ver las bases de precios");
})->with(['laboratorio', 'consulta_externa'])
    ->note('El examen o la consulta lo buscan desde la cuenta, que ya les muestra el precio; esa búsqueda no pasa por `ViewAny:Item`.');

it('🔴 sin esos listados siguen abriendo la cuenta y cargandole', function (string $rol): void {
    entraAlMenuComo($rol);

    $cuenta = Cuenta::factory()->create();

    expect(Gate::allows('create', Cuenta::class))->toBeTrue()
        ->and(Gate::allows('create', Encuentro::class))->toBeTrue()
        ->and(Gate::allows('create', Cargo::class))->toBeTrue()
        ->and(Gate::allows('cargar', $cuenta))->toBeTrue();
})->with(['laboratorio', 'consulta_externa'])
    ->note('Abrir la cuenta crea su encuentro y agregar es crear un cargo: por eso `Encuentro` y `Cargo` quedan en `Create`. Lo que se va es el listado.');

/*
|--------------------------------------------------------------------------
| Caja conserva lo que usa
|--------------------------------------------------------------------------
*/

it('caja sigue con productos, servicios, categorias, bases de precios, encuentros y cargos', function (): void {
    entraAlMenuComo('caja');

    foreach ([
        ProductoResource::class, ItemResource::class, CategoriaItemResource::class,
        EncuentroResource::class, CargoResource::class, PacienteResource::class, CuentaResource::class,
    ] as $resource) {
        expect($resource::canAccess())->toBeTrue("caja debería ver {$resource}");
    }

    expect(BasesDePrecios::canAccess())->toBeTrue();
})->note('Mauricio: «encuentros y cargos es entendible en cajeras». Y crea y edita productos y servicios desde el 13-oct-2026.');
