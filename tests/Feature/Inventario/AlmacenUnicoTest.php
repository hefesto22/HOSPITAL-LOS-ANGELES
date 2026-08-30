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
 * EL ALMACÉN ÚNICO — Y EL DÍA QUE DEJÓ DE SERLO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIÓ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hospital Los Ángeles arrancó con un solo estante: entraba la compra,
 * salía la venta y comía el servicio, todo del mismo lugar. Desde que
 * separó FARMACIA de BODEGA —dos lugares físicos, dos llaves— la bandera
 * `sihla.inventario.modo_almacen_unico` está en `false`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA TRAMPA QUE ESTE ARCHIVO SIGUE CUIDANDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `AlmacenesDelUsuario` decide quién toca qué a partir del TIPO de
 * almacén, leyendo `almacenes_por_rol`. Si un almacén tuviera un tipo que
 * no está en ese mapa, el rol se quedaría sin ver NADA — y no como un
 * error: como una lista vacía, que se lee igual que «todavía no hay
 * nada».
 *
 * Por eso el mapa hoy está VACÍO a propósito: separar los estantes fue un
 * cambio físico, no de permisos. Estas pruebas son las que avisan si
 * alguien lo llena a medias.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('el hospital ya no esta en modo almacen unico', function (): void {
    expect(AlmacenForm::modoUnico())->toBeFalse();
})->note('Si esto se cae, alguien volvió a encender la bandera y el formulario dejó de pedir Tipo y Servicio dueño — con lo cual «CARRITO ROJO 1» ya no se puede crear como stock de un servicio.');

it('el formulario vuelve a ofrecer tipo y servicio cuando el modo esta apagado', function (): void {
    config()->set('sihla.inventario.modo_almacen_unico', true);

    expect(AlmacenForm::modoUnico())->toBeTrue();

    config()->set('sihla.inventario.modo_almacen_unico', false);

    expect(AlmacenForm::modoUnico())->toBeFalse();
})->note('La bandera se lee de la configuración en cada llamada, no se congela al arrancar: la clínica siguiente cambia un archivo y no un deploy.');

it('el almacen unico dispensa a paciente y ademas surte al servicio', function (): void {
    expect(TipoAlmacen::AlmacenUnico->dispensaAPaciente())->toBeTrue()
        ->and(TipoAlmacen::AlmacenUnico->esConsumoInterno())->toBeTrue()
        ->and(TipoAlmacen::AlmacenUnico->esUnico())->toBeTrue();
})->note('El tipo no se borró: los almacenes que nacieron así conservan su histórico, y esta prueba impide que alguien lo saque del enum creyendo que ya no se usa.');

it('la bodega central guarda y traslada, no dispensa', function (): void {
    expect(TipoAlmacen::BodegaCentral->dispensaAPaciente())->toBeFalse()
        ->and(TipoAlmacen::FarmaciaVenta->dispensaAPaciente())->toBeTrue()
        ->and(TipoAlmacen::StockDeServicio->dispensaAPaciente())->toBeTrue();
})->note('🔴 Es la distinción que ordena el resto: si bodega dispensara, un producto saldría del hospital sin pasar por el traslado que deja constancia de que bajó al piso.');

/*
|--------------------------------------------------------------------------
| Nadie se queda sin estante
|--------------------------------------------------------------------------
*/

it('bodega y farmacia ven los dos estantes mientras el mapa este vacio', function (): void {
    expect(config('sihla.inventario.almacenes_por_rol'))->toBe([]);

    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();
    $carrito = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create();

    foreach (['bodega', 'farmacia'] as $rol) {
        /** @var User $usuario */
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol);
        $usuario = $usuario->fresh() ?? $usuario;

        foreach ([$farmacia, $bodega, $carrito] as $almacen) {
            expect(AlmacenesDelUsuario::puedeOperarEn($almacen, $usuario))
                ->toBeTrue("{$rol} se quedó sin {$almacen->nombre}");
        }
    }
})->note('El turno de noche es una persona que abre los dos estantes. Encender el filtro el mismo día que se parten los almacenes es cómo aparece «no puedo dispensar» a las dos de la mañana.');

it('cuando el hospital quiera separar el mando, el mapa alcanza', function (): void {
    config()->set('sihla.inventario.almacenes_por_rol', [
        'bodega'   => ['bodega_central', 'stock_de_servicio'],
        'farmacia' => ['farmacia_venta', 'farmacia_interna'],
    ]);

    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();

    /** @var User $deFarmacia */
    $deFarmacia = User::factory()->create(['is_active' => true]);
    $deFarmacia->assignRole('farmacia');
    $deFarmacia = $deFarmacia->fresh() ?? $deFarmacia;

    expect(AlmacenesDelUsuario::puedeOperarEn($farmacia, $deFarmacia))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($bodega, $deFarmacia))->toBeFalse();
})->note('La separación de mando no necesita migración ni código: el permiso se deriva del TIPO del almacén, y el tipo ya está puesto. Esta prueba es la que garantiza que el día que se encienda, funcione.');
