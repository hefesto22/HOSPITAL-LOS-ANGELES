<?php

declare(strict_types=1);

use App\Domain\Enums\MotivoDeAnulacionDeCuenta;
use App\Domain\Enums\TipoEncuentro;
use App\Filament\Widgets\AccesosDelEscritorio;
use App\Filament\Widgets\CajaDelEscritorio;
use App\Filament\Widgets\InventarioDelEscritorio;
use App\Filament\Widgets\LoQueCargueHoy;
use App\Filament\Widgets\ResumenDelDia;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCuenta;
use App\Support\EscritorioDelRol;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * EL ESCRITORIO SEGÚN QUIÉN ENTRA (Mauricio, 21-oct-2026).
 *
 * Caja ve su turno, laboratorio y consulta externa lo que cargaron,
 * farmacia y bodega lo que se vence, dirección el día entero. Qué sección
 * ve cada rol está en `sihla.escritorio.secciones_por_rol`, y cada cuadro
 * vuelve a pedir su permiso.
 *
 * Los cuadros se prueban llamando a `getStats()`: es donde están las
 * consultas, y una consulta mal escrita revienta acá y no en el celular
 * de la cajera.
 */

const SUJETOS_DEL_ESCRITORIO = [
    'Abono', 'Almacen', 'Cargo', 'Cuenta', 'Encuentro', 'Factura', 'FusionDePersona', 'Item',
    'Persona', 'Prestamo', 'Presupuesto', 'Producto', 'RangoCai', 'Recepcion', 'TurnoDeCaja',
];

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (SUJETOS_DEL_ESCRITORIO as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function entraAlEscritorio(string $rol, ?int $sedeId = null): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create([
        'is_active' => true,
        'name'      => 'ANA '.mb_strtoupper(str_replace('_', ' ', $rol)),
        'sede_id'   => $sedeId,
    ]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/**
 * @param class-string $widget
 *
 * @return list<Stat>
 */
function statsDelCuadro(string $widget): array
{
    $cuadro = new $widget;

    /** @var list<Stat> $stats */
    $stats = (fn (): array => $this->getStats())->call($cuadro);

    return $stats;
}

/**
 * @param list<Stat> $stats
 */
function elStat(array $stats, string $etiqueta): ?Stat
{
    foreach ($stats as $stat) {
        if ($stat->getLabel() === $etiqueta) {
            return $stat;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Qué cuadros ve cada rol
|--------------------------------------------------------------------------
*/

it('🔴 cada rol ve sus cuadros y no los de otro', function (string $rol, array $esperados): void {
    entraAlEscritorio($rol);

    $todos = [
        AccesosDelEscritorio::class, ResumenDelDia::class, CajaDelEscritorio::class,
        LoQueCargueHoy::class, InventarioDelEscritorio::class,
    ];

    foreach ($todos as $cuadro) {
        expect($cuadro::canView())->toBe(in_array($cuadro, $esperados, true), "{$rol} y {$cuadro}");
    }
})->with([
    'caja'             => ['caja', [AccesosDelEscritorio::class, CajaDelEscritorio::class]],
    'laboratorio'      => ['laboratorio', [AccesosDelEscritorio::class, LoQueCargueHoy::class]],
    'consulta externa' => ['consulta_externa', [AccesosDelEscritorio::class, LoQueCargueHoy::class]],
    'farmacia'         => ['farmacia', [AccesosDelEscritorio::class, LoQueCargueHoy::class, InventarioDelEscritorio::class]],
    'bodega'           => ['bodega', [AccesosDelEscritorio::class, InventarioDelEscritorio::class]],
    'direccion'        => ['direccion', [AccesosDelEscritorio::class, ResumenDelDia::class, CajaDelEscritorio::class, InventarioDelEscritorio::class]],
    'auditoria'        => ['auditoria', [AccesosDelEscritorio::class, ResumenDelDia::class]],
    'admision'         => ['admision', [AccesosDelEscritorio::class]],
]);

it('la configuracion decide la seccion y el super admin las ve todas', function (): void {
    $caja = entraAlEscritorio('caja');

    expect(EscritorioDelRol::muestra(EscritorioDelRol::CAJA, $caja))->toBeTrue()
        ->and(EscritorioDelRol::muestra(EscritorioDelRol::RESUMEN_DEL_DIA, $caja))->toBeFalse();

    config()->set('sihla.escritorio.secciones_por_rol.caja', [EscritorioDelRol::CAJA, EscritorioDelRol::RESUMEN_DEL_DIA]);

    expect(EscritorioDelRol::muestra(EscritorioDelRol::RESUMEN_DEL_DIA, $caja))->toBeTrue();

    $soporte = entraAlEscritorio('super_admin');

    foreach ([EscritorioDelRol::RESUMEN_DEL_DIA, EscritorioDelRol::CAJA, EscritorioDelRol::LO_QUE_CARGUE, EscritorioDelRol::INVENTARIO] as $seccion) {
        expect(EscritorioDelRol::muestra($seccion, $soporte))->toBeTrue();
    }
})->note('Otra clínica arma su escritorio con una línea de config, sin tocar código (§1.1).');

it('🔴 la configuracion no le abre a nadie lo que su permiso le niega', function (): void {
    config()->set('sihla.escritorio.secciones_por_rol.laboratorio', [EscritorioDelRol::RESUMEN_DEL_DIA, EscritorioDelRol::CAJA]);

    entraAlEscritorio('laboratorio');

    expect(ResumenDelDia::canView())->toBeFalse()
        ->and(CajaDelEscritorio::canView())->toBeFalse();
})->note('Laboratorio no tiene `ViewAny:Factura` ni `ViewAny:TurnoDeCaja`: la línea de config le muestra la sección, el permiso la apaga. Lo que se ve lo decide la matriz, no el escritorio.');

/*
|--------------------------------------------------------------------------
| Los botones grandes
|--------------------------------------------------------------------------
*/

it('🔴 laboratorio tiene abrir cuenta primero, y no los botones de caja ni de farmacia', function (): void {
    entraAlEscritorio('laboratorio');

    $accesos = (new AccesosDelEscritorio)->accesos();
    $etiquetas = array_column($accesos, 'etiqueta');

    expect($etiquetas)->toBe(['Abrir cuenta', 'Cuentas abiertas', 'Pacientes'])
        ->and($accesos[0]['principal'])->toBeTrue()
        ->and($accesos[0]['url'])->toContain('action=abrirCuenta');
})->note('Los botones salen del mismo `canAccess()` que decide el menú: si laboratorio no ve Caja en el menú, tampoco tiene el botón.');

it('caja tiene su caja y sus facturas', function (): void {
    entraAlEscritorio('caja');

    $etiquetas = array_column((new AccesosDelEscritorio)->accesos(), 'etiqueta');

    expect($etiquetas)->toContain('Abrir cuenta', 'Cuentas abiertas', 'Pacientes', 'Caja', 'Facturas', 'Productos');
    expect($etiquetas)->not->toContain('Existencias', 'Recibir mercadería');
});

it('el saludo usa el primer nombre y el rol', function (): void {
    entraAlEscritorio('consulta_externa');

    $accesos = new AccesosDelEscritorio;

    expect($accesos->nombre())->toBe('Ana')
        ->and($accesos->roles())->toBe('Consulta externa');
});

/*
|--------------------------------------------------------------------------
| Los números
|--------------------------------------------------------------------------
*/

it('🔴 los cuadros de direccion leen sin romperse', function (): void {
    $sede = Sede::factory()->create();
    entraAlEscritorio('direccion');

    $persona = Persona::factory()->create(['fecha_nacimiento' => now()->subYears(40)->toDateString()]);
    $cuenta = app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: Expediente::factory()->create(['sede_id' => $sede->id, 'persona_id' => $persona->id]),
        tipo: TipoEncuentro::Emergencia,
        convenio: Convenio::factory()->contado()->create(),
        sede: $sede,
    );

    conUnServicioDe($cuenta, '250.0000');
    abonarle($cuenta, '100.00');

    $resumen = statsDelCuadro(ResumenDelDia::class);
    $caja = statsDelCuadro(CajaDelEscritorio::class);
    $inventario = statsDelCuadro(InventarioDelEscritorio::class);

    expect(elStat($resumen, 'Cuentas abiertas')?->getValue())->toBe('1')
        ->and(elStat($resumen, 'Cobrado hoy')?->getValue())->toContain('100.00')
        ->and(elStat($caja, 'Por cobrar a pacientes')?->getValue())->toContain('150.00')
        ->and(elStat($inventario, 'Vencidos en el estante')?->getValue())->toBe('0');
})->note('Por cobrar es la parte del paciente menos lo abonado: L 250 cargados, L 100 abonados, quedan L 150.');

it('🔴 dirección ve las cuentas anuladas del día, cuánto y quién', function (): void {
    $sede = Sede::factory()->create();
    $direccion = entraAlEscritorio('direccion');

    $persona = Persona::factory()->create(['fecha_nacimiento' => now()->subYears(40)->toDateString()]);
    $cuenta = app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: Expediente::factory()->create(['sede_id' => $sede->id, 'persona_id' => $persona->id]),
        tipo: TipoEncuentro::Emergencia,
        convenio: Convenio::factory()->contado()->create(),
        sede: $sede,
    );

    conUnServicioDe($cuenta, '320.0000');

    app(AnuladorDeCuenta::class)->anular(
        $cuenta,
        MotivoDeAnulacionDeCuenta::PacienteEquivocado,
        'ERA LA CUENTA DEL HERMANO, QUE SE LLAMA IGUAL',
    );

    $anuladas = elStat(statsDelCuadro(ResumenDelDia::class), 'Cuentas anuladas hoy');

    expect($anuladas?->getValue())->toBe('1')
        ->and($anuladas?->getDescription())->toContain('320.00')
        ->and($anuladas?->getDescription())->toContain($direccion->name);
})->note('Anular una cuenta con renglones también es cómo se esconde un cobro sin recibo. No se le traba la corrección a nadie, pero dirección la ve cada día.');

it('🔴 laboratorio ve lo que cargó hoy, en renglones y no en lempiras', function (): void {
    $cuenta = Cuenta::factory()->create();
    $laboratorista = entraAlEscritorio('laboratorio', $cuenta->sede_id);

    Cargo::factory()->enLaCuenta($cuenta)->create(['created_by' => $laboratorista->id]);
    Cargo::factory()->enLaCuenta($cuenta)->create(['created_by' => $laboratorista->id]);
    Cargo::factory()->enLaCuenta($cuenta)->create();

    $stats = statsDelCuadro(LoQueCargueHoy::class);

    expect(elStat($stats, 'Hoy cargaste')?->getValue())->toBe('2 renglones')
        ->and(elStat($stats, 'Hoy cargaste')?->getDescription())->toBe('A 1 paciente');
})->note('§1.4: laboratorio no ve costos. Lo que le sirve es saber si se le pasó alguno.');
