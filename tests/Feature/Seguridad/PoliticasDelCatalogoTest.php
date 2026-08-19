<?php

declare(strict_types=1);

use App\Models\Almacen;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\MargenObjetivo;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Servicio;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * LA PRUEBA CON UN ROL RESTRINGIDO QUE EXIGE LA DoD (§5).
 *
 * ⚠️ ESTE ARCHIVO NACE DE UN AGUJERO REAL.
 *
 * Los Resources del bloque 3 se construyeron sin Policy. Y sin policy
 * Filament **no deniega: deja pasar**. El camino está en
 * `vendor/filament/filament/src/helpers.php`, en
 * `get_authorization_response()`: si no hay policy para el modelo y el
 * panel no está en modo estricto, la última línea es `Response::allow()`.
 *
 * O sea que el catálogo completo, los márgenes, los convenios y los
 * precios estuvieron abiertos a cualquiera que entrara al panel —bodega,
 * caja, laboratorio— mientras la matriz de permisos parecía estar
 * cuidándolos.
 *
 * Los tests de la matriz no lo veían porque prueban qué permisos tiene
 * cada rol, no si alguien los consulta. Estos prueban lo segundo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * Y YA SALVÓ EL MISMO AGUJERO UNA SEGUNDA VEZ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un `php artisan shield:generate --all` —corrido para crear los
 * permisos de dos Resources nuevos— **reescribió las catorce policies**
 * con la plantilla de Shield, donde cada método delega en el permiso del
 * mismo nombre: `delete()` pasó a devolver `$authUser->can('Delete:Item')`
 * en vez de `false`.
 *
 * O sea que dirección volvió a poder borrar el catálogo, sin un solo
 * cambio en el código escrito a mano y sin ningún aviso. Los únicos tres
 * tests que fallaron fueron los de acá abajo.
 *
 * Por eso `config/filament-shield.php` tiene ahora
 * `policies.generate => false`: Shield crea los permisos y no toca
 * `app/Policies/`. Si alguien lo vuelve a poner en true y regenera, esto
 * es lo que se pone rojo.
 */
$permisosDeLaPrueba = [
    'ViewAny:Item', 'View:Item', 'Create:Item', 'Update:Item', 'Delete:Item',
    'ViewAny:Persona', 'View:Persona',
    'ViewAny:Convenio', 'Create:Convenio', 'Delete:Convenio',
    'ViewAny:MargenObjetivo', 'Update:MargenObjetivo',
    'ViewAny:Unidad', 'ViewAny:Activity',
];

beforeEach(function () use ($permisosDeLaPrueba): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RoleSeeder::class);

    foreach ($permisosDeLaPrueba as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    $this->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function usuarioCon(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    return $usuario->fresh() ?? $usuario;
}

/*
|--------------------------------------------------------------------------
| Que exista quien consulte los permisos
|--------------------------------------------------------------------------
*/

it('cada modelo del catalogo tiene su policy registrada', function (): void {
    $modelos = [
        Item::class, Unidad::class, Convenio::class, MargenObjetivo::class,
        Almacen::class, Sede::class, Servicio::class, Persona::class,
    ];

    foreach ($modelos as $modelo) {
        expect(Gate::getPolicyFor($modelo))->not->toBeNull(
            "El modelo {$modelo} no tiene policy: Filament lo deja abierto a todo el mundo"
        );
    }
})->note('Es el test que habría atrapado el agujero. Sin policy, Filament no deniega — permite, y el permiso sembrado no lo consulta nadie.');

/*
|--------------------------------------------------------------------------
| Lo que un rol restringido NO puede
|--------------------------------------------------------------------------
*/

it('bodega no puede ver el expediente aunque entre al panel', function (): void {
    $bodega = usuarioCon('bodega');

    expect($bodega->can('viewAny', Persona::class))->toBeFalse()
        ->and($bodega->can('viewAny', Item::class))->toBeTrue();
})->note('El bug más caro de este sistema es «la cajera vio el expediente». Acá es bodega, y la respuesta tiene que ser no.');

it('auditoria lee el catalogo pero no lo escribe', function (): void {
    $auditoria = usuarioCon('auditoria');

    expect($auditoria->can('viewAny', Item::class))->toBeTrue()
        ->and($auditoria->can('create', Item::class))->toBeFalse();
})->note('Quien audita no puede ser parte de lo auditado.');

it('caja no puede dar de alta un convenio', function (): void {
    $caja = usuarioCon('caja');

    expect($caja->can('viewAny', Convenio::class))->toBeTrue()
        ->and($caja->can('create', Convenio::class))->toBeFalse();
})->note('Caja factura contra el convenio, así que lo necesita ver. Crearlo incluye declarar la lectura del Art. 30, y eso es de dirección.');

it('laboratorio no toca los margenes', function (): void {
    $laboratorio = usuarioCon('laboratorio');

    expect($laboratorio->can('viewAny', MargenObjetivo::class))->toBeFalse();
})->note('El margen objetivo es política comercial. Que lo vea quien procesa muestras no aporta nada y sí filtra cuánto gana el hospital por cada estudio.');

/*
|--------------------------------------------------------------------------
| Lo que dirección SÍ puede
|--------------------------------------------------------------------------
*/

it('direccion escribe el catalogo y los convenios', function (): void {
    $direccion = usuarioCon('direccion');

    expect($direccion->can('viewAny', Item::class))->toBeTrue()
        ->and($direccion->can('create', Item::class))->toBeTrue()
        ->and($direccion->can('create', Convenio::class))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Lo que NADIE puede, por más permiso que tenga
|--------------------------------------------------------------------------
*/

it('nadie borra un convenio, ni siquiera direccion', function (): void {
    $direccion = usuarioCon('direccion');
    $convenio = Convenio::factory()->create();

    expect($direccion->can('delete', $convenio))->toBeFalse()
        ->and($direccion->can('forceDelete', $convenio))->toBeFalse();
})->note('Borrarlo dejaría facturas apuntando a un pagador inexistente. La policy lo niega aunque el permiso Delete:Convenio exista y dirección lo tenga por comodín.');

it('nadie borra un item, ni siquiera direccion', function (): void {
    $direccion = usuarioCon('direccion');
    $item = Item::factory()->create();

    expect($direccion->can('delete', $item))->toBeFalse();
})->note('Un ítem se retira poniéndole fecha de fin de vigencia. Borrarlo dejaría cargos apuntando a la nada y una factura que ya no se puede reimprimir.');

it('el margen objetivo no se edita ni con permiso', function (): void {
    $direccion = usuarioCon('direccion');
    $margen = MargenObjetivo::factory()->create();

    expect($direccion->can('update', $margen))->toBeFalse()
        ->and($direccion->can('viewAny', MargenObjetivo::class))->toBeTrue();
})->note('Cambiar el margen es cerrar el vigente y abrir uno nuevo con fecha. Un UPDATE borraría la respuesta a por qué ese producto se vendía a ese precio en marzo.');
