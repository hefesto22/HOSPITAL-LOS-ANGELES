<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use App\Filament\Resources\Almacenes\Schemas\AlmacenForm;
use App\Models\Almacen;
use App\Models\User;
use App\Support\AlmacenesDelUsuario;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * EL ALMACÉN ÚNICO — Hospital Los Ángeles no divide el inventario.
 *
 * Lo que este archivo cuida es la trampa silenciosa: `AlmacenesDelUsuario`
 * decide quién toca qué a partir del TIPO de almacén. Si el único almacén
 * del hospital tuviera un tipo que no está en `almacenes_por_rol`, la
 * farmacia —o la bodega— se quedaría sin ver NADA, y no como un error:
 * como una lista vacía, que se lee igual que «todavía no hay nada».
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('el hospital arranca en modo almacen unico', function (): void {
    expect(AlmacenForm::modoUnico())->toBeTrue();
})->note('Si esto se cae, alguien apagó la bandera y la pantalla volvió a pedir Tipo y Servicio dueño.');

it('el almacen unico dispensa a paciente y ademas surte al servicio', function (): void {
    expect(TipoAlmacen::AlmacenUnico->dispensaAPaciente())->toBeTrue()
        ->and(TipoAlmacen::AlmacenUnico->esConsumoInterno())->toBeTrue()
        ->and(TipoAlmacen::AlmacenUnico->esUnico())->toBeTrue();
})->note('Es la diferencia con bodega central, que NO dispensa: si el único almacén no dispensara, no habría de dónde cobrarle al paciente.');

it('bodega y farmacia trabajan las dos sobre el almacen unico', function (): void {
    $almacen = Almacen::factory()->unico()->create();

    foreach (['bodega', 'farmacia'] as $rol) {
        /** @var User $usuario */
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol);

        expect(AlmacenesDelUsuario::puedeOperarEn($almacen, $usuario->fresh() ?? $usuario))
            ->toBeTrue("{$rol} se quedó sin el único almacén del hospital");
    }
})->note('Con un solo estante, dejar fuera a un rol es dejarlo sin inventario — y la pantalla se ve vacía, no rota.');

it('el tipo unico no se ofrece en el select cuando el modo esta apagado', function (): void {
    config()->set('sihla.inventario.modo_almacen_unico', false);

    expect(AlmacenForm::modoUnico())->toBeFalse();
})->note('La clínica que sí divide no debería poder elegir «Almacén del hospital» a mano: o divide, o no divide.');
