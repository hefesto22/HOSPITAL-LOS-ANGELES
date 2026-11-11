<?php

declare(strict_types=1);

use App\Domain\Enums\AccionDeLectura;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\HonorariosPorMedico;
use App\Models\AccesoExpediente;
use App\Models\Medico;
use App\Models\Sede;
use App\Models\User;
use App\Services\EmisorDeFactura;
use App\Services\ReporteDeHonorarios;
use App\Support\Periodo;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Honorarios por médico: tarjetas y reportes impresos (11-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «poder imprimir reportes de un solo médico de cuántos
| honorarios le tocan, o sacar reporte general donde se listen todos …
| si se saca un reporte de todos los médicos se listan en orden
| alfabético y cuánto le toca de honorarios».
|
| La pantalla y el papel salen de `ReporteDeHonorarios`. Lo que se
| prueba acá es que digan lo mismo, que el papel no lo abra cualquiera
| y que cada impresión quede anotada.
|
| `cuentaConHonorarioDe()` y `facturarLaCuentaDeHonorarios()` viven en
| `tests/Pest.php`: con `--parallel` una función de otro archivo no
| existiría acá.
*/

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);
    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Un usuario del rol, en la única sede vigente que haya (la bitácora de
 * lectura necesita saber en qué sede se imprimió).
 */
function imprimeComo(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create([
        'is_active' => true,
        'sede_id'   => Sede::query()->orderBy('id')->value('id') ?? Sede::factory()->create()->id,
    ]);
    $usuario->assignRole($rol);

    $usuario = $usuario->fresh() ?? $usuario;

    test()->actingAs($usuario);

    return $usuario;
}

/**
 * @return array{0: string, 1: string}
 */
function esteMes(): array
{
    return Periodo::de(null, null);
}

it('🔴 cada médico suma lo mismo en su tarjeta, en sus pagadores y en sus renglones', function (): void {
    $ana = Medico::factory()->create(['nombre' => 'ANA MEJIA']);
    $zoila = Medico::factory()->create(['nombre' => 'ZOILA PAZ']);

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($zoila, '1000.0000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($ana, '450.5000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($ana, '300.0000'));

    [$desde, $hasta] = esteMes();
    $reporte = app(ReporteDeHonorarios::class);

    $totales = $reporte->totalesPorMedico($desde, $hasta);
    $pagadores = $reporte->porPagador($desde, $hasta);
    $renglones = $reporte->renglones($desde, $hasta);

    expect($totales[$ana->id]['importe']->redondeado(2))->toBe('750.50')
        ->and($totales[$ana->id]['cuantos'])->toBe(2)
        ->and($totales[$zoila->id]['importe']->redondeado(2))->toBe('1000.00');

    foreach ($totales as $id => $suyo) {
        $porPagador = Decimal::cero();
        $cuantosPorPagador = 0;

        foreach ($pagadores[$id] as $pagador) {
            $porPagador = $porPagador->sumar($pagador['importe']);
            $cuantosPorPagador += $pagador['cuantos'];
        }

        expect($porPagador->redondeado(2))->toBe($suyo['importe']->redondeado(2))
            ->and($cuantosPorPagador)->toBe($suyo['cuantos'])
            ->and($renglones[$id])->toHaveCount($suyo['cuantos']);
    }

    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->totalDelPeriodo())->toBe('L. 1,750.50')
        ->and(collect($pantalla->medicos())->pluck('total', 'nombre')->all())->toBe([
            'ANA MEJIA' => 'L. 750.50',
            'ZOILA PAZ' => 'L. 1,000.00',
        ]);
});

it('el reporte general lista a los médicos de la A a la Z, y con detalle una hoja por médico', function (): void {
    $zoila = Medico::factory()->create(['nombre' => 'ZOILA PAZ']);
    $ana = Medico::factory()->create(['nombre' => 'ANA MEJIA']);

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($zoila, '1000.0000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($ana, '450.5000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($ana, '300.0000'));

    [$desde, $hasta] = esteMes();
    $reporte = app(ReporteDeHonorarios::class);

    $resumen = $reporte->paraImprimir($desde, $hasta, null, false);

    expect(array_column($resumen['resumen'], 'nombre'))->toBe(['ANA MEJIA', 'ZOILA PAZ'])
        ->and(array_column($resumen['resumen'], 'parte'))->toBe(['43 %', '57 %'])
        ->and($resumen['total'])->toBe('L. 1,750.50')
        ->and($resumen['cuantos'])->toBe(3)
        ->and($resumen['hojas'])->toBe([]);

    $conDetalle = $reporte->paraImprimir($desde, $hasta, null, true);

    expect(array_column($conDetalle['hojas'], 'nombre'))->toBe(['ANA MEJIA', 'ZOILA PAZ'])
        ->and($conDetalle['hojas'][0]['renglones'])->toHaveCount(2)
        ->and($conDetalle['hojas'][0]['total'])->toBe('L. 750.50')
        ->and($conDetalle['hojas'][1]['renglones'])->toHaveCount(1);
});

it('las tarjetas se ordenan por lo que más lleva si se pide', function (): void {
    $ana = Medico::factory()->create(['nombre' => 'ANA MEJIA']);
    $zoila = Medico::factory()->create(['nombre' => 'ZOILA PAZ']);

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($ana, '300.0000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($zoila, '1000.0000'));

    $pantalla = app(HonorariosPorMedico::class);

    expect(array_column($pantalla->medicos(), 'nombre'))->toBe(['ANA MEJIA', 'ZOILA PAZ']);

    $pantalla->orden = 'total';

    expect(array_column($pantalla->medicos(), 'nombre'))->toBe(['ZOILA PAZ', 'ANA MEJIA']);

    $pantalla->orden = 'cualquier cosa';

    expect(array_column($pantalla->medicos(), 'nombre'))->toBe(['ANA MEJIA', 'ZOILA PAZ']);
});

it('🔴 un médico dado de baja después de cobrar sigue en la pantalla y en el papel', function (): void {
    $medico = Medico::factory()->create(['nombre' => 'JUAN OSORIO']);

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));

    $medico->delete();

    [$desde, $hasta] = esteMes();
    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->medicos())->toHaveCount(1)
        ->and($pantalla->medicos()[0]['nombre'])->toBe('JUAN OSORIO')
        ->and(app(ReporteDeHonorarios::class)->paraImprimir($desde, $hasta, null, false)['resumen'])->toHaveCount(1);
})->note('Antes la lista de médicos no traía a los dados de baja: su plata entraba al total de arriba y su fila no aparecía, y el total no cuadraba con la suma de los médicos.');

it('🔴 la factura anulada no sale en el reporte del médico', function (): void {
    $medico = Medico::factory()->create();

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));
    $laQueSeAnula = facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));

    app(EmisorDeFactura::class)->anular($laQueSeAnula, 'SE FACTURO AL PACIENTE EQUIVOCADO', User::factory()->create()->id);

    [$desde, $hasta] = esteMes();
    $hoja = app(ReporteDeHonorarios::class)->paraImprimir($desde, $hasta, $medico, false)['hojas'][0];

    expect($hoja['renglones'])->toHaveCount(1)
        ->and($hoja['cuantos'])->toBe(1)
        ->and($hoja['total'])->toBe('L. 1,000.00');
});

it('en la hoja del médico el honorario no repite su nombre', function (): void {
    expect(ReporteDeHonorarios::sinElNombre('CONSULTA EXTERNA GINECOLOGIA · ANA MEJIA', 'ANA MEJIA'))
        ->toBe('CONSULTA EXTERNA GINECOLOGIA')
        ->and(ReporteDeHonorarios::sinElNombre('HONORARIO MÉDICO · JUAN OSORIO · CIRUGÍA GENERAL', 'JUAN OSORIO'))
        ->toBe('HONORARIO MÉDICO · CIRUGÍA GENERAL')
        ->and(ReporteDeHonorarios::sinElNombre('JUAN OSORIO', 'JUAN OSORIO'))->toBe('JUAN OSORIO');
});

it('el reporte de un médico sin honorarios en el período sale en cero, sin error', function (): void {
    $medico = Medico::factory()->create(['nombre' => 'KAREN CARACAMO']);

    [$desde, $hasta] = esteMes();
    $reporte = app(ReporteDeHonorarios::class)->paraImprimir($desde, $hasta, $medico, false);

    expect($reporte['hojas'])->toHaveCount(1)
        ->and($reporte['hojas'][0]['nombre'])->toBe('KAREN CARACAMO')
        ->and($reporte['hojas'][0]['renglones'])->toBe([])
        ->and($reporte['total'])->toBe('L. 0.00');
});

it('🔴 solo dirección y auditoría imprimen: ni el general ni el de un médico', function (): void {
    $medico = Medico::factory()->create();

    foreach (array_keys(RoleSeeder::ROLES) as $rol) {
        imprimeComo($rol);

        $esperado = in_array($rol, ['direccion', 'auditoria'], true) ? 200 : 403;

        test()->get(route('honorarios.reporte'))->assertStatus($esperado);
        test()->get(route('honorarios.reporte.medico', ['medico' => $medico->id]))->assertStatus($esperado);
    }
})->note('Mauricio, 31-oct-2026: «honorarios por médico no deberían poder verlo las cajeras». El papel dice lo mismo que la pantalla, y una ruta de impresión sin candado es ese listado servido por URL.');

it('sin sesión, el reporte no se abre', function (): void {
    Medico::factory()->create();

    expect(test()->get(route('honorarios.reporte'))->isOk())->toBeFalse()
        ->and(AccesoExpediente::query()->count())->toBe(0);
});

it('el papel del médico lleva sus renglones, su total y la firma de recibido', function (): void {
    $medico = Medico::factory()->create(['nombre' => 'JUAN OSORIO']);

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));

    imprimeComo('direccion');

    test()->get(route('honorarios.reporte.medico', ['medico' => $medico->id]))
        ->assertOk()
        ->assertSee('JUAN OSORIO')
        ->assertSee('L. 1,000.00')
        ->assertSee('Recibí conforme')
        ->assertDontSee('Reporte general');
});

it('el papel general con detalle trae el resumen y la hoja de cada médico', function (): void {
    foreach (['ZOILA PAZ', 'ANA MEJIA'] as $nombre) {
        facturarLaCuentaDeHonorarios(cuentaConHonorarioDe(Medico::factory()->create(['nombre' => $nombre]), '500.0000'));
    }

    /*
     * Dirección y no auditoría: cada cuenta de la prueba abre su propia
     * sede, y solo dirección las ve todas (ver la prueba de abajo).
     */
    imprimeComo('direccion');

    $resumen = test()->get(route('honorarios.reporte'))->assertOk();

    $resumen->assertSeeInOrder(['ANA MEJIA', 'ZOILA PAZ'])
        ->assertSee('L. 1,000.00')
        ->assertDontSee('Recibí conforme');

    test()->get(route('honorarios.reporte', ['detalle' => '1']))
        ->assertOk()
        ->assertSeeInOrder(['Reporte general', 'Reporte del médico', 'ANA MEJIA', 'Reporte del médico', 'ZOILA PAZ'])
        ->assertSee('Recibí conforme');
});

it('🔴 quien no ve todas las sedes imprime solo los honorarios de la suya', function (): void {
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe(Medico::factory()->create(['nombre' => 'ZOILA PAZ']), '500.0000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe(Medico::factory()->create(['nombre' => 'ANA MEJIA']), '700.0000'));

    /*
     * Cada cuenta abrió su sede; `imprimeComo()` deja al usuario en la
     * primera, que es la de ZOILA PAZ.
     */
    imprimeComo('auditoria');

    test()->get(route('honorarios.reporte', ['detalle' => '1']))
        ->assertOk()
        ->assertSee('ZOILA PAZ')
        ->assertSee('L. 500.00')
        ->assertDontSee('ANA MEJIA')
        ->assertDontSee('L. 700.00');
})->note('ADR-0002: el alcance por sede vale también en el papel. Auditoría de una sede no ve lo que se le facturó a los médicos en otra.');

it('🔴 cada impresión queda en la bitácora de lectura, con el período', function (): void {
    $medico = Medico::factory()->create();

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));

    $usuario = imprimeComo('direccion');

    test()->get(route('honorarios.reporte.medico', ['medico' => $medico->id]))->assertOk();
    test()->get(route('honorarios.reporte', ['detalle' => '1']))->assertOk();

    $impresiones = AccesoExpediente::query()
        ->where('user_id', $usuario->id)
        ->where('accion', AccionDeLectura::Imprimir->value)
        ->where('recurso_tipo', Medico::class)
        ->orderBy('id')
        ->get();

    expect($impresiones)->toHaveCount(2)
        ->and((int) $impresiones[0]->recurso_id)->toBe($medico->id)
        ->and($impresiones[0]->motivo_texto)->toStartWith('REPORTE DE HONORARIOS · ')
        ->and($impresiones[1]->recurso_id)->toBeNull()
        ->and($impresiones[1]->motivo_texto)->toEndWith('CON EL DETALLE DE CADA MÉDICO');
})->note('El papel lleva nombres de pacientes y es por donde sale casi todo lo que se filtra (§9.E8, §9.L12).');

it('un período de más de un año no se imprime', function (): void {
    imprimeComo('direccion');

    test()->get(route('honorarios.reporte', ['desde' => '2025-01-01', 'hasta' => '2026-06-30']))
        ->assertStatus(422);

    test()->get(route('honorarios.reporte', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']))
        ->assertOk();
});

it('el enlace de la pantalla lleva el período que se está viendo', function (): void {
    $pantalla = app(HonorariosPorMedico::class);
    $pantalla->desde = '2026-10-01';
    $pantalla->hasta = '2026-10-15';

    expect($pantalla->enlaceDelReporte())->toBe(route('honorarios.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-15']))
        ->and($pantalla->enlaceDelReporte(7))->toBe(route('honorarios.reporte.medico', ['medico' => 7, 'desde' => '2026-10-01', 'hasta' => '2026-10-15']));
});

it('incrustado en el modal, el papel sale sin su barra de botones', function (): void {
    Medico::factory()->create();

    imprimeComo('direccion');

    test()->get(route('honorarios.reporte'))->assertOk()->assertSee('Volver a la pantalla');
    test()->get(route('honorarios.reporte', ['incrustada' => 1]))->assertOk()->assertDontSee('Volver a la pantalla');
});

it('el modal muestra la hoja incrustada y, en el general, ofrece la hoja de cada uno', function (): void {
    $pantalla = app(HonorariosPorMedico::class);
    $pantalla->desde = '2026-10-01';
    $pantalla->hasta = '2026-10-15';

    $general = $pantalla->enlaceDelReporte(incrustada: true);
    $conDetalle = $pantalla->enlaceDelReporte(incrustada: true, conDetalle: true);

    expect($general)->toBe(route('honorarios.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-15', 'incrustada' => 1]))
        ->and($conDetalle)->toBe(route('honorarios.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-15', 'detalle' => 1, 'incrustada' => 1]))
        ->and($pantalla->enlaceDelReporte(7, incrustada: true, conDetalle: true))
        ->toBe(route('honorarios.reporte.medico', ['medico' => 7, 'desde' => '2026-10-01', 'hasta' => '2026-10-15', 'incrustada' => 1]));

    $html = view('honorarios.modal', [
        'enlace'           => $general,
        'enlaceConDetalle' => $conDetalle,
        'titulo'           => 'Reporte general de honorarios',
    ])->render();

    expect($html)->toContain('src="'.e($general).'"')
        ->and($html)->toContain('Con la hoja de cada uno');
})->note('Mauricio, 11-nov-2026: «que no me redirija a una ventana, que se abra un modal y la ventana de impresión». La hoja es la misma ruta de antes, sin su barra.');
