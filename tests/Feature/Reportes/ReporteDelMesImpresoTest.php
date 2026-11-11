<?php

declare(strict_types=1);

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Pages\ReporteDelMes;
use App\Models\AccesoExpediente;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Sede;
use App\Models\User;
use App\Services\ResumenDeFacturacion;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El reporte del mes, en papel (11-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «debe tener mejor diseño y sobre todo que se pueda imprimir».
|
| Los números del papel salen de `ResumenDeFacturacion`, igual que la
| pantalla, y esos ya se prueban al centavo en `ResumenDeFacturacionTest`.
| Acá se prueba que el papel no lo abra cualquiera, que filtre por la sede
| como la pantalla, que cada impresión quede anotada y que la barra de a
| dónde se fueron los ingresos sume bien.
|
| ⚠️ Los ayudantes tienen nombres propios: con `--parallel` dos iguales en
| archivos distintos chocan, y uno de otro archivo no existe acá (§9.M5).
*/

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);
    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Un usuario del rol parado en esa sede: el reporte filtra por la sede de
 * quien lo pide.
 *
 * ⚠️ SIEMPRE con sede. La bitácora de lectura la exige
 * (`accesos_expediente.sede_id` NOT NULL): sin sede el insert falla, la
 * bitácora se lo traga, pero PostgreSQL ya abortó la transacción del test
 * y todo lo que sigue revienta con 25P02.
 */
function imprimeElReporteDelMesComo(string $rol, ?int $sedeId = null): User
{
    $sedeId ??= Sede::query()->orderBy('id')->value('id') ?? Sede::factory()->create()->id;

    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true, 'sede_id' => $sedeId]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/**
 * Una factura emitida hoy, de ese total, con su número.
 *
 * Todas con el MISMO convenio CONTADO: sin esto cada factura armaba su
 * cuenta con `Convenio::factory()->contado()`, y la segunda chocaba con el
 * único de `convenios.codigo`.
 *
 * @param numeric-string $total
 */
function facturaDelReporteImpreso(int $correlativo, string $total, string $cliente = 'CONSUMIDOR FINAL'): Factura
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    return Factura::factory()->create([
        'cuenta_id'      => Cuenta::factory()->conPagador($contado)->create()->id,
        'correlativo'    => $correlativo,
        'numero'         => sprintf('000-001-01-%08d', $correlativo),
        'cliente_nombre' => $cliente,
        'bruto'          => $total,
        'exento'         => $total,
        'total'          => $total,
    ]);
}

it('🔴 solo dirección y auditoría imprimen el reporte del mes', function (): void {
    foreach (array_keys(RoleSeeder::ROLES) as $rol) {
        imprimeElReporteDelMesComo($rol);

        $esperado = in_array($rol, ['direccion', 'auditoria'], true) ? 200 : 403;

        test()->get(route('reportes.mes'))->assertStatus($esperado);
        test()->get(route('reportes.mes', ['facturas' => 1]))->assertStatus($esperado);
    }
})->note('El papel dice lo mismo que la pantalla, que es solo de dirección y auditoría: una ruta de impresión sin candado es ese reporte servido por URL.');

it('sin sesión, el papel no se abre ni queda anotado', function (): void {
    expect(test()->get(route('reportes.mes'))->isOk())->toBeFalse()
        ->and(AccesoExpediente::query()->count())->toBe(0);
});

it('el papel lleva las cifras, y las facturas una por una solo si se piden', function (): void {
    $factura = facturaDelReporteImpreso(42, '1000.00', 'MARIA JOSE HERNANDEZ');

    imprimeElReporteDelMesComo('direccion', $factura->sede_id);

    test()->get(route('reportes.mes'))
        ->assertOk()
        ->assertSee('Facturación del período')
        ->assertSee('L. 1,000.00')
        ->assertSeeInOrder(['Lo facturado', 'Lo que entró y lo que falta', 'Lo que queda', 'De lo que ya entró', 'A crédito de cada seguro'])
        ->assertDontSee('000-001-01-00000042')
        ->assertDontSee('MARIA JOSE HERNANDEZ');

    test()->get(route('reportes.mes', ['facturas' => 1]))
        ->assertOk()
        ->assertSee('Facturas del período')
        ->assertSee('000-001-01-00000042')
        ->assertSee('MARIA JOSE HERNANDEZ');
});

it('🔴 quien tiene sede imprime solo lo de su sede, como en la pantalla', function (): void {
    $suya = facturaDelReporteImpreso(1, '1000.00');
    $deOtra = facturaDelReporteImpreso(2, '700.00', 'CLIENTE DE OTRA SEDE');

    expect($deOtra->sede_id)->not->toBe($suya->sede_id);

    imprimeElReporteDelMesComo('auditoria', $suya->sede_id);

    test()->get(route('reportes.mes', ['facturas' => 1]))
        ->assertOk()
        ->assertSee('L. 1,000.00')
        ->assertDontSee('L. 700.00')
        ->assertDontSee('CLIENTE DE OTRA SEDE');
})->note('ADR-0002: el alcance por sede vale también en el papel (§9.L5). La pantalla y el papel usan `ResumenDeFacturacion::sedeDe()`.');

it('🔴 cada impresión queda en la bitácora de lectura, con el período', function (): void {
    $usuario = imprimeElReporteDelMesComo('direccion');

    test()->get(route('reportes.mes'))->assertOk();
    test()->get(route('reportes.mes', ['facturas' => 1]))->assertOk();

    $impresiones = AccesoExpediente::query()
        ->where('user_id', $usuario->id)
        ->where('accion', AccionDeLectura::Imprimir->value)
        ->where('recurso_tipo', Factura::class)
        ->orderBy('id')
        ->get();

    expect($impresiones)->toHaveCount(2)
        ->and($impresiones[0]->motivo_texto)->toStartWith('REPORTE DEL MES · ')
        ->and($impresiones[0]->motivo_texto)->not->toContain('CON LAS FACTURAS')
        ->and($impresiones[1]->motivo_texto)->toEndWith('CON LAS FACTURAS');
})->note('Con las facturas, el papel lleva nombres de pacientes (§9.E8, §9.L12).');

it('un período de más de un año no se imprime', function (): void {
    imprimeElReporteDelMesComo('direccion');

    test()->get(route('reportes.mes', ['desde' => '2025-01-01', 'hasta' => '2026-06-30']))->assertStatus(422);
    test()->get(route('reportes.mes', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']))->assertOk();
});

it('incrustado en el modal, el papel sale sin su barra de botones', function (): void {
    imprimeElReporteDelMesComo('direccion');

    test()->get(route('reportes.mes'))->assertOk()->assertSee('Volver a la pantalla');
    test()->get(route('reportes.mes', ['incrustada' => 1]))->assertOk()->assertDontSee('Volver a la pantalla');
});

it('el enlace de la pantalla lleva el período que se está viendo, y el modal ofrece «Con las facturas»', function (): void {
    $pantalla = app(ReporteDelMes::class);
    $pantalla->desde = '2026-10-01';
    $pantalla->hasta = '2026-10-31';

    $resumen = $pantalla->enlaceDelReporte(incrustada: true);
    $conFacturas = $pantalla->enlaceDelReporte(incrustada: true, conFacturas: true);

    expect($pantalla->enlaceDelReporte())->toBe(route('reportes.mes', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
        ->and($resumen)->toBe(route('reportes.mes', ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'incrustada' => 1]))
        ->and($conFacturas)->toBe(route('reportes.mes', ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'facturas' => 1, 'incrustada' => 1]))
        ->and($pantalla->sePuedeImprimir())->toBeTrue();

    $html = view('honorarios.modal', [
        'enlace'             => $resumen,
        'enlaceConDetalle'   => $conFacturas,
        'etiquetaConDetalle' => 'Con las facturas',
        'titulo'             => 'Reporte del mes · Octubre 2026',
    ])->render();

    expect($html)->toContain('Con las facturas')
        ->and($html)->not->toContain('Con la hoja de cada uno');
});

it('🔴 la barra de a dónde se fueron los ingresos suma el todo, al décimo', function (): void {
    $reparto = ResumenDeFacturacion::repartoDeLosIngresos([
        'ingresos'              => '2250.00',
        'medicamentos'          => ['costo' => '455.00'],
        'honorarios'            => ['total' => '1300.00'],
        'despues_de_honorarios' => '495.00',
    ]);

    expect(array_column($reparto, 'clave'))->toBe(['medicamentos', 'honorarios', 'queda'])
        ->and(array_column($reparto, 'monto'))->toBe(['455.00', '1300.00', '495.00'])
        ->and(array_column($reparto, 'parte'))->toBe(['20.2', '57.8', '22.0']);
})->note('Los mismos números del golden test del resultado: 455 + 1,300 + 495 = 2,250 de ingresos.');

it('sin ingresos, o si lo que queda es negativo, no hay barra', function (): void {
    $sinIngresos = ResumenDeFacturacion::repartoDeLosIngresos([
        'ingresos'              => '0.00',
        'medicamentos'          => ['costo' => '0.00'],
        'honorarios'            => ['total' => '0.00'],
        'despues_de_honorarios' => '0.00',
    ]);

    $enRojo = ResumenDeFacturacion::repartoDeLosIngresos([
        'ingresos'              => '1000.00',
        'medicamentos'          => ['costo' => '800.00'],
        'honorarios'            => ['total' => '500.00'],
        'despues_de_honorarios' => '-300.00',
    ]);

    expect($sinIngresos)->toBe([])
        ->and($enRojo)->toBe([]);
})->note('Una barra no puede dibujar más del todo; la cascada ya dice el número en negativo.');
