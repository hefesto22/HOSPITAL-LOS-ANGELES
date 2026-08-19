<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\MargenObjetivo;
use App\Models\User;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los BOTONES también son autorización.
 *
 * Las policies deciden si alguien puede ver un Resource. Pero dentro de un
 * Resource que sí puede ver hay acciones que hacen otra cosa: la
 * calculadora muestra el margen del hospital, «Fijar un precio» escribe el
 * precio de venta, «Pactar un porcentaje» cambia lo que se le cobra a una
 * aseguradora.
 *
 * ⚠️ Las tres nacieron sin condición de permisos. Con el catálogo abierto
 * a todos no se notaba; en cuanto las policies empezaron a cerrar el paso,
 * quedó a la vista que bodega podía abrir la calculadora y ver el margen
 * que la matriz le niega, y que cualquiera que abriera la ficha de un ítem
 * podía fijarle el precio de venta al hospital.
 *
 * Ver algo y poder cambiarlo son permisos distintos, y el botón tiene que
 * saberlo.
 */
$permisosDeLaPrueba = [
    'ViewAny:Item', 'View:Item', 'Update:Item',
    'ViewAny:Convenio', 'Update:Convenio',
    'ViewAny:MargenObjetivo', 'Create:MargenObjetivo',
    'ViewAny:Unidad', 'ViewAny:Persona', 'ViewAny:Activity',
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

function comoElRol(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    test()->actingAs($usuario);

    return $usuario;
}

function unMedicamentoCualquiera(): Item
{
    return Item::factory()->de(
        TipoItem::Medicamento,
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
    )->create();
}

/*
|--------------------------------------------------------------------------
| La calculadora muestra el margen: no es para cualquiera
|--------------------------------------------------------------------------
*/

it('bodega no ve el boton de calcular precio', function (): void {
    comoElRol('bodega');

    expect(CalcularPrecioAction::puedeVerse(unMedicamentoCualquiera()))->toBeFalse();
})->note('Bodega ve el catálogo porque es contra lo que entra la mercadería, pero el modal muestra el margen objetivo y lo que deja cada rango de edad. Eso la matriz no se lo concede.');

it('auditoria si ve el boton de calcular precio', function (): void {
    comoElRol('auditoria');

    expect(CalcularPrecioAction::puedeVerse(unMedicamentoCualquiera()))->toBeTrue();
})->note('Auditoría lee el margen: sin verlo no puede cerrar un hallazgo sobre lo que se le cobró a un paciente.');

it('direccion ve el boton de calcular precio', function (): void {
    comoElRol('direccion');

    expect(CalcularPrecioAction::puedeVerse(unMedicamentoCualquiera()))->toBeTrue();
});

it('el boton sigue oculto en lo que no se compra, aunque el permiso sobre', function (): void {
    comoElRol('direccion');

    $honorario = Item::factory()->de(
        TipoItem::Honorario,
        CategoriaLegalDeDescuento::ConsultaEspecializada,
    )->create();

    expect(CalcularPrecioAction::puedeVerse($honorario))->toBeFalse();
})->note('Son dos condiciones distintas y las dos tienen que cumplirse: permiso para ver el margen, y un ítem que de verdad tenga costo del cual derivar (Ruta B del §4.1).');

/*
|--------------------------------------------------------------------------
| Ver el precio y ponerlo son permisos distintos
|--------------------------------------------------------------------------
*/

it('caja no puede fijar el precio de un item', function (): void {
    $caja = comoElRol('caja');
    $item = unMedicamentoCualquiera();

    expect($caja->can('viewAny', Item::class))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeFalse();
})->note('El paciente ve el precio en el mostrador; ponerlo es otra cosa. La acción «Fijar un precio» se condiciona a `update` sobre el ítem.');

it('direccion si puede fijar el precio de un item', function (): void {
    comoElRol('direccion');

    expect(Gate::allows('update', unMedicamentoCualquiera()))->toBeTrue();
});

it('admision lee el convenio pero no pacta el porcentaje', function (): void {
    $admision = comoElRol('admision');
    $convenio = Convenio::factory()->create();

    expect($admision->can('viewAny', Convenio::class))->toBeTrue()
        ->and(Gate::allows('update', $convenio))->toBeFalse();
})->note('Admisión elige el pagador al ingreso. Cambiar lo que se le cobra a esa aseguradora es de dirección.');

/*
|--------------------------------------------------------------------------
| Fijar un margen es CREAR, no editar
|--------------------------------------------------------------------------
*/

it('auditoria entra a margenes pero no puede fijar uno nuevo', function (): void {
    $auditoria = comoElRol('auditoria');

    expect($auditoria->can('viewAny', MargenObjetivo::class))->toBeTrue()
        ->and(Gate::allows('create', MargenObjetivo::class))->toBeFalse();
});

it('direccion fija margenes nuevos pero no edita los viejos', function (): void {
    comoElRol('direccion');

    $margen = MargenObjetivo::factory()->create();

    expect(Gate::allows('create', MargenObjetivo::class))->toBeTrue()
        ->and(Gate::allows('update', $margen))->toBeFalse();
})->note('Por eso el botón se condiciona a `create` y no a `update`: fijar un margen nuevo inserta una fila, y la policy niega el UPDATE a todo el mundo para que el historial no se pueda reescribir.');
