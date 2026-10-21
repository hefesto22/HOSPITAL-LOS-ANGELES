<?php

declare(strict_types=1);

use App\Domain\Enums\TipoItem;
use App\Models\Abono;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Item;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Support\CatalogoDelRol;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UsuariosDePruebaSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * ─────────────────────────────────────────────────────────────────────
 * QUIÉN FACTURA, QUIÉN CARGA QUÉ, Y LOS USUARIOS DE PRUEBA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «un usuario de laboratorio que podrá abrir cuentas y agregar
 * exámenes, pero facturar es exclusivo de las cajeras que estén en
 * turno»; dirección (Angela y el Dr. Juan) también factura; y consulta
 * externa es «como laboratorio, pero para consulta».
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (['Factura', 'Abono', 'TurnoDeCaja', 'Cuenta', 'Cargo', 'Encuentro', 'Persona'] as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function alguienConElRol(string $rol, bool $conTurno = false): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    if ($conTurno) {
        TurnoDeCaja::factory()->create(['usuario_id' => $usuario->id]);
    }

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

it('🔴 la cajera sin turno abierto no factura', function (): void {
    alguienConElRol('caja');

    expect(Gate::allows('create', Factura::class))->toBeFalse();
})->note('La factura casi siempre se cobra en el acto, y esa plata tiene que caer en una gaveta que alguien cuente.');

it('la cajera con su turno abierto factura', function (): void {
    alguienConElRol('caja', conTurno: true);

    expect(Gate::allows('create', Factura::class))->toBeTrue();
});

it('direccion factura aunque no tenga turno', function (): void {
    alguienConElRol('direccion');

    expect(Gate::allows('create', Factura::class))->toBeTrue();
})->note('Decisión de Mauricio: la administración factura. Si hay que cobrar, el abono le pide la gaveta igual.');

it('🔴 laboratorio y consulta externa no facturan ni con un turno abierto', function (string $rol): void {
    alguienConElRol($rol, conTurno: true);

    expect(Gate::allows('create', Factura::class))->toBeFalse();
})->with(['laboratorio', 'consulta_externa']);

it('consulta externa abre cuentas y carga, pero no cobra', function (): void {
    alguienConElRol('consulta_externa');

    expect(Gate::allows('create', Cuenta::class))->toBeTrue()
        ->and(Gate::allows('create', Cargo::class))->toBeTrue()
        ->and(Gate::allows('create', Abono::class))->toBeFalse();
});

it('🔴 consulta externa carga consultas y procedimientos, y nada más', function (): void {
    $usuario = alguienConElRol('consulta_externa');

    $puede = static fn (TipoItem $tipo): bool => CatalogoDelRol::puedeCargar(Item::factory()->make(['tipo' => $tipo]), $usuario);

    expect($puede(TipoItem::Honorario))->toBeTrue()
        ->and($puede(TipoItem::Procedimiento))->toBeTrue()
        ->and($puede(TipoItem::EstudioLaboratorio))->toBeFalse()
        ->and($puede(TipoItem::Medicamento))->toBeFalse()
        ->and($puede(TipoItem::Estancia))->toBeFalse();
})->note('Mismo mecanismo que laboratorio: el permiso dice que puede cargar, el mapa de `sihla.inventario.cargos_por_rol` dice QUÉ.');

it('🔴 laboratorio carga exámenes de laboratorio, y nada más', function (): void {
    $usuario = alguienConElRol('laboratorio');

    $puede = static fn (TipoItem $tipo): bool => CatalogoDelRol::puedeCargar(Item::factory()->make(['tipo' => $tipo]), $usuario);

    expect($puede(TipoItem::EstudioLaboratorio))->toBeTrue()
        ->and($puede(TipoItem::Honorario))->toBeFalse()
        ->and($puede(TipoItem::Estancia))->toBeFalse()
        ->and($puede(TipoItem::Medicamento))->toBeFalse();
})->note('🔴 Esta restricción no funcionaba: `CatalogoDelRol` buscaba el mapa en una clave de config que no existe, y sin mapa nadie quedaba restringido. Esta prueba es la que hubiera avisado.');

it('caja carga cualquier cosa', function (): void {
    $usuario = alguienConElRol('caja');

    expect(CatalogoDelRol::tiposQuePuedeCargar($usuario))->toBeNull();
})->note('Un rol que no está en el mapa no tiene restricción: caja, admisión, enfermería y dirección cargan todo.');

it('🔴 laboratorio y consulta externa le cargan a una cuenta abierta, pero no la cierran ni la rebajan', function (): void {
    $cuenta = Cuenta::factory()->create();

    foreach (['laboratorio', 'consulta_externa'] as $rol) {
        alguienConElRol($rol);

        expect(Gate::allows('cargar', $cuenta))->toBeTrue("{$rol} debería poder cargarle a la cuenta")
            ->and(Gate::allows('update', $cuenta))->toBeFalse("{$rol} NO debería cerrar ni rebajar la cuenta");
    }
})->note('Mauricio, 21-oct-2026: agregar un ácido úrico desde laboratorio daba «Error al cargar la página». La pantalla pedía `update` —que es también cerrar y poner descuento— para agregar una línea.');

it('nadie le carga a una cuenta cerrada, ni caja', function (): void {
    $cuenta = Cuenta::factory()->cerrada()->create();

    foreach (['caja', 'laboratorio'] as $rol) {
        alguienConElRol($rol);

        expect(Gate::allows('cargar', $cuenta))->toBeFalse("{$rol} no debería cargarle a una cuenta cerrada");
    }
});

it('quien no tiene Create:Cargo no carga', function (): void {
    $cuenta = Cuenta::factory()->create();

    alguienConElRol('auditoria');

    expect(Gate::allows('cargar', $cuenta))->toBeFalse();
});

it('farmacia carga lo que despacha, y nada más', function (): void {
    $usuario = alguienConElRol('farmacia');

    $puede = static fn (TipoItem $tipo): bool => CatalogoDelRol::puedeCargar(Item::factory()->make(['tipo' => $tipo]), $usuario);

    expect($puede(TipoItem::Medicamento))->toBeTrue()
        ->and($puede(TipoItem::Insumo))->toBeTrue()
        ->and($puede(TipoItem::Estancia))->toBeFalse()
        ->and($puede(TipoItem::EstudioLaboratorio))->toBeFalse()
        ->and($puede(TipoItem::Honorario))->toBeFalse();
})->note('Desde que cargar dejó de pedir `Update:Cuenta`, farmacia puede cargar; el mapa la deja en medicamentos e insumos.');

/*
|--------------------------------------------------------------------------
| El seeder de usuarios de prueba
|--------------------------------------------------------------------------
*/

it('siembra las tres cajeras, laboratorio, consulta externa y los dos de direccion', function (): void {
    Sede::factory()->create();

    test()->seed(UsuariosDePruebaSeeder::class);

    foreach (UsuariosDePruebaSeeder::USUARIOS as $datos) {
        $usuario = User::query()->where('email', $datos['email'])->firstOrFail();

        expect($usuario->getRoleNames()->all())->toBe([$datos['rol']])
            ->and($usuario->turno)->toBe($datos['turno'])
            ->and($usuario->sede_id)->not->toBeNull()
            ->and(Hash::check('12345678', $usuario->password))->toBeTrue();
    }

    expect(User::query()->where('email', 'like', '%@gmail.com')->count())->toBe(count(UsuariosDePruebaSeeder::USUARIOS));
});

it('correrlo de nuevo no le pisa la contraseña a nadie', function (): void {
    Sede::factory()->create();
    test()->seed(UsuariosDePruebaSeeder::class);

    $angela = User::query()->where('email', 'angela.cardona@gmail.com')->firstOrFail();
    $angela->forceFill(['password' => Hash::make('la-que-ella-eligio')])->save();

    test()->seed(UsuariosDePruebaSeeder::class);

    expect(Hash::check('la-que-ella-eligio', (string) $angela->fresh()?->password))->toBeTrue()
        ->and(User::query()->where('email', 'angela.cardona@gmail.com')->count())->toBe(1);
});

it('🔴 en produccion no corre sin la marca del servidor de pruebas', function (): void {
    Sede::factory()->create();
    app()->instance('env', 'production');

    /*
     * Directo y no con `seed()`: `db:seed` en producción pide
     * confirmación, y sin consola se cancela solo — la prueba pasaría
     * sin que el seeder llegara a decir que no.
     */
    expect(fn () => app(UsuariosDePruebaSeeder::class)->run())->toThrow(RuntimeException::class);
})->note('Contraseña conocida y cajeras por turno: en el servidor del hospital, jamás.');
