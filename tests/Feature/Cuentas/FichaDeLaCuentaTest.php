<?php

declare(strict_types=1);

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Cuentas\Tables\CuentasTable;
use App\Models\AccesoExpediente;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\User;
use App\Support\FichaDeLaCuenta;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| La ficha de la cuenta y su bitácora (11-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «Todos los cargos no tiene sentido: en cada cuenta habrá
| historial de todo lo que se hace… que tenga un acceso a ver bitácora y
| mejorar el diseño». Se quitó «Todos los cargos», «Ver cuenta» pasó de
| cinco pestañas a una hoja, y cada fila de «Todas las cuentas» abre su
| bitácora al costado.
|
| Lo que dice la bitácora se prueba en `HistorialDeLaCuentaTest`. Acá: que
| la hoja diga lo que tiene que decir, que mirar una cuenta quede anotado
| y que los permisos de cargos no se pierdan con la pantalla que se fue.
|
| Los ayudantes de acá tienen nombre propio (§9.M5).
*/

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    /*
     * Los permisos de los Resources los crea Shield en el servidor, no un
     * seeder: sin crearlos antes, la matriz no los reparte.
     */
    foreach ([...MatrizDePermisosSeeder::permisosDeclarados(), 'View:Factura'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Un usuario del rol, parado en la sede de la cuenta.
 *
 * ⚠️ SIEMPRE con sede: la bitácora de lectura la exige y las cuentas se
 * filtran por la sede de quien mira.
 */
function miraLaFichaComo(string $rol, Cuenta $cuenta): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true, 'sede_id' => $cuenta->sede_id]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/**
 * Una cuenta con un seguro que cubre la mitad y un servicio de L 1,000.
 */
function unaCuentaParaLaFicha(string $rol = 'direccion'): Cuenta
{
    $cuenta = unaCuentaCon(unSeguroQueCubre('0.5000'));

    miraLaFichaComo($rol, $cuenta);

    conUnServicioDe($cuenta, '1000.0000');

    return $cuenta->fresh() ?? $cuenta;
}

/*
 * ⚠️ Sin `class_exists()`: el mapa de clases de Composer recuerda el
 * archivo borrado hasta el próximo `dump-autoload`, y `class_exists()` lo
 * intenta abrir y revienta. Lo que importa es que el panel ya no lo tenga.
 */
it('«Todos los cargos» ya no existe: los cargos se ven en cada cuenta', function (): void {
    $deCargos = array_filter(
        Filament::getDefaultPanel()->getResources(),
        fn (string $resource): bool => is_subclass_of($resource, Resource::class) && $resource::getModel() === Cargo::class,
    );

    expect($deCargos)->toBe([])
        ->and(file_exists(app_path('Filament/Resources/Cargos/CargoResource.php')))->toBeFalse();
})->note('Mauricio, 11-nov-2026: «no tiene sentido ya que en cada cuenta habrá historial de todo lo que se hace».');

it('🔴 los permisos de cargos siguen en la pantalla de roles y en una instalación nueva', function (): void {
    expect(MatrizDePermisosSeeder::permisosSinPantalla())->toBe(['ViewAny:Cargo', 'View:Cargo', 'Create:Cargo'])
        ->and(config('filament-shield.shield_resource.tabs.custom_permissions'))->toBeTrue();

    foreach (MatrizDePermisosSeeder::permisosSinPantalla() as $permiso) {
        expect(Permission::query()->where('name', $permiso)->exists())->toBeTrue("{$permiso} no se creó");
    }
})->note('Sin Resource, Shield deja de mostrarlos, y guardar un rol desde esa pantalla los borra en silencio (§9.L4): `Create:Cargo` es poder cargarle a una cuenta.');

it('la hoja dice de quién es, quién paga y las cinco cifras', function (): void {
    $cuenta = unaCuentaParaLaFicha();
    abonarle($cuenta, '300.00');

    $ficha = FichaDeLaCuenta::de($cuenta);
    $cifras = array_column($ficha['cifras'], 'valor', 'rotulo');

    expect($ficha['paciente'])->toBe($cuenta->encuentro->persona->nombreCompleto())
        ->and($ficha['pagador'])->toBe($cuenta->convenio->nombre)
        ->and($ficha['estadoEnPalabras'])->toBe('Abierta')
        ->and(array_keys($cifras))->toBe(['Total', 'Seguro paga', 'Le toca al paciente', 'Abonado', 'Falta'])
        ->and($cifras['Total'])->toBe($cuenta->saldo()->formateado())
        ->and($cifras['Seguro paga'])->toBe($cuenta->saldoDeLaAseguradora()->formateado())
        ->and($cifras['Le toca al paciente'])->toBe($cuenta->saldoDelPaciente()->formateado())
        ->and($cifras['Abonado'])->toBe(Monto::de('300.00')->formateado())
        ->and($cifras['Falta'])->toBe(Monto::de(Decimal::de($cuenta->total)->restar('300.00'))->formateado())
        ->and(array_column($ficha['datos'], 'valor', 'rotulo')['Cuenta'])->toBe($cuenta->numero);
});

it('la hoja no muestra renglones vacíos: sin póliza no hay renglón de póliza', function (): void {
    $cuenta = unaCuentaParaLaFicha();

    $rotulos = array_column(FichaDeLaCuenta::de($cuenta)['datos'], 'rotulo');

    expect($rotulos)->toContain('Cuenta', 'Encuentro', 'Ingreso')
        ->and($rotulos)->not->toContain('Póliza')
        ->and($rotulos)->not->toContain('Facturada')
        ->and($rotulos)->not->toContain('Anulada');
});

it('las facturas de la cuenta salen con su enlace para imprimir', function (): void {
    $cuenta = unaCuentaParaLaFicha();

    Factura::factory()->create([
        'cuenta_id' => $cuenta->id,
        'sede_id'   => $cuenta->sede_id,
        'numero'    => '000-003-01-00000012',
    ]);

    $facturas = FichaDeLaCuenta::de($cuenta)['facturas'];

    expect($facturas)->toHaveCount(1)
        ->and($facturas[0]['numero'])->toBe('000-003-01-00000012')
        ->and($facturas[0]['anulada'])->toBeFalse()
        ->and($facturas[0]['enlace'])->toContain('/imprimir');
});

it('lo que falta se dice igual en la lista y en la ficha', function (): void {
    $cero = Decimal::cero();
    $cien = Decimal::de('100.00');

    expect(FichaDeLaCuenta::loQueFalta(EstadoCuenta::Abierta, $cero, $cero))->toBe('Sin cargos')
        ->and(FichaDeLaCuenta::loQueFalta(EstadoCuenta::Cerrada, $cien, $cien))->toBe('Saldada')
        ->and(FichaDeLaCuenta::loQueFalta(EstadoCuenta::Abierta, $cien, Decimal::de('150.00')))
        ->toBe('A favor '.Monto::de('50.00')->formateado())
        ->and(FichaDeLaCuenta::loQueFalta(EstadoCuenta::Abierta, $cien, $cero))->toBe(Monto::de('100.00')->formateado())
        ->and(FichaDeLaCuenta::loQueFalta(EstadoCuenta::Anulada, $cien, $cero))->toBeNull()
        ->and(FichaDeLaCuenta::colorDeLoQueFalta(EstadoCuenta::Abierta, $cien, $cero))->toBe('warning')
        ->and(FichaDeLaCuenta::colorDeLoQueFalta(EstadoCuenta::Cerrada, $cien, $cien))->toBe('success')
        ->and(FichaDeLaCuenta::colorDeLoQueFalta(EstadoCuenta::Abierta, $cien, Decimal::de('150.00')))->toBe('info')
        ->and(FichaDeLaCuenta::colorDeLoQueFalta(EstadoCuenta::Anulada, $cien, $cero))->toBe('gray');
});

it('🔴 abrir la ficha queda en la bitácora de lectura, y la hoja trae la bitácora abierta', function (): void {
    $cuenta = unaCuentaParaLaFicha();
    $usuario = auth()->user();

    test()->get('/cuentas-de-pacientes/'.$cuenta->id)
        ->assertOk()
        ->assertSee('Cuenta '.$cuenta->numero)
        ->assertSee($cuenta->encuentro->persona->nombreCompleto())
        ->assertSee('Le toca al paciente')
        ->assertSee('Bitácora de la cuenta');

    $lectura = AccesoExpediente::query()
        ->where('recurso_tipo', Cuenta::class)
        ->where('recurso_id', $cuenta->id)
        ->first();

    expect($lectura)->not->toBeNull()
        ->and($lectura?->user_id)->toBe($usuario?->getAuthIdentifier())
        ->and($lectura?->paciente_id)->toBe($cuenta->encuentro->persona_id)
        ->and($lectura?->accion)->toBe(AccionDeLectura::Ver);
})->note('La cuenta dice qué medicamentos y estudios se le hicieron a una persona (§9.L6). Antes, abrirla no dejaba rastro.');

it('🔴 la bitácora al costado también queda anotada, y se pinta con lo que pasó', function (): void {
    $cuenta = unaCuentaParaLaFicha();

    CuentasTable::registrarQueSeAbrioLaBitacora($cuenta);

    $lectura = AccesoExpediente::query()
        ->where('recurso_tipo', Cuenta::class)
        ->where('recurso_id', $cuenta->id)
        ->first();

    expect($lectura?->motivo_texto)->toBe('BITÁCORA DE LA CUENTA');

    $html = view('filament.resources.cuentas.bitacora-al-costado', ['cuenta' => $cuenta])->render();

    expect($html)->toContain('Bitácora de la cuenta')
        ->and($html)->toContain('Agregó')
        ->and($html)->toContain('Ver la ficha completa')
        ->and($html)->toContain('/cuentas-de-pacientes/'.$cuenta->id);
});
