<?php

declare(strict_types=1);

use App\Models\Sede;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UsuarioDeDireccionSeeder;
use Filament\Facades\Filament;

/**
 * La administradora del hospital — el usuario con el que se va a trabajar
 * todos los días.
 *
 * Lo que este archivo protege no es «que el seeder corra». Es una
 * decisión de seguridad que se toma UNA vez y que después nadie vuelve a
 * mirar: **la administradora no es super_admin**.
 *
 * `super_admin` es de soporte y Shield le monta un `Gate::before` que
 * responde `true` antes de consultar cualquier policy. Con ese rol, las
 * policies escritas a mano —las que niegan borrar, las de
 * break-the-glass del expediente, las de inmutabilidad— no se ejecutan.
 * El sistema deja de poder explicar por qué se permitió algo.
 *
 * El día que alguien «arregle» un permiso faltante dándole super_admin
 * desde el panel, este archivo se pone rojo.
 */
beforeEach(function (): void {
    Sede::factory()->create();

    $this->seed(RoleSeeder::class);
    $this->seed(UsuarioDeDireccionSeeder::class);
});

/*
 * Se la busca POR EL ROL y no por el correo: el correo y el nombre salen
 * del .env y cambian de hospital en hospital. Lo que no cambia —y lo que
 * estos tests protegen— es que exista exactamente UNA persona con el rol
 * de dirección.
 */
function laAdministradora(): User
{
    return User::query()->role(UsuarioDeDireccionSeeder::ROL)->sole();
}

it('crea a la administradora con el rol de dirección', function (): void {
    $ella = laAdministradora();

    expect($ella->hasRole(UsuarioDeDireccionSeeder::ROL))->toBeTrue()
        ->and($ella->is_active)->toBeTrue()
        ->and($ella->roles()->count())->toBe(1);
});

it('no le da super_admin, y se lo quita si alguien se lo puso a mano', function (): void {
    $ella = laAdministradora();

    expect($ella->hasRole(ShieldUtils::getSuperAdminName()))->toBeFalse();

    /*
     * El escenario real: falta un permiso, alguien entra al panel y
     * «lo arregla» dándole super_admin. El seeder usa syncRoles, así que
     * la próxima corrida reconcilia contra el repo y se lo saca.
     */
    $ella->assignRole(ShieldUtils::getSuperAdminName());
    expect($ella->fresh()?->hasRole(ShieldUtils::getSuperAdminName()))->toBeTrue();

    $this->seed(UsuarioDeDireccionSeeder::class);

    expect(laAdministradora()->hasRole(ShieldUtils::getSuperAdminName()))->toBeFalse();
})->note('La decisión vive en el repo, no en lo que alguien recuerde haber hecho un martes.');

it('no le pisa la contraseña cuando el deploy vuelve a sembrar', function (): void {
    $ella = laAdministradora();

    /*
     * Ella cambió su contraseña desde el panel. Si el seeder la
     * reescribiera en cada `db:seed` —como hace el de soporte—, el
     * próximo deploy la dejaría afuera de su propio sistema sin ningún
     * mensaje que lo explique.
     */
    $ella->forceFill(['password' => bcrypt('la-que-ella-eligio')])->save();
    $hash = $ella->fresh()?->getAttribute('password');

    $this->seed(UsuarioDeDireccionSeeder::class);

    expect(laAdministradora()->getAttribute('password'))->toBe($hash);
});

it('la deja entrar al panel', function (): void {
    expect(laAdministradora()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
})->note('Un rol sin permisos deja entrar y no muestra nada; eso se diagnostica como «no me funciona el sistema».');

it('le asigna la única sede vigente', function (): void {
    $sede = Sede::query()->sole();

    expect(laAdministradora()->getAttribute('sede_id'))->toBe($sede->id);
})->note('direccion cruza sedes igual —ContextoSede::veTodas() no mira esta columna—, pero sin default no puede abrir turno de caja.');

it('la revive si estaba dada de baja', function (): void {
    laAdministradora()->delete();

    expect(User::query()->role(UsuarioDeDireccionSeeder::ROL)->exists())->toBeFalse();

    $this->seed(UsuarioDeDireccionSeeder::class);

    expect(laAdministradora()->hasRole(UsuarioDeDireccionSeeder::ROL))->toBeTrue();
})->note('Sin esto, dar de baja al usuario y re-sembrar reventaba con el único de email.');
