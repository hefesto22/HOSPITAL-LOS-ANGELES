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
 * Por eso `almacen_unico` sigue en las DOS listas: los almacenes que
 * nacieron antes de partir los estantes conservan ese tipo, y sacarlo del
 * mapa dejaría ciego al rol que todavía trabaja sobre ellos.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Un usuario activo con ese rol, ya refrescado para que Spatie vea sus
 * permisos recién asignados.
 */
function conElRol(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    return $usuario->fresh() ?? $usuario;
}

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

it('cada rol responde por su propio estante', function (): void {
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();
    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();
    $carrito = Almacen::factory()->de(TipoAlmacen::StockDeServicio)->create();

    $deFarmacia = conElRol('farmacia');
    $deBodega = conElRol('bodega');

    expect(AlmacenesDelUsuario::puedeOperarEn($farmacia, $deFarmacia))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($bodega, $deFarmacia))->toBeFalse()
        ->and(AlmacenesDelUsuario::puedeOperarEn($carrito, $deFarmacia))->toBeFalse()
        ->and(AlmacenesDelUsuario::puedeOperarEn($bodega, $deBodega))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($carrito, $deBodega))->toBeTrue()
        ->and(AlmacenesDelUsuario::puedeOperarEn($farmacia, $deBodega))->toBeFalse();
})->note('Contar y ajustar exigen el estante propio: ahí no hay contraparte que deje rastro, el número simplemente cambia. Los carritos son de bodega, que es quien baja la mercadería — no del mostrador que dispensa.');

it('el almacen unico sigue estando en las dos listas', function (): void {
    $viejo = Almacen::factory()->unico()->create();

    foreach (['bodega', 'farmacia'] as $rol) {
        expect(AlmacenesDelUsuario::puedeOperarEn($viejo, conElRol($rol)))
            ->toBeTrue("{$rol} se quedó sin el almacén que existía antes de partir los estantes");
    }
})->note('🔴 Los almacenes que nacieron antes de dividir conservan el tipo `almacen_unico`. Sacarlo del mapa dejaría a un rol sin ver NADA — y no como error, sino como lista vacía, que se lee igual que «todavía no hay nada».');
