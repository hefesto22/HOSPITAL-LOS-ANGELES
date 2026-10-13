<?php

declare(strict_types=1);

use App\Filament\Resources\Abonos\AbonoResource;
use App\Filament\Resources\TurnosDeCaja\TurnoDeCajaResource;
use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Arrayable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL MENÚ DE CAJA SE RECOGE (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Abonos recibidos» y «Turnos de caja» cuelgan de «Caja»: Filament los
 * pinta solo cuando se está en Caja o en uno de ellos, y fuera de ahí el
 * grupo Atención queda corto. Lo que este archivo cuida:
 *
 *   · que cuelguen de verdad (y no queden sueltos debajo, como antes);
 *   · que un rol que ve los recibos sin poder entrar a Caja no se quede
 *     sin el renglón: Filament bota en silencio al hijo cuyo papá no se ve.
 *
 * ⚠️ Ayudante con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (['TurnoDeCaja', 'Abono'] as $modelo) {
        foreach (['ViewAny', 'View', 'Create', 'Update'] as $accion) {
            Permission::findOrCreate("{$accion}:{$modelo}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * El grupo «Atención» del menú tal como lo arma Filament para ese rol.
 *
 * @return list<NavigationItem>
 */
function menuDeAtencionComo(string $rol): array
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    test()->actingAs($usuario->fresh() ?? $usuario);

    $grupo = collect(Filament::getNavigation())
        ->first(fn (NavigationGroup $candidato): bool => $candidato->getLabel() === 'Atención');

    if (! $grupo instanceof NavigationGroup) {
        throw new RuntimeException('El menú no trae el grupo Atención.');
    }

    return renglonesDelMenu($grupo->getItems());
}

/**
 * @param array<mixed>|Arrayable<array-key, mixed> $renglones
 *
 * @return list<NavigationItem>
 */
function renglonesDelMenu(array|Arrayable $renglones): array
{
    return array_values(array_filter(
        collect($renglones)->all(),
        fn (mixed $renglon): bool => $renglon instanceof NavigationItem,
    ));
}

/**
 * @param list<NavigationItem> $renglones
 *
 * @return list<string>
 */
function nombresDelMenu(array $renglones): array
{
    return array_map(fn (NavigationItem $renglon): string => $renglon->getLabel(), $renglones);
}

/**
 * Lo que cuelga de «Caja», en el orden en que se pinta.
 *
 * @param list<NavigationItem> $renglones
 *
 * @return list<string>
 */
function loQueCuelgaDeCaja(array $renglones): array
{
    foreach ($renglones as $renglon) {
        if ($renglon->getLabel() === 'Caja') {
            return nombresDelMenu(renglonesDelMenu($renglon->getChildItems()));
        }
    }

    throw new RuntimeException('El grupo Atención no trae Caja.');
}

it('🔴 para la cajera, abonos y turnos cuelgan de Caja y no quedan sueltos en el grupo', function (): void {
    $renglones = menuDeAtencionComo('caja');
    $nombres = nombresDelMenu($renglones);

    expect($nombres)->toContain('Caja');
    expect($nombres)->not->toContain('Abonos recibidos');
    expect($nombres)->not->toContain('Turnos de caja');
    expect(loQueCuelgaDeCaja($renglones))->toBe(['Abonos recibidos', 'Turnos de caja']);
});

it('para dirección también cuelgan de Caja', function (): void {
    $renglones = menuDeAtencionComo('direccion');

    expect(nombresDelMenu($renglones))->not->toContain('Turnos de caja');
    expect(loQueCuelgaDeCaja($renglones))->toBe(['Abonos recibidos', 'Turnos de caja']);
});

it('quien ve los recibos sin poder entrar a Caja los tiene sueltos en el grupo, no perdidos', function (): void {
    Role::findOrCreate('solo_recibos', 'web')->givePermissionTo(['ViewAny:Abono', 'View:Abono']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $nombres = nombresDelMenu(menuDeAtencionComo('solo_recibos'));

    expect($nombres)->toContain('Abonos recibidos');
    expect($nombres)->not->toContain('Caja');
    expect(AbonoResource::getNavigationParentItem())->toBeNull();
    expect(TurnoDeCajaResource::getNavigationParentItem())->toBeNull();
});
