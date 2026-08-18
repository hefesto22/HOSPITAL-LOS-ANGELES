<?php

declare(strict_types=1);

use App\Models\Sede;
use App\Models\Servicio;
use App\Models\User;
use App\Support\ContextoSede;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * EL TEST QUE EXIGE EL ADR-0002.
 *
 * El scope global de BelongsToSede es comodidad; ESTE archivo es la
 * garantía. Si alguien lo borra o lo debilita, el aislamiento entre sedes
 * deja de existir y nadie se entera hasta que un usuario de la sede 2 ve
 * los cargos de la sede 1.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function usuarioDeSede(Sede $sede, ?string $rol = null): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create([
        'sede_id'   => $sede->id,
        'is_active' => true,
    ]);

    if ($rol !== null) {
        Role::findOrCreate($rol, 'web');
        $usuario->assignRole($rol);
    }

    return $usuario;
}

it('rellena sede_id solo, sin que nadie se acuerde', function (): void {
    $sede = Sede::factory()->create();
    test()->actingAs(usuarioDeSede($sede));

    $servicio = Servicio::factory()->create(['sede_id' => null]);

    expect($servicio->sede_id)->toBe($sede->id);
})->note('Un sede_id nulo en una tabla clínica es un registro que después nadie puede atribuir.');

it('no deja ver en el listado los registros de otra sede', function (): void {
    $mia = Sede::factory()->create();
    $otra = Sede::factory()->create();

    Servicio::factory()->create(['sede_id' => $mia->id, 'codigo' => 'MIO']);
    Servicio::factory()->create(['sede_id' => $otra->id, 'codigo' => 'AJENO']);

    test()->actingAs(usuarioDeSede($mia));

    $codigos = Servicio::query()->pluck('codigo')->all();

    expect($codigos)->toContain('MIO')
        ->and($codigos)->not->toContain('AJENO');
});

it('no deja traer POR ID un registro de otra sede', function (): void {
    $mia = Sede::factory()->create();
    $otra = Sede::factory()->create();

    $ajeno = Servicio::factory()->create(['sede_id' => $otra->id]);

    test()->actingAs(usuarioDeSede($mia));

    expect(Servicio::query()->find($ajeno->id))->toBeNull();
})->note('El acceso directo por URL de edición es el agujero típico en Filament: el listado filtra, la ruta de edición no.');

it('deja a direccion cruzar sedes', function (): void {
    $mia = Sede::factory()->create();
    $otra = Sede::factory()->create();

    Servicio::factory()->create(['sede_id' => $mia->id]);
    Servicio::factory()->create(['sede_id' => $otra->id]);

    test()->actingAs(usuarioDeSede($mia, 'direccion'));

    expect(Servicio::query()->count())->toBe(2)
        ->and(ContextoSede::idsVisibles())->toBeNull();
})->note('null en idsVisibles significa TODAS. Un arreglo vacio significaria NINGUNA y dejaria al usuario sin datos.');

it('deja al super admin cruzar sedes', function (): void {
    $mia = Sede::factory()->create();
    Sede::factory()->create();

    Servicio::factory()->count(3)->create(['sede_id' => $mia->id]);

    test()->actingAs(usuarioDeSede($mia, ShieldUtils::getSuperAdminName()));

    expect(Servicio::query()->count())->toBe(3);
});

it('no filtra cuando no hay usuario autenticado', function (): void {
    Servicio::factory()->count(2)->create();

    expect(Servicio::query()->count())->toBe(2)
        ->and(ContextoSede::idsVisibles())->toBeNull();
})->note('Consola, colas y seeders. Filtrar a vacio haria que un cierre de mes reporte cero y eso no se ve como error, se ve como un mes malo.');

it('ignora una sede puesta a mano en la sesion si el usuario no la puede ver', function (): void {
    $mia = Sede::factory()->create();
    $otra = Sede::factory()->create();

    test()->actingAs(usuarioDeSede($mia));

    expect(ContextoSede::establecer($otra->id))->toBeFalse()
        ->and(ContextoSede::actualId())->toBe($mia->id);
})->note('Un id de sede en la sesion no es una credencial.');

it('permite salir del filtro solo de forma explicita', function (): void {
    $mia = Sede::factory()->create();
    $otra = Sede::factory()->create();

    Servicio::factory()->create(['sede_id' => $mia->id]);
    Servicio::factory()->create(['sede_id' => $otra->id]);

    test()->actingAs(usuarioDeSede($mia));

    expect(Servicio::query()->count())->toBe(1)
        ->and(Servicio::query()->deTodasLasSedes()->count())->toBe(2);
})->note('Quitar un filtro de seguridad nunca debe ser el comportamiento por defecto de nada: se llama a proposito y se ve en el codigo.');
