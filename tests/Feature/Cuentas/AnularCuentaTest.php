<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\MotivoDeAnulacionDeCuenta;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCuenta;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDeRecepcion;
use App\Support\CatalogoDelRol;
use Database\Seeders\MatrizDePermisosSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * ANULAR UNA CUENTA ABIERTA POR ERROR (Mauricio, 21-oct-2026).
 *
 * «Cuando se abre una cuenta por accidente no hay cómo quitarla». Y si ya
 * tiene algo cargado: «debe haber un motivo del por qué se cierra esa
 * cuenta y por qué no se factura».
 */

const DETALLE_DE_LA_ANULACION = 'SE LE CARGÓ EL HEMOGRAMA A LA HERMANA, QUE VENÍA CON ELLA';

function elQueAnulaCuentas(): AnuladorDeCuenta
{
    return app(AnuladorDeCuenta::class);
}

/**
 * CONTADO es UNO solo: su código es único en la base. Cada cuenta de
 * este archivo lo reusa, o la segunda revienta con `convenios_codigo_unico`.
 */
function elContadoDeLaAnulacion(): Convenio
{
    return Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();
}

/**
 * Abierta como la abre la pantalla: encuentro y cuenta en la misma sede.
 */
function unaCuentaQueSeAbrioPorError(?Persona $persona = null, ?Sede $sede = null): Cuenta
{
    $sede ??= Sede::factory()->create();
    $persona ??= Persona::factory()->create([
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);

    $expediente = Expediente::query()
        ->where('persona_id', $persona->id)
        ->where('sede_id', $sede->id)
        ->first() ?? Expediente::factory()->create([
            'sede_id'    => $sede->id,
            'persona_id' => $persona->id,
        ]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Emergencia,
        convenio: elContadoDeLaAnulacion(),
        sede: $sede,
    );
}

/*
|--------------------------------------------------------------------------
| La cuenta vacía
|--------------------------------------------------------------------------
*/

it('🔴 una cuenta vacia se anula, con quien, cuando y por que, y su encuentro tambien', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();
    $cajera = User::factory()->create(['sede_id' => $cuenta->sede_id]);
    $this->actingAs($cajera);

    elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::AbiertaPorError);

    $cuenta->refresh();
    $encuentro = $cuenta->encuentro()->firstOrFail();

    expect($cuenta->estado)->toBe(EstadoCuenta::Anulada)
        ->and($cuenta->anulada_en)->not->toBeNull()
        ->and($cuenta->anulada_por)->toBe($cajera->id)
        ->and($cuenta->tipo_anulacion)->toBe(MotivoDeAnulacionDeCuenta::AbiertaPorError)
        ->and($cuenta->motivo_anulacion)->toBe('SE ABRIÓ POR ERROR')
        ->and($cuenta->total_al_anular)->toBe('0.00')
        ->and($encuentro->estado)->toBe(EstadoEncuentro::Anulado)
        ->and($encuentro->anulado_por)->toBe($cajera->id);
})->note('Nada se borra: la cuenta queda anulada con su número, que no se reusa, y aparece en «Cuentas (consulta)» con el filtro de anuladas.');

it('🔴 al paciente se le puede abrir la cuenta correcta enseguida', function (): void {
    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create(['fecha_nacimiento' => now()->subYears(35)->toDateString()]);

    $equivocada = unaCuentaQueSeAbrioPorError($persona, $sede);
    elQueAnulaCuentas()->anular($equivocada, MotivoDeAnulacionDeCuenta::PagadorOTipoEquivocado);

    $correcta = unaCuentaQueSeAbrioPorError($persona, $sede);

    expect($correcta->estado)->toBe(EstadoCuenta::Abierta)
        ->and($correcta->encuentro()->firstOrFail()->encuentro_anterior_id)->toBeNull();
})->note('Un paciente tiene una sola cuenta viva (ADR-0007): la abierta por error le bloqueaba la correcta. Y el encuentro anulado no se cuenta como ingreso anterior: no hubo reingreso.');

/*
|--------------------------------------------------------------------------
| 🔴 Con algo cargado
|--------------------------------------------------------------------------
*/

it('🔴 si ya tiene algo cargado exige decir por que no se factura', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();
    conUnServicioDe($cuenta, '150.0000');

    expect(fn () => elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::PacienteEquivocado))
        ->toThrow(CuentaException::class, 'por qué no se le factura');

    expect($cuenta->refresh()->estado)->toBe(EstadoCuenta::Abierta)
        ->and(Cargo::query()->where('cuenta_id', $cuenta->id)->where('estado', EstadoCargo::Pendiente->value)->count())->toBe(1);
})->note('Mauricio: «si ya tiene algo agregado debe haber un motivo del por qué se cierra esa cuenta y por qué no se factura». El motivo de la lista no alcanza: falta el detalle.');

it('🔴 con el detalle quita todos los renglones y guarda cuanto llevaba', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();
    conUnServicioDe($cuenta, '150.0000');
    conUnServicioDe($cuenta, '80.0000');

    elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::PacienteEquivocado, DETALLE_DE_LA_ANULACION);

    $cuenta->refresh();
    $originales = Cargo::query()->where('cuenta_id', $cuenta->id)->where('estado', EstadoCargo::Anulado->value)->get();

    expect($cuenta->estado)->toBe(EstadoCuenta::Anulada)
        ->and($cuenta->total)->toBe('0.00')
        ->and($cuenta->total_al_anular)->toBe('230.00')
        ->and($cuenta->motivo_anulacion)->toBe('SE ABRIÓ AL PACIENTE EQUIVOCADO: '.DETALLE_DE_LA_ANULACION)
        ->and($cuenta->renglonesVivos())->toBeEmpty()
        ->and($originales)->toHaveCount(2);

    foreach ($originales as $cargo) {
        expect($cargo->motivo_anulacion)->toContain('SE ANULÓ LA CUENTA '.mb_strtoupper($cuenta->numero))
            ->and($cargo->motivo_anulacion)->toContain('HEMOGRAMA');
    }
})->note('Cada renglón se quita con su reversa al lado y el motivo de la cuenta: el total vuelve a cero sumando, sin editar nada. `total_al_anular` es lo que dirección ve como «anulado hoy».');

it('lo que salio de farmacia vuelve al mismo estante', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();
    $almacen = Almacen::factory()->create();

    $item = Item::factory()->medicamento()->create([
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a('75.0000')->create();

    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [new LineaRecibida(
            item: $item,
            presentacion: null,
            cantidadPresentacion: Decimal::de('30'),
            unidadesPorPresentacion: Decimal::de('1'),
            costoPorPresentacion: Decimal::de('15'),
            numeroLote: 'L-ANULA',
            vencimiento: now()->addYear(),
        )],
    );

    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('8'),
        claveIdempotencia: (string) Str::uuid(),
        almacen: $almacen,
    ));

    expect(app(ConsultorDeExistencias::class)->totalEn($item, $almacen)->redondeado(4))->toBe('22.0000');

    elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::AbiertaPorError, DETALLE_DE_LA_ANULACION);

    expect(app(ConsultorDeExistencias::class)->totalEn($item, $almacen)->redondeado(4))->toBe('30.0000');
})->note('Anular es «esto no debió cargarse». Por eso el modal avisa que no es para el paciente que sí recibió algo y no va a pagar: ahí el medicamento ya no está en el estante.');

/*
|--------------------------------------------------------------------------
| Lo que no hace
|--------------------------------------------------------------------------
*/

it('🔴 con plata abonada no se anula', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();
    abonarle($cuenta, '100.00');

    expect(fn () => elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::AbiertaPorError))
        ->toThrow(CuentaException::class, 'abonados');

    expect($cuenta->refresh()->estado)->toBe(EstadoCuenta::Abierta);
})->note('La plata se devuelve con su comprobante: primero se anula el recibo, con su turno abierto. Anular la cuenta encima de un abono vivo dejaría L 100 en la gaveta sin cuenta a la que pertenecer.');

it('una cuenta facturada no se anula', function (): void {
    $cuenta = Cuenta::factory()->cerrada()->create(['convenio_id' => elContadoDeLaAnulacion()->id]);

    expect(fn () => elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::AbiertaPorError))
        ->toThrow(CuentaException::class, 'ya no se anula');
});

it('otro motivo exige escribir cual', function (): void {
    $cuenta = unaCuentaQueSeAbrioPorError();

    expect(fn () => elQueAnulaCuentas()->anular($cuenta, MotivoDeAnulacionDeCuenta::Otro, 'X'))
        ->toThrow(CuentaException::class, 'Otro motivo');
});

it('la base rechaza un tipo de anulacion que no existe', function (): void {
    $cuenta = Cuenta::factory()->create(['convenio_id' => elContadoDeLaAnulacion()->id]);

    expect(fn () => DB::table('cuentas')->where('id', $cuenta->id)->update(['tipo_anulacion' => 'porque_si']))
        ->toThrow(QueryException::class, 'cuentas_tipo_anulacion_conocido');
})->note('§9.C4: el CHECK se prueba sin pasar por el modelo, que con el enum fallaría antes de llegar a la base.');

/*
|--------------------------------------------------------------------------
| Quién anula
|--------------------------------------------------------------------------
*/

function sembrarLosPermisosParaAnular(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->seed(RoleSeeder::class);

    foreach (['Cuenta', 'Cargo', 'Encuentro', 'Persona'] as $sujeto) {
        foreach (MatrizDePermisosSeeder::ACCIONES as $accion) {
            Permission::findOrCreate("{$accion}:{$sujeto}", 'web');
        }
    }

    test()->seed(MatrizDePermisosSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function alguienQueAnula(string $rol): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['is_active' => true]);
    $usuario->assignRole($rol);

    return $usuario->fresh() ?? $usuario;
}

it('🔴 laboratorio y consulta externa anulan la que ellos abrieron, y no la de otro', function (string $rol): void {
    sembrarLosPermisosParaAnular();

    $quien = alguienQueAnula($rol);
    $suya = Cuenta::factory()->create(['created_by' => $quien->id, 'convenio_id' => elContadoDeLaAnulacion()->id]);
    $ajena = Cuenta::factory()->create(['convenio_id' => elContadoDeLaAnulacion()->id]);

    expect(Gate::forUser($quien)->allows('anular', $suya))->toBeTrue()
        ->and(Gate::forUser($quien)->allows('anular', $ajena))->toBeFalse();
})->with(['laboratorio', 'consulta_externa'])
    ->note('Corrigen su propio error sin llamar a nadie. La de otro la anula caja, admisión o dirección.');

it('caja, admision y direccion anulan cualquiera; auditoria ninguna', function (): void {
    sembrarLosPermisosParaAnular();

    $cuenta = Cuenta::factory()->create(['convenio_id' => elContadoDeLaAnulacion()->id]);

    foreach (['caja', 'admision', 'direccion'] as $rol) {
        expect(Gate::forUser(alguienQueAnula($rol))->allows('anular', $cuenta))->toBeTrue("{$rol} debería poder anular");
    }

    expect(Gate::forUser(alguienQueAnula('auditoria'))->allows('anular', $cuenta))->toBeFalse();
});

it('nadie anula una cuenta facturada, ni direccion', function (): void {
    sembrarLosPermisosParaAnular();

    $cuenta = Cuenta::factory()->cerrada()->create(['convenio_id' => elContadoDeLaAnulacion()->id]);

    expect(Gate::forUser(alguienQueAnula('direccion'))->allows('anular', $cuenta))->toBeFalse();
});

it('🔴 laboratorio no anula una cuenta con renglones de otra area', function (): void {
    sembrarLosPermisosParaAnular();

    $laboratorista = alguienQueAnula('laboratorio');
    $cuenta = Cuenta::factory()->create(['created_by' => $laboratorista->id, 'convenio_id' => elContadoDeLaAnulacion()->id]);

    $examen = Item::factory()->de(TipoItem::EstudioLaboratorio, CategoriaLegalDeDescuento::RadiologiaYLaboratorio)->create();
    Cargo::factory()->enLaCuenta($cuenta)->create(['item_id' => $examen->id]);

    expect(CatalogoDelRol::todoLoVivoEsDeSuArea($cuenta, $laboratorista))->toBeTrue();

    $consulta = Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada)->create();
    Cargo::factory()->enLaCuenta($cuenta)->create(['item_id' => $consulta->id]);

    expect(CatalogoDelRol::todoLoVivoEsDeSuArea($cuenta, $laboratorista))->toBeFalse()
        ->and(CatalogoDelRol::todoLoVivoEsDeSuArea($cuenta, alguienQueAnula('caja')))->toBeTrue();
})->note('Anular quita TODOS los renglones. El honorario o el medicamento que puso otra área no lo quita laboratorio, igual que con la ✕ de cada renglón.');
