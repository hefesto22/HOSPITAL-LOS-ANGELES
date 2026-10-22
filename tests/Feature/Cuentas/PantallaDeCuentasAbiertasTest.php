<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoDiagnostico;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\TipoDiagnostico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Cie10;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Diagnostico;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use Database\Seeders\Cie10DeArranqueSeeder;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * LA PANTALLA DE CUENTAS ABIERTAS, ARRIBA Y EN CADA TARJETA
 * (Mauricio, 22-oct-2026).
 *
 *   · Debajo del título van los números —cuántas, cuánto sin facturar,
 *     cuántas vacías— y no una frase fija.
 *   · Si la grilla no las muestra todas, lo dice: la 25.ª cuenta
 *     desaparecía sin aviso.
 *   · Cada quien ve los botones que puede usar. «Ya que aún no se usará
 *     lo de diagnósticos ni tratamientos, no hay necesidad de que lo vean
 *     los cajeros».
 *   · La cuenta vacía no se factura, y el botón lo dice antes del modal.
 *
 * Se prueba con los métodos que lee la vista, como `TarjetaDeCuentaTest`:
 * ahí están las consultas y las reglas.
 *
 * ⚠️ Los ayudantes tienen nombres propios: con `--parallel`, dos
 * funciones globales con el mismo nombre chocan según cómo caiga el
 * reparto (§9.M5).
 */

const SUJETOS_DE_CUENTAS_ABIERTAS = ['Abono', 'Cargo', 'Cuenta', 'Encuentro', 'Factura', 'TurnoDeCaja'];

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (SUJETOS_DE_CUENTAS_ABIERTAS as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function quienMiraLasCuentas(string $rol, ?int $sedeId = null): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true, 'sede_id' => $sedeId]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/**
 * Una cuenta de contado en esa sede. El código CONTADO es único: se
 * reusa el convenio si ya existe.
 */
function cuentaEnLaSede(Sede $sede): Cuenta
{
    $persona = Persona::factory()->create(['fecha_nacimiento' => now()->subYears(40)->toDateString()]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: Expediente::factory()->create(['sede_id' => $sede->id, 'persona_id' => $persona->id]),
        tipo: TipoEncuentro::Emergencia,
        convenio: Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
            ?? Convenio::factory()->contado()->create(),
        sede: $sede,
    );
}

function unDiagnosticoEn(Cuenta $cuenta, int $cie10, User $medico): void
{
    Diagnostico::query()->create([
        'encuentro_id'      => $cuenta->encuentro_id,
        'cie10_id'          => $cie10,
        'tipo'              => TipoDiagnostico::Secundario->value,
        'momento'           => MomentoDiagnostico::Ingreso->value,
        'estado'            => EstadoDiagnostico::Vigente->value,
        'confirmado'        => false,
        'diagnosticado_por' => $medico->id,
        'diagnosticado_en'  => now(),
    ]);
}

/**
 * Cuántas consultas del registro fueron a esa tabla.
 */
function consultasA(string $tabla): int
{
    return count(array_filter(
        DB::getQueryLog(),
        static fn (mixed $consulta): bool => str_contains((string) data_get($consulta, 'query'), '"'.$tabla.'"'),
    ));
}

/*
|--------------------------------------------------------------------------
| El encabezado
|--------------------------------------------------------------------------
*/

it('el encabezado dice cuantas hay, cuanto llevan sin facturar y cuantas estan vacias', function (): void {
    $sede = Sede::factory()->create();
    $llena = cuentaEnLaSede($sede);
    cuentaEnLaSede($sede);

    conUnServicioDe($llena, '250.0000');

    $pagina = app(CuentasAbiertas::class);

    expect($pagina->numerosDeLaPantalla())->toBe(['abiertas' => 2, 'cargado' => '250.00', 'vacias' => 1])
        ->and($pagina->getSubheading())->toBe('2 abiertas · '.Monto::de('250.00')->formateado().' sin facturar · 1 vacía');
})->note('Las vacías van aparte porque casi siempre son una cuenta abierta por error o una olvidada: se ven desde arriba, sin recorrer tarjetas.');

it('sin cuentas el encabezado no dice nada', function (): void {
    expect(app(CuentasAbiertas::class)->getSubheading())->toBeNull();
})->note('El recuadro vacío de abajo ya lo explica; repetirlo arriba es ruido.');

/*
|--------------------------------------------------------------------------
| Las que no entran en la grilla
|--------------------------------------------------------------------------
*/

it('🔴 si la grilla no las muestra todas, la pantalla sabe cuantas son', function (): void {
    config()->set('sihla.facturacion.tarjetas_por_pantalla', 2);

    $sede = Sede::factory()->create();
    cuentaEnLaSede($sede);
    cuentaEnLaSede($sede);
    cuentaEnLaSede($sede);

    $pagina = app(CuentasAbiertas::class);
    $cuentas = $pagina->cuentas();

    expect($cuentas)->toHaveCount(2)
        ->and($pagina->cuantasCoinciden($cuentas->count()))->toBe(3);
})->note('Antes la tercera desaparecía sin aviso: un paciente internado cuya cuenta no estaba en la pantalla de cuentas abiertas. La vista avisa «se muestran las 2 más recientes de 3».');

it('si entran todas, no le pregunta nada mas a la base', function (): void {
    config()->set('sihla.facturacion.tarjetas_por_pantalla', 5);

    $pagina = app(CuentasAbiertas::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $coinciden = $pagina->cuantasCoinciden(2);

    expect($coinciden)->toBe(2)
        ->and(DB::getQueryLog())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Los botones de la tarjeta
|--------------------------------------------------------------------------
*/

it('🔴 cada quien ve los botones que puede usar', function (string $rol, array $esperados): void {
    quienMiraLasCuentas($rol);

    expect(app(CuentasAbiertas::class)->botonesDeLaTarjeta())->toBe($esperados);
})->with([
    'caja'             => ['caja', ['abonar' => true, 'facturar' => true, 'clinicos' => false]],
    'direccion'        => ['direccion', ['abonar' => true, 'facturar' => true, 'clinicos' => false]],
    'laboratorio'      => ['laboratorio', ['abonar' => false, 'facturar' => false, 'clinicos' => false]],
    'consulta externa' => ['consulta_externa', ['abonar' => false, 'facturar' => false, 'clinicos' => false]],
])->note('Mauricio: «ya que aún no se usará lo de diagnósticos ni tratamientos, no hay necesidad de que lo vean los cajeros». Sin el permiso el botón no está; con el permiso y sin turno, está apagado y dice por qué.');

it('el medico ve diagnostico y tratamiento', function (): void {
    quienMiraLasCuentas('medico');

    expect(app(CuentasAbiertas::class)->botonesDeLaTarjeta()['clinicos'])->toBeTrue();
});

it('🔴 la cajera sin turno sigue viendo facturar, apagado y con el porque', function (): void {
    $sede = Sede::factory()->create();
    quienMiraLasCuentas('caja', $sede->id);

    $pagina = app(CuentasAbiertas::class);

    expect($pagina->botonesDeLaTarjeta()['facturar'])->toBeTrue()
        ->and($pagina->porQueNoFactura())->toBe('Abrí el turno de caja para facturar.');
})->note('El permiso decide si el botón está; el turno, si se puede apretar. Sin turno no se esconde: la cajera tiene que saber que le falta abrirlo.');

it('🔴 la cuenta vacia no se factura, y el boton lo dice antes de abrir el modal', function (): void {
    $sede = Sede::factory()->create();
    $vacia = cuentaEnLaSede($sede);
    $llena = cuentaEnLaSede($sede);

    conUnServicioDe($llena, '80.0000');

    $pagina = app(CuentasAbiertas::class);

    expect($pagina->porQueNoFacturaLa($vacia->refresh(), null))->toContain('nada cargado')
        ->and($pagina->porQueNoFacturaLa($llena->refresh(), null))->toBeNull()
        ->and($pagina->porQueNoFacturaLa($llena, 'Abrí el turno de caja para facturar.'))
        ->toBe('Abrí el turno de caja para facturar.');
})->note('El botón encendido solo llevaba a «no tiene renglones cobrables» con el modal ya abierto. Lo de quien mira —el turno— pesa primero.');

/*
|--------------------------------------------------------------------------
| El contador de diagnósticos
|--------------------------------------------------------------------------
*/

it('🔴 los diagnosticos de toda la grilla salen en una sola consulta', function (): void {
    test()->seed(Cie10DeArranqueSeeder::class);

    $sede = Sede::factory()->create();
    $medico = quienMiraLasCuentas('medico', $sede->id);

    $una = cuentaEnLaSede($sede);
    $otra = cuentaEnLaSede($sede);
    $sin = cuentaEnLaSede($sede);

    $codigos = Cie10::query()->orderBy('id')->limit(2)->pluck('id')->all();

    unDiagnosticoEn($una, (int) $codigos[0], $medico);
    unDiagnosticoEn($una, (int) $codigos[1], $medico);
    unDiagnosticoEn($otra, (int) $codigos[0], $medico);

    $pagina = app(CuentasAbiertas::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $cuantos = $pagina->diagnosticosPorEncuentro(new Collection([$una, $otra, $sin]));

    expect($cuantos)->toHaveCount(2)
        ->and($cuantos[$una->encuentro_id] ?? null)->toBe(2)
        ->and($cuantos[$otra->encuentro_id] ?? null)->toBe(1)
        ->and(consultasA('diagnosticos'))->toBe(1);
})->note('Antes era un count() por tarjeta y la vista lo llamaba dos veces: 48 consultas de más con 24 cuentas en pantalla (§13.2).');

it('a quien no ve el boton, el contador no le cuesta ninguna consulta', function (): void {
    $sede = Sede::factory()->create();
    quienMiraLasCuentas('caja', $sede->id);

    $cuenta = cuentaEnLaSede($sede);
    $pagina = app(CuentasAbiertas::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($pagina->diagnosticosPorEncuentro(new Collection([$cuenta])))->toBe([])
        ->and(consultasA('diagnosticos'))->toBe(0);
});
