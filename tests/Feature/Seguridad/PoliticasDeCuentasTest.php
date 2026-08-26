<?php

declare(strict_types=1);

use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * LA PRUEBA CON UN ROL RESTRINGIDO QUE EXIGE LA DoD (§5), para cuentas.
 *
 * ⚠️ Sin policy, Filament **no deniega: deja pasar**. Si no hay policy
 * para el modelo y el panel no está en modo estricto, la última línea de
 * `get_authorization_response()` es `Response::allow()`. Por eso el
 * primer test de este archivo es que las tres policies existan.
 *
 * El segundo grupo prueba la regla más cara de este módulo: que **nadie**
 * puede editar un cargo. Ni caja, ni admisión, ni dirección con su
 * comodín. Corregir es anular y volver a cargar.
 */
$permisosDeCuentas = [
    'ViewAny:Encuentro', 'View:Encuentro', 'Create:Encuentro', 'Update:Encuentro', 'Delete:Encuentro',
    'ViewAny:Cuenta', 'View:Cuenta', 'Create:Cuenta', 'Update:Cuenta', 'Delete:Cuenta',
    'ViewAny:Cargo', 'View:Cargo', 'Create:Cargo', 'Update:Cargo', 'Delete:Cargo',
    'ViewAny:Persona', 'View:Persona', 'ViewAny:Item', 'View:Item', 'ViewAny:Unidad',
    'ViewAny:Convenio', 'View:Convenio',
];

beforeEach(function () use ($permisosDeCuentas): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach ($permisosDeCuentas as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function conElRolDeAtencion(string $rol): User
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
| Las policies existen
|--------------------------------------------------------------------------
*/

it('los tres modelos tienen policy', function (): void {
    expect(Gate::getPolicyFor(Encuentro::class))->not->toBeNull()
        ->and(Gate::getPolicyFor(Cuenta::class))->not->toBeNull()
        ->and(Gate::getPolicyFor(Cargo::class))->not->toBeNull();
})->note('Sin policy, Filament PERMITE. No deniega: permite.');

/*
|--------------------------------------------------------------------------
| Quién puede qué
|--------------------------------------------------------------------------
*/

it('admision abre cuentas', function (): void {
    conElRolDeAtencion('admision');

    expect(Gate::allows('create', Cuenta::class))->toBeTrue()
        ->and(Gate::allows('create', Encuentro::class))->toBeTrue()
        ->and(Gate::allows('create', Cargo::class))->toBeTrue();
});

it('enfermeria puede cargarle cosas a la cuenta', function (): void {
    conElRolDeAtencion('enfermeria');

    expect(Gate::allows('create', Cargo::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Cuenta::class))->toBeTrue();
})->note('Si solo admisión pudiera, la enfermera de las 3 de la mañana usaría la clave de otro y la bitácora dejaría de servir para lo único que existe.');

it('el medico no ve la cuenta', function (): void {
    conElRolDeAtencion('medico');

    expect(Gate::allows('viewAny', Encuentro::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Cuenta::class))->toBeFalse()
        ->and(Gate::allows('viewAny', Cargo::class))->toBeFalse()
        ->and(Gate::allows('create', Cargo::class))->toBeFalse();
})->note('§1.4: el médico no ve costos, y la cuenta es precios en pantalla. El día que una aseguradora pregunte si se pidió un estudio por necesidad o por margen, la respuesta tiene que ser que el que lo pidió no podía ver el margen.');

it('bodega no ve ni cuentas ni pacientes internados', function (): void {
    conElRolDeAtencion('bodega');

    expect(Gate::allows('viewAny', Cuenta::class))->toBeFalse()
        ->and(Gate::allows('viewAny', Encuentro::class))->toBeFalse()
        ->and(Gate::allows('viewAny', Cargo::class))->toBeFalse();
})->note('Quien recibe una compra no tiene por qué saber quién está internado (§1.4).');

it('auditoria lee todo y no escribe nada', function (): void {
    conElRolDeAtencion('auditoria');

    $cuenta = Cuenta::factory()->create();
    $cargo = Cargo::factory()->enLaCuenta($cuenta)->create();

    expect(Gate::allows('viewAny', Cuenta::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Cargo::class))->toBeTrue()
        ->and(Gate::allows('create', Cargo::class))->toBeFalse()
        ->and(Gate::allows('update', $cargo))->toBeFalse()
        ->and(Gate::allows('create', Cuenta::class))->toBeFalse();
})->note('Quien audita no puede ser parte de lo auditado.');

it('caja liquida pero no toca el catalogo', function (): void {
    conElRolDeAtencion('caja');

    expect(Gate::allows('create', Cargo::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Cuenta::class))->toBeTrue()
        ->and(Gate::allows('create', Item::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 🔴 Lo que NADIE puede
|--------------------------------------------------------------------------
*/

it('nadie edita un cargo, ni siquiera direccion', function (): void {
    $cuenta = Cuenta::factory()->create();
    $cargo = Cargo::factory()->enLaCuenta($cuenta)->create();

    foreach (['direccion', 'admision', 'caja', 'enfermeria', 'auditoria', 'farmacia'] as $rol) {
        conElRolDeAtencion($rol);

        expect(Gate::allows('update', $cargo))->toBeFalse("El rol {$rol} no debería poder editar un cargo.");
    }
})->note('🔴 Un cargo asentado no se edita. Un trigger de la base lo rechaza igual; que la policy diga lo mismo evita ofrecer un botón que termina en un error de PostgreSQL frente al paciente.');

it('nadie borra cuentas, encuentros ni cargos', function (): void {
    $cuenta = Cuenta::factory()->create();
    $cargo = Cargo::factory()->enLaCuenta($cuenta)->create();
    $encuentro = $cuenta->encuentro;

    foreach (['direccion', 'admision', 'caja', 'auditoria'] as $rol) {
        conElRolDeAtencion($rol);

        expect(Gate::allows('delete', $cuenta))->toBeFalse("El rol {$rol} no debería poder borrar una cuenta.")
            ->and(Gate::allows('delete', $cargo))->toBeFalse("El rol {$rol} no debería poder borrar un cargo.")
            ->and(Gate::allows('delete', $encuentro))->toBeFalse("El rol {$rol} no debería poder borrar un encuentro.");
    }
});

it('una cuenta cerrada ya no acepta el permiso de escritura', function (): void {
    conElRolDeAtencion('caja');

    $cuenta = Cuenta::factory()->cerrada()->create();

    expect(Gate::allows('update', $cuenta))->toBeFalse()
        ->and(Gate::allows('view', $cuenta))->toBeTrue();
})->note('Dejar el botón visible sería prometer algo que el sistema no puede cumplir.');

/*
|--------------------------------------------------------------------------
| La matriz no inventa permisos
|--------------------------------------------------------------------------
*/

it('todos los permisos que la matriz nombra existen de verdad', function (): void {
    $declarados = collect(MatrizDePermisosSeeder::permisosDeclarados());
    $reales = Permission::query()->where('guard_name', 'web')->pluck('name');

    $inventados = $declarados->diff($reales)
        ->reject(fn (string $permiso): bool => ! str_contains($permiso, ':'))
        /*
         * Se filtran los de otros módulos que este test no siembra: acá
         * solo se verifica que los de cuentas existan. El test general de
         * la matriz vive en RolesOperativosTest.
         */
        ->filter(fn (string $permiso): bool => str_contains($permiso, ':Cuenta')
            || str_contains($permiso, ':Cargo')
            || str_contains($permiso, ':Encuentro'));

    expect($inventados->all())->toBe([]);
});
