<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Filament\Pages\HonorariosPorMedico;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\ProponedorDeHonorario;
use App\Services\RegistradorDeCargo;
use App\Services\ReporteDeHonorarios;
use App\Support\Periodo;
use Database\Seeders\HonorariosDelPersonalSeeder;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Honorarios del personal: por turno o área, sin personas (11-nov-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «también hay honorarios, ejemplo de enfermería … si no se
| registran las enfermeras, solo los médicos … que salga así: en el
| reporte pertenece a X turno, sin enfermeras». El turno es el que dice
| el nombre del ítem.
|
| Y todo honorario de médico exige al médico: lo que se cobró sin él antes
| sale aparte, para que el total cuadre.
*/

/**
 * Un honorario del personal con su precio de lista.
 *
 * @param numeric-string $precio
 */
function unHonorarioDelPersonal(string $nombre, string $precio): Item
{
    $item = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create(['nombre' => $nombre, 'cobra_el_honorario' => QuienCobraElHonorario::Personal]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * Un honorario de médico con su precio de lista.
 *
 * @param numeric-string $precio
 */
function unHonorarioDeMedicoConLista(string $nombre, string $precio): Item
{
    $item = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create(['nombre' => $nombre]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * Una cuenta de contado con ese honorario cargado, con o sin médico.
 */
function cuentaConElHonorario(Item $item, ?int $medicoId = null): Cuenta
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $cuenta = unaCuentaCon($contado);

    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        medicoId: $medicoId,
    ));

    return $cuenta->refresh();
}

/**
 * Un usuario del rol, en la primera sede (la bitácora necesita saber en
 * qué sede se imprimió).
 */
function imprimeElTurnoComo(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create([
        'is_active' => true,
        'sede_id'   => Sede::query()->orderBy('id')->value('id') ?? Sede::factory()->create()->id,
    ]);
    $usuario->assignRole($rol);

    test()->actingAs($usuario->fresh() ?? $usuario);

    return $usuario;
}

it('🔴 el honorario de un turno cuenta para su turno y no para ningún médico', function (): void {
    $turnoC = unHonorarioDelPersonal('HONORARIOS ENFERMERIA TURNO C', '670.0000');
    $medico = Medico::factory()->create(['nombre' => 'JUAN OSORIO']);

    facturarLaCuentaDeHonorarios(cuentaConElHonorario($turnoC));

    /*
     * Aunque llegue un médico —un formulario viejo, un import—, el cargo
     * no lo guarda ni lo nombra.
     */
    $conMedico = cuentaConElHonorario($turnoC, $medico->id);
    $cargo = Cargo::query()->where('cuenta_id', $conMedico->id)->firstOrFail();

    expect($cargo->medico_id)->toBeNull()
        ->and($cargo->texto)->toBe('HONORARIOS ENFERMERIA TURNO C');

    facturarLaCuentaDeHonorarios($conMedico);

    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->medicos())->toBe([])
        ->and(array_column($pantalla->personal(), 'nombre'))->toBe(['ENFERMERIA TURNO C'])
        ->and($pantalla->personal()[0]['renglones'])->toBe(2)
        ->and($pantalla->personal()[0]['total'])->toBe('L. 1,340.00')
        ->and($pantalla->totalDelPeriodo())->toBe('L. 1,340.00')
        ->and($pantalla->resumen()['totalPersonal'])->toBe('L. 1,340.00');
});

it('🔴 un honorario de médico cobrado sin médico sale aparte y el total cuadra', function (): void {
    $consulta = unHonorarioDeMedicoConLista('HONORARIOS MEDICO GENERAL', '800.0000');
    $medico = Medico::factory()->create();

    facturarLaCuentaDeHonorarios(cuentaConElHonorario($consulta, $medico->id));
    facturarLaCuentaDeHonorarios(cuentaConElHonorario($consulta));

    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->medicos())->toHaveCount(1)
        ->and($pantalla->medicos()[0]['total'])->toBe('L. 800.00')
        ->and($pantalla->sinMedicoDelPeriodo())->toBe(['renglones' => 1, 'total' => 'L. 800.00'])
        ->and($pantalla->totalDelPeriodo())->toBe('L. 1,600.00')
        ->and($pantalla->resumen()['renglones'])->toBe(2);

    [$desde, $hasta] = Periodo::de(null, null);
    $papel = app(ReporteDeHonorarios::class)->paraImprimir($desde, $hasta, null, false);

    expect($papel['sinMedico'])->toBe(['cuantos' => 1, 'total' => 'L. 800.00'])
        ->and($papel['total'])->toBe('L. 1,600.00');
})->note('No se inventa a quién: se muestra aparte para que alguien lo revise. Ya facturado no se puede cambiar.');

it('el reporte general trae los turnos después de los médicos, y cada uno con su hoja', function (): void {
    $turnoA = unHonorarioDelPersonal('HONORARIOS ENFERMERIA TURNO A', '570.0000');
    $circulante = unHonorarioDelPersonal('HONORARIOS CIRCULANTE 2 HORAS', '1050.0000');
    $consulta = unHonorarioDeMedicoConLista('HONORARIOS MEDICO GENERAL', '800.0000');

    facturarLaCuentaDeHonorarios(cuentaConElHonorario($turnoA));
    facturarLaCuentaDeHonorarios(cuentaConElHonorario($circulante));
    facturarLaCuentaDeHonorarios(cuentaConElHonorario($consulta, Medico::factory()->create(['nombre' => 'ANA MEJIA'])->id));

    [$desde, $hasta] = Periodo::de(null, null);
    $papel = app(ReporteDeHonorarios::class)->paraImprimir($desde, $hasta, null, true);

    expect(array_column($papel['resumen'], 'nombre'))->toBe(['ANA MEJIA'])
        ->and(array_column($papel['personal'], 'nombre'))->toBe(['CIRCULANTE 2 HORAS', 'ENFERMERIA TURNO A'])
        ->and($papel['totalPersonal'])->toBe('L. 1,620.00')
        ->and($papel['total'])->toBe('L. 2,420.00')
        ->and($papel['sinMedico'])->toBeNull()
        ->and(array_column($papel['hojas'], 'tipo'))->toBe(['medico', 'personal', 'personal'])
        ->and(array_column($papel['hojas'], 'nombre'))->toBe(['ANA MEJIA', 'CIRCULANTE 2 HORAS', 'ENFERMERIA TURNO A']);
});

it('la ficha del turno trae sus pagadores y el enlace a su reporte', function (): void {
    $turnoC = unHonorarioDelPersonal('HONORARIOS ENFERMERIA TURNO C', '670.0000');

    facturarLaCuentaDeHonorarios(cuentaConElHonorario($turnoC));

    $pantalla = app(HonorariosPorMedico::class);
    $pantalla->turno = $turnoC->id;

    $ficha = $pantalla->ficha();

    expect($ficha)->not->toBeNull()
        ->and($ficha['tipo'] ?? null)->toBe('personal')
        ->and($ficha['nombre'] ?? null)->toBe('ENFERMERIA TURNO C')
        ->and($ficha['total'] ?? null)->toBe('L. 670.00')
        ->and($ficha['pagadores'] ?? [])->toHaveCount(1)
        ->and((string) ($ficha['enlace'] ?? ''))->toContain('/honorarios/reporte/turno/'.$turnoC->id);
});

describe('el papel del turno', function (): void {
    beforeEach(function (): void {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        test()->seed(RoleSeeder::class);
        test()->seed(MatrizDePermisosSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    it('sale con sus renglones y la firma de quien lo recibe por el turno', function (): void {
        $turnoC = unHonorarioDelPersonal('HONORARIOS ENFERMERIA TURNO C', '670.0000');

        facturarLaCuentaDeHonorarios(cuentaConElHonorario($turnoC));

        imprimeElTurnoComo('direccion');

        test()->get(route('honorarios.reporte.turno', ['item' => $turnoC->id]))
            ->assertOk()
            ->assertSee('ENFERMERIA TURNO C')
            ->assertSee('L. 670.00')
            ->assertSee('Recibí conforme por el turno');
    });

    it('🔴 caja no lo imprime', function (): void {
        $turnoC = unHonorarioDelPersonal('HONORARIOS ENFERMERIA TURNO C', '670.0000');

        imprimeElTurnoComo('caja');

        test()->get(route('honorarios.reporte.turno', ['item' => $turnoC->id]))->assertForbidden();
    });

    it('solo se abre para un honorario del personal', function (): void {
        $consulta = unHonorarioDeMedicoConLista('HONORARIOS MEDICO GENERAL', '800.0000');

        imprimeElTurnoComo('direccion');

        test()->get(route('honorarios.reporte.turno', ['item' => $consulta->id]))->assertNotFound();
    });
});

it('🔴 solo un honorario puede ser del personal', function (): void {
    $servicio = Item::factory()->create(['cobra_el_honorario' => QuienCobraElHonorario::Personal]);

    expect($servicio->refresh()->cobra_el_honorario)->toBe(QuienCobraElHonorario::Medico);

    DB::table('items')->where('id', $servicio->id)->update(['cobra_el_honorario' => 'personal']);
})->throws(QueryException::class, 'items_cobra_el_honorario_check');

it('un honorario sin decir quién lo cobra es de médico', function (): void {
    $honorario = Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)->create();

    expect($honorario->refresh()->cobra_el_honorario)->toBe(QuienCobraElHonorario::Medico)
        ->and($honorario->esHonorarioDelPersonal())->toBeFalse();
});

it('el seeder marca los siete del hospital, y correrlo de nuevo no desmarca nada', function (): void {
    foreach (array_keys(HonorariosDelPersonalSeeder::HONORARIOS) as $codigo) {
        Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)->create(['codigo' => $codigo]);
    }

    $medicoGeneral = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)
        ->create(['codigo' => 'HOS-020']);

    test()->seed(HonorariosDelPersonalSeeder::class);

    $otro = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create(['cobra_el_honorario' => QuienCobraElHonorario::Personal]);

    test()->seed(HonorariosDelPersonalSeeder::class);

    $delPersonal = Item::query()
        ->where('cobra_el_honorario', QuienCobraElHonorario::Personal->value)
        ->pluck('codigo')
        ->sort()
        ->values()
        ->all();

    expect($delPersonal)->toBe(collect(array_keys(HonorariosDelPersonalSeeder::HONORARIOS))->push($otro->codigo)->sort()->values()->all())
        ->and($medicoGeneral->refresh()->cobra_el_honorario)->toBe(QuienCobraElHonorario::Medico);
});

it('al particular sin precio, el honorario del personal no pide lo que cobra «el médico»', function (): void {
    $turno = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario)
        ->create(['cobra_el_honorario' => QuienCobraElHonorario::Personal]);

    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $propuesto = app(ProponedorDeHonorario::class)->para($turno, unaCuentaCon($contado), null, now());

    expect($propuesto->precio)->toBeNull()
        ->and($propuesto->explicacion)->toBe('Escribí cuánto se cobra por este honorario.');
});
