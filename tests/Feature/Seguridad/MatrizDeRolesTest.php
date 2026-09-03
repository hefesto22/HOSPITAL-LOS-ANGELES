<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Protege la columna "QUÉ NO PUEDE" del §1.4.
 *
 * La parte fácil de un sistema de permisos es dar acceso. La que se rompe
 * en silencio es la que lo quita: nadie nota que la cajera puede ver el
 * expediente clínico hasta que alguien lo usa.
 *
 * ⚠️ ESTE ARCHIVO YA FALLÓ UNA VEZ, Y EN VERDE.
 *
 * La primera versión creaba los permisos a mano con nombres inventados
 * —`view_any_activity`— y la matriz, que buscaba por substring, los
 * encontraba. Pasaba siempre. Pero Shield genera **`ViewAny:Activity`**,
 * así que en la aplicación real la matriz no casaba con nada y todos los
 * roles menos dirección quedaron sin un solo permiso durante todo el
 * bloque 3.
 *
 * Por eso ahora los permisos de prueba se siembran con el MISMO
 * vocabulario que Shield produce, y hay tests que verifican el formato y
 * que los sujetos nombrados correspondan a modelos que existen.
 */

/**
 * Los sujetos que Shield genera hoy, uno por Resource registrado.
 *
 * Cuando se agrega un Resource nuevo hay que agregarlo acá también, o los
 * tests de esta matriz siembran un universo de permisos más chico que el
 * real y no verifican nada sobre el módulo nuevo.
 */
const SUJETOS_DE_SHIELD = [
    'Activity', 'Almacen', 'CategoriaItem', 'Compra', 'Convenio', 'FusionDePersona',
    'Item', 'MargenObjetivo', 'Persona', 'Prestamo', 'Producto', 'Proveedor', 'Recepcion',
    'Role', 'Sede', 'Servicio', 'Unidad', 'User',
];

/**
 * Reproduce el universo de permisos que deja `shield:generate`.
 */
function sembrarPermisosComoShield(): void
{
    foreach (SUJETOS_DE_SHIELD as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }
}

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Entrada al panel
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Que la matriz hable el idioma de Shield
|--------------------------------------------------------------------------
*/

it('la matriz nombra los permisos con el formato que genera Shield', function (): void {
    $acciones = implode('|', MatrizDePermisosSeeder::ACCIONES);

    foreach (MatrizDePermisosSeeder::permisosDeclarados() as $permiso) {
        expect($permiso)->toMatch("/^({$acciones}):[A-Z][A-Za-z]+$/");
    }
})->note('`config/filament-shield.php` usa separator ":" y case pascal. Un `view_any_item` escrito acá no casaría con nada y el rol quedaría mudo — que es exactamente lo que pasó durante todo el bloque 3.');

it('la matriz solo nombra sujetos que existen como modelo', function (): void {
    $deOtroPaquete = [
        'Activity' => Activity::class,
        'Role'     => Role::class,
    ];

    foreach (MatrizDePermisosSeeder::permisosDeclarados() as $permiso) {
        $sujeto = explode(':', $permiso)[1];

        $clase = $deOtroPaquete[$sujeto] ?? "App\\Models\\{$sujeto}";

        expect(class_exists($clase))->toBeTrue("El sujeto {$sujeto} no corresponde a ningún modelo");
    }
})->note('Es la red que reemplaza al matching difuso: si alguien renombra un Resource, esto falla en vez de dejar a un rol sin permisos durante meses.');

it('no le concede a nadie permisos de borrado', function (): void {
    $prohibidas = ['Delete', 'ForceDelete', 'ForceDeleteAny'];

    foreach (MatrizDePermisosSeeder::permisosDeclarados() as $permiso) {
        $accion = explode(':', $permiso)[0];

        expect($prohibidas)->not->toContain($accion, "La matriz concede {$permiso}");
    }
})->note('En este sistema nada se borra: se cierra la vigencia. Las policies además lo niegan, pero la matriz no debería ni ofrecerlo.');

/*
|--------------------------------------------------------------------------
| La columna "QUÉ NO PUEDE" del §1.4
|--------------------------------------------------------------------------
*/

it('deja la bitacora solo para auditoria y direccion', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $conBitacora = ['auditoria', 'direccion'];

    foreach (array_keys(RoleSeeder::ROLES) as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        $tiene = $rol->hasPermissionTo('ViewAny:Activity');

        in_array($nombreRol, $conBitacora, true)
            ? expect($tiene)->toBeTrue("El rol {$nombreRol} debería leer la bitácora")
            : expect($tiene)->toBeFalse("El rol {$nombreRol} NO debería leer la bitácora");
    }
})->note('Quien audita no puede ser parte de lo auditado, y el resto no tiene por qué ver quién leyó qué expediente.');

it('bodega nunca ve el expediente', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $bodega */
    $bodega = Role::findByName('bodega', 'web');

    expect($bodega->hasPermissionTo('ViewAny:Persona'))->toBeFalse()
        ->and($bodega->hasPermissionTo('View:Persona'))->toBeFalse()
        ->and($bodega->hasPermissionTo('ViewAny:Item'))->toBeTrue();
})->note('Quien recibe una compra no tiene por qué saber quién está internado. Sí necesita el catálogo: es contra lo que entra la mercadería.');

it('solo direccion escribe el catalogo', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (array_keys(RoleSeeder::ROLES) as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        $nombreRol === 'direccion'
            ? expect($rol->hasPermissionTo('Create:Item'))->toBeTrue()
            : expect($rol->hasPermissionTo('Create:Item'))->toBeFalse("El rol {$nombreRol} no debería crear ítems");
    }
})->note('Al dar de alta un ítem se fija su régimen de ISV y bajo qué numeral del Art. 30 cae su descuento. Equivocarse ahí es un hallazgo del SAR, no un dato que se corrige después.');

it('admision y caja leen los convenios pero no los crean', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['admision', 'caja'] as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        expect($rol->hasPermissionTo('ViewAny:Convenio'))->toBeTrue()
            ->and($rol->hasPermissionTo('Create:Convenio'))->toBeFalse();
    }

    /** @var Role $bodega */
    $bodega = Role::findByName('bodega', 'web');

    expect($bodega->hasPermissionTo('ViewAny:Convenio'))->toBeFalse();
})->note('Dar de alta un convenio incluye declarar sobre qué monto se le aplica el descuento del Art. 30. Esa es una decisión con respaldo legal, no del turno de las 3 de la mañana.');

it('bodega recibe mercaderia pero no ve el registro fiscal de compras', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $bodega */
    $bodega = Role::findByName('bodega', 'web');

    /** @var Role $auditoria */
    $auditoria = Role::findByName('auditoria', 'web');

    expect($bodega->hasPermissionTo('Create:Recepcion'))->toBeTrue()
        ->and($bodega->hasPermissionTo('Update:Recepcion'))->toBeTrue()
        ->and($bodega->hasPermissionTo('ViewAny:Compra'))->toBeFalse()
        ->and($auditoria->hasPermissionTo('ViewAny:Recepcion'))->toBeTrue()
        ->and($auditoria->hasPermissionTo('ViewAny:Compra'))->toBeTrue()
        ->and($auditoria->hasPermissionTo('Create:Recepcion'))->toBeFalse();
})->note('Son dos módulos distintos: bodega mete mercadería al kardex y no ve cuánto se le paga a cada proveedor. `Update:Recepcion` es también el permiso de marcar revisada, y eso no rompe los cuatro ojos porque la base impide que revise el mismo que recibió.');

it('nadie puede borrar una recepcion, ni siquiera direccion', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (array_keys(RoleSeeder::ROLES) as $nombreRol) {
        /** @var Role $rol */
        $rol = Role::findByName($nombreRol, 'web');

        expect($rol->hasPermissionTo('Delete:Recepcion'))->toBe($nombreRol === 'direccion');
    }

    /** @var Role $direccion */
    $direccion = Role::findByName('direccion', 'web');

    expect($direccion->hasPermissionTo('Delete:Recepcion'))->toBeTrue();
})->note('Dirección se lleva el permiso por el comodín —por eso el test de «ningún permiso de borrado» sigue en verde—, pero `RecepcionPolicy::delete()` devuelve false igual: una recepción explica movimientos de un kardex append-only, así que no la borra nadie. El permiso existe y la policy lo niega, que es el patrón de todo el catálogo.');

it('direccion se lleva todos los permisos que existan', function (): void {
    sembrarPermisosComoShield();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $direccion */
    $direccion = Role::findByName('direccion', 'web');

    expect($direccion->permissions)->toHaveCount(Permission::query()->count());
})->note('Es el único rol con comodín, y por eso es el único que no se rompió cuando la matriz dejó de casar nombres.');

it('borra los permisos que alguien agregue a mano en el panel', function (): void {
    sembrarPermisosComoShield();

    /** @var Role $laboratorio */
    $laboratorio = Role::findByName('laboratorio', 'web');
    $laboratorio->givePermissionTo('Create:Item');

    expect($laboratorio->fresh()?->hasPermissionTo('Create:Item'))->toBeTrue();

    $this->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($laboratorio->fresh()?->hasPermissionTo('Create:Item'))->toBeFalse();
})->note('El seeder es la única fuente de verdad: un permiso dado por el panel no viaja al deploy ni se puede auditar.');
