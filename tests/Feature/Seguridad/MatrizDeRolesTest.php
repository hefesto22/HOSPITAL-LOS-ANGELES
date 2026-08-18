<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Protege la columna "QUÉ NO PUEDE" del §1.4.
 *
 * La parte fácil de un sistema de permisos es dar acceso. La que se rompe
 * en silencio es la que lo quita: nadie nota que la cajera puede ver el
 * expediente clínico hasta que alguien lo usa.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

it('crea los once roles del hospital', function (): void {
    $esperados = array_keys(RoleSeeder::ROLES);

    $existentes = Role::query()->pluck('name')->all();

    foreach ($esperados as $rol) {
        expect($existentes)->toContain($rol);
    }

    expect($esperados)->toHaveCount(10)
        ->and($existentes)->toContain('super_admin');
})->note('Diez roles del hospital más super_admin, que es de soporte y no del hospital.');

it('deja entrar al panel a un usuario activo con rol operativo', function (): void {
    /** @var User $medico */
    $medico = User::factory()->create(['is_active' => true]);
    $medico->assignRole('medico');

    expect($medico->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
})->note('Sin este cambio, los diez roles del §1.4 quedaban fuera del sistema y el síntoma parecía un bug de Filament.');

it('no deja entrar a un usuario sin ningun rol', function (): void {
    /** @var User $huerfano */
    $huerfano = User::factory()->create(['is_active' => true]);

    expect($huerfano->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
})->note('Un usuario recién creado al que nadie le asignó función no debe ver nada.');

it('no deja entrar a un usuario inactivo aunque tenga rol', function (): void {
    /** @var User $suspendido */
    $suspendido = User::factory()->create(['is_active' => false]);
    $suspendido->assignRole('direccion');

    expect($suspendido->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('deja la bitacora solo para auditoria y direccion', function (): void {
    Permission::findOrCreate('view_any_activity', 'web');
    Permission::findOrCreate('view_activity', 'web');

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $conBitacora = ['auditoria', 'direccion'];

    foreach (array_keys(RoleSeeder::ROLES) as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        $tiene = $rol->hasPermissionTo('view_any_activity');

        in_array($nombreRol, $conBitacora, true)
            ? expect($tiene)->toBeTrue("El rol {$nombreRol} debería leer la bitácora")
            : expect($tiene)->toBeFalse("El rol {$nombreRol} NO debería leer la bitácora");
    }
})->note('Quien audita no puede ser parte de lo auditado, y el resto no tiene por qué ver quién leyó qué expediente.');

it('deja a los roles operativos sin permisos hasta que exista su modulo', function (): void {
    Permission::findOrCreate('view_any_user', 'web');

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['caja', 'medico', 'enfermeria', 'farmacia', 'laboratorio', 'imagenes', 'bodega', 'admision'] as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        expect($rol->permissions)->toHaveCount(0, "El rol {$nombreRol} no debería tener permisos todavía");
    }
})->note('La matriz es allowlist: un permiso nuevo no se le concede a nadie por descuido.');

it('borra los permisos que alguien agregue a mano en el panel', function (): void {
    Permission::findOrCreate('view_any_user', 'web');

    /** @var Role $caja */
    $caja = Role::findByName('caja', 'web');
    $caja->givePermissionTo('view_any_user');

    expect($caja->fresh()?->permissions)->toHaveCount(1);

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($caja->fresh()?->permissions)->toHaveCount(0);
})->note('El seeder es la única fuente de verdad: un permiso dado por el panel no viaja al deploy ni se puede auditar.');
