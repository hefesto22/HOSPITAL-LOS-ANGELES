<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CajaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Sede;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Services\ReceptorDeAbono;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL TURNO ES DE LA GAVETA, NO DE QUIEN LO ABRIÓ (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si un administrador abre el turno A, el usuario que tenga
 * turno A puede cerrarlo, y viceversa; el admin puede abrir el turno A,
 * cerrarlo y luego abrir el turno B; en automático debe saber cuál es el
 * turno que sigue».
 *
 * Lo que este archivo cuida:
 *
 *   · la administración abre y la cajera del turno cobra y cierra, y al
 *     revés;
 *   · la cajera de OTRO turno no cobra ni cierra en el que está abierto;
 *   · con un turno abierto no se abre otro: la gaveta es una sola;
 *   · el nombre del turno viene solo, el que sigue, y da la vuelta.
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Una cuenta viva con L 2,000 de deuda, y la sede de su gaveta.
 *
 * @return array{cuenta: Cuenta, sede: Sede}
 */
function gavetaDeLaPrueba(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);
    conUnServicioDe($cuenta, '2000.0000');

    return ['cuenta' => $cuenta->refresh(), 'sede' => Sede::query()->findOrFail($cuenta->sede_id)];
}

function alguienDeCaja(string $rol, ?string $turno, Sede $sede): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create([
        'is_active' => true,
        'turno'     => $turno,
        'sede_id'   => $sede->id,
    ]);
    $usuario->assignRole($rol);

    return $usuario->fresh() ?? $usuario;
}

/**
 * La matriz de roles de verdad, para las pruebas que preguntan a las
 * policies y no solo al servicio.
 */
function permisosDeCajaParaLaPrueba(): void
{
    foreach (['Factura', 'Abono', 'TurnoDeCaja', 'Cargo', 'Cuenta'] as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function abridorDeLaGaveta(): AbridorDeTurnoDeCaja
{
    return app(AbridorDeTurnoDeCaja::class);
}

function efectivoDeLaGaveta(string $monto): MedioDePago
{
    return new MedioDePago(FormaDePago::Efectivo, Decimal::de($monto));
}

it('🔴 la administración abre el turno A y la cajera del turno A cobra en él y lo cierra', function (): void {
    ['cuenta' => $cuenta, 'sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $rosa = alguienDeCaja('caja', 'Turno A', $sede);

    $turno = abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [efectivoDeLaGaveta('50.00')],
        quienRecibe: $rosa,
    );

    $cerrado = abridorDeLaGaveta()->cerrar($turno, Decimal::de('350.00'), null, $rosa->id);

    expect($turno->nombre)->toBe('TURNO A')
        ->and($recibo->turno_id)->toBe($turno->id)
        ->and($cerrado->estaAbierto())->toBeFalse()
        ->and($cerrado->usuario_id)->toBe($administracion->id)
        ->and($cerrado->cerrado_por)->toBe($rosa->id)
        ->and($cerrado->diferencia)->toBe('0.00');
})->note('Quién abrió y quién contó quedan escritos por separado: el arqueo responsabiliza a quien contó.');

it('y al revés: la cajera abre su turno y la administración lo cierra', function (): void {
    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $rosa = alguienDeCaja('caja', 'Turno A', $sede);

    $turno = abridorDeLaGaveta()->abrir($rosa, $sede, Decimal::de('300.00'));
    $cerrado = abridorDeLaGaveta()->cerrar($turno, Decimal::de('300.00'), null, $administracion->id);

    expect($cerrado->estaAbierto())->toBeFalse()
        ->and($cerrado->cerrado_por)->toBe($administracion->id);
});

it('🔴 la cajera de otro turno no cobra en el turno abierto', function (): void {
    ['cuenta' => $cuenta, 'sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $juan = alguienDeCaja('caja', 'Turno B', $sede);

    abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    expect(fn () => app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [efectivoDeLaGaveta('50.00')],
        quienRecibe: $juan,
    ))->toThrow(CajaException::class, 'La gaveta está en el TURNO A');
})->note('La plata caería en un arqueo que cuenta otra persona.');

it('🔴 la cajera de otro turno no cierra el turno abierto', function (): void {
    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $juan = alguienDeCaja('caja', 'Turno B', $sede);

    $turno = abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    expect(fn () => abridorDeLaGaveta()->cerrar($turno, Decimal::de('300.00'), null, $juan->id))
        ->toThrow(CajaException::class, 'Tu turno es el TURNO B');

    expect($turno->refresh()->estaAbierto())->toBeTrue();
});

it('🔴 con un turno abierto no se abre otro, ni siquiera la administración', function (): void {
    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $rosa = alguienDeCaja('caja', 'Turno A', $sede);
    $juan = alguienDeCaja('caja', 'Turno B', $sede);

    abridorDeLaGaveta()->abrir($rosa, $sede, Decimal::de('300.00'));

    expect(fn () => abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('0')))
        ->toThrow(CajaException::class, 'El TURNO A sigue abierto');

    expect(fn () => abridorDeLaGaveta()->abrir($juan, $sede, Decimal::de('0')))
        ->toThrow(CajaException::class, 'abrió el TURNO A');
})->note('A quien puede cerrarlo se le dice que lo cierre; a los demás, quién lo cierra.');

it('🔴 el turno que sigue viene solo, y después del último vuelve al primero', function (): void {
    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);

    $nombres = [];

    foreach (range(1, 4) as $vuelta) {
        $turno = abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('0'));
        $nombres[] = $turno->nombre;
        abridorDeLaGaveta()->cerrar($turno, Decimal::de('0'), null, $administracion->id);
    }

    expect($nombres)->toBe(['TURNO A', 'TURNO B', 'TURNO C', 'TURNO A']);
});

it('se puede saltar un turno eligiéndolo, y el que sigue cuenta desde ese', function (): void {
    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);

    $turno = abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('0'), 'Turno C');
    abridorDeLaGaveta()->cerrar($turno, Decimal::de('0'), null, $administracion->id);

    expect($turno->nombre)->toBe('TURNO C')
        ->and(abridorDeLaGaveta()->siguienteEn($sede))->toBe('TURNO A');
});

it('quien no tiene turno asignado trabaja en el que abrió', function (): void {
    ['cuenta' => $cuenta, 'sede' => $sede] = gavetaDeLaPrueba();
    $ana = alguienDeCaja('caja', null, $sede);

    $turno = abridorDeLaGaveta()->abrir($ana, $sede, Decimal::de('100.00'));

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [efectivoDeLaGaveta('25.00')],
        quienRecibe: $ana,
    );

    expect($recibo->turno_id)->toBe($turno->id);
});

it('🔴 la cajera del turno factura con el turno que le abrió la administración; la de otro turno no', function (): void {
    permisosDeCajaParaLaPrueba();

    ['sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $rosa = alguienDeCaja('caja', 'Turno A', $sede);
    $juan = alguienDeCaja('caja', 'Turno B', $sede);

    abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    test()->actingAs($rosa);
    $rosaFactura = Gate::allows('create', Factura::class);

    test()->actingAs($juan);
    $juanFactura = Gate::allows('create', Factura::class);

    expect($rosaFactura)->toBeTrue()
        ->and($juanFactura)->toBeFalse();
});

it('🔴 la cajera de otro turno, o sin turno asignado, carga en la cuenta pero no abona ni factura', function (string $turnoDeElla, string $porQue): void {
    permisosDeCajaParaLaPrueba();

    ['cuenta' => $cuenta, 'sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $ella = alguienDeCaja('caja', $turnoDeElla === '' ? null : $turnoDeElla, $sede);

    abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    test()->actingAs($ella);

    $antes = $cuenta->cargos()->count();
    conUnServicioDe($cuenta, '150.0000');

    expect(Gate::allows('create', Cargo::class))->toBeTrue()
        ->and($cuenta->cargos()->count())->toBe($antes + 1)
        ->and(Gate::allows('create', Factura::class))->toBeFalse();

    expect(fn () => app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [efectivoDeLaGaveta('50.00')],
        quienRecibe: $ella,
    ))->toThrow(CajaException::class, $porQue);
})->with([
    'de otro turno' => ['Turno B', 'Tu turno es el TURNO B'],
    'sin turno'     => ['', 'No tenés un turno asignado'],
])->note('Mauricio: «puede agregar cosas a la cuenta del paciente, mas no puede abonar ni facturar ni cerrar el turno que no es de ella».');

it('🔴 la cajera de otro turno no anula un recibo del turno abierto; la del turno sí', function (): void {
    ['cuenta' => $cuenta, 'sede' => $sede] = gavetaDeLaPrueba();
    $administracion = alguienDeCaja('direccion', null, $sede);
    $rosa = alguienDeCaja('caja', 'Turno A', $sede);
    $juan = alguienDeCaja('caja', 'Turno B', $sede);

    abridorDeLaGaveta()->abrir($administracion, $sede, Decimal::de('300.00'));

    $recibo = app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [efectivoDeLaGaveta('50.00')],
        quienRecibe: $rosa,
    );

    expect(fn () => app(ReceptorDeAbono::class)->anular($recibo, 'Se recibió dos veces por error', $juan->id))
        ->toThrow(CajaException::class, 'Tu turno es el TURNO B');

    $anulado = app(ReceptorDeAbono::class)->anular($recibo->refresh(), 'Se recibió dos veces por error', $rosa->id);

    expect($anulado->anulado_por)->toBe($rosa->id);
})->note('Anular saca plata del arqueo de ese turno: lo hace quien lo va a contar.');
