<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoDiagnostico;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\TipoDiagnostico;
use App\Domain\Exceptions\DiagnosticoException;
use App\Models\Cie10;
use App\Models\Diagnostico;
use App\Models\Encuentro;
use App\Models\User;
use App\Services\RegistradorDeDiagnostico;
use Database\Seeders\Cie10DeArranqueSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * DE QUÉ SE LE ESTÁ ATENDIENDO.
 *
 * El sistema ya sabía QUÉ se le cobró y QUIÉN se lo dio. Esto es POR QUÉ.
 * Sin diagnóstico la aseguradora no procesa el reclamo, el Art. 180 del
 * Código de Salud queda sin cumplirse, y el hospital no puede contestar
 * de qué atiende.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RoleSeeder::class);
    test()->seed(Cie10DeArranqueSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function elRegistrador(): RegistradorDeDiagnostico
{
    return app(RegistradorDeDiagnostico::class);
}

function unMedico(): User
{
    $medico = User::factory()->create();
    $medico->assignRole('medico');

    test()->actingAs($medico);

    return $medico;
}

function unCodigo(string $codigo): Cie10
{
    /** @var Cie10 $fila */
    $fila = Cie10::query()->where('codigo', $codigo)->firstOrFail();

    return $fila;
}

/*
|--------------------------------------------------------------------------
| 🔴 Diagnosticar es un acto médico
|--------------------------------------------------------------------------
*/

it('🔴 la cajera no puede diagnosticar', function (): void {
    $cajera = User::factory()->create();
    $cajera->assignRole('caja');

    expect(Gate::forUser($cajera)->allows('create', Diagnostico::class))->toBeFalse();
})->note('🔴 Atado al ROL y no a un permiso de Shield a propósito. Un permiso «Create:Diagnostico» que dirección le pueda dar a la cajera para destrabar una factura anularía la decisión en la primera semana de apuro, sin que nadie sienta que decidió nada.');

it('el medico si puede', function (): void {
    $medico = User::factory()->create();
    $medico->assignRole('medico');

    expect(Gate::forUser($medico)->allows('create', Diagnostico::class))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Con qué entró y con qué salió
|--------------------------------------------------------------------------
*/

it('registra el diagnostico con su autor y su momento', function (): void {
    $medico = unMedico();
    $encuentro = Encuentro::factory()->create();

    $dx = elRegistrador()->registrar(
        encuentro: $encuentro,
        cie10: unCodigo('A90'),
        tipo: TipoDiagnostico::Principal,
        momento: MomentoDiagnostico::Ingreso,
    );

    expect($dx->diagnosticado_por)->toBe($medico->id)
        ->and($dx->estado)->toBe(EstadoDiagnostico::Vigente)
        ->and($dx->confirmado)->toBeFalse()
        ->and($dx->esNotificable())->toBeTrue();
})->note('Al ingreso nace presuntivo y está bien que así sea: guardar un presuntivo como confirmado hace que las estadísticas cuenten casos que nunca existieron. Y el dengue viene marcado como notificable desde el catálogo, no desde acá.');

it('🔴 no deja dos principales en el mismo momento', function (): void {
    unMedico();
    $encuentro = Encuentro::factory()->create();

    elRegistrador()->registrar($encuentro, unCodigo('A90'), TipoDiagnostico::Principal, MomentoDiagnostico::Ingreso);
    elRegistrador()->registrar($encuentro, unCodigo('J18.9'), TipoDiagnostico::Principal, MomentoDiagnostico::Ingreso);
})->throws(DiagnosticoException::class)
    ->note('🔴 Dos principales al egreso es una cuenta que la aseguradora no sabe contra cuál evaluar, y un caso contado dos veces en la notificación epidemiológica. Si cambió el criterio, lo que corresponde es CORREGIR el que está.');

it('el mismo principal si convive entre ingreso y egreso', function (): void {
    unMedico();
    $encuentro = Encuentro::factory()->create();

    elRegistrador()->registrar($encuentro, unCodigo('R10.4'), TipoDiagnostico::Principal, MomentoDiagnostico::Ingreso);
    $egreso = elRegistrador()->registrar($encuentro, unCodigo('K35.8'), TipoDiagnostico::Principal, MomentoDiagnostico::Egreso);

    expect($egreso->momento)->toBe(MomentoDiagnostico::Egreso)
        ->and($egreso->confirmado)->toBeTrue();
})->note('Entra por «dolor abdominal» y sale con «apendicitis aguda»: entre esos dos códigos está el trabajo del hospital, y es lo que la aseguradora compara.');

/*
|--------------------------------------------------------------------------
| 🔴 Corregir es enmendar, no editar (ADR-0004)
|--------------------------------------------------------------------------
*/

it('🔴 al corregir, el anterior queda tachado y el nuevo lo referencia', function (): void {
    unMedico();
    $encuentro = Encuentro::factory()->create();

    $viejo = elRegistrador()->registrar($encuentro, unCodigo('A90'), TipoDiagnostico::Principal, MomentoDiagnostico::Egreso);

    $nuevo = elRegistrador()->corregir(
        anterior: $viejo,
        cie10: unCodigo('A91'),
        motivo: 'Evolucionó a dengue grave con datos de fuga capilar.',
    );

    expect($viejo->refresh()->estado)->toBe(EstadoDiagnostico::Corregido)
        ->and($viejo->motivo_correccion)->toContain('fuga capilar')
        ->and($nuevo->corrige_a_id)->toBe($viejo->id)
        ->and($nuevo->estado)->toBe(EstadoDiagnostico::Vigente);
})->note('🔴 No hay UPDATE del código. Lo que un perito busca no es el diagnóstico final: es qué se pensó, cuándo se cambió de idea y quién la cambió. Un UPDATE borra exactamente eso, y para siempre.');

it('la enmienda sin motivo se rechaza', function (): void {
    unMedico();
    $encuentro = Encuentro::factory()->create();

    $dx = elRegistrador()->registrar($encuentro, unCodigo('A90'), TipoDiagnostico::Principal, MomentoDiagnostico::Ingreso);

    elRegistrador()->retractar($dx, 'error');
})->throws(DiagnosticoException::class)
    ->note('Diez caracteres no hacen bueno un motivo, pero descartan «ok» y «error». Un diagnóstico tachado sin explicación deja la duda instalada sin la respuesta.');

it('lo retractado sigue existiendo', function (): void {
    unMedico();
    $encuentro = Encuentro::factory()->create();

    $dx = elRegistrador()->registrar($encuentro, unCodigo('A90'), TipoDiagnostico::Principal, MomentoDiagnostico::Ingreso);
    elRegistrador()->retractar($dx, 'Se cargó en el paciente equivocado durante el cambio de turno.');

    expect(Diagnostico::query()->count())->toBe(1)
        ->and($dx->refresh()->estado)->toBe(EstadoDiagnostico::Retractado);
})->note('«Nunca eliminada» (§8.8-1). Se muestra tachado, con motivo y autor — que es lo que tiene que verse.');

/*
|--------------------------------------------------------------------------
| El catálogo
|--------------------------------------------------------------------------
*/

it('encuentra un diagnostico escrito sin tildes', function (): void {
    expect(Cie10::buscar('neumonia')->pluck('codigo')->all())->toContain('J18.9');
})->note('Si no aparece a la primera, el médico elige cualquier código que sí aparezca — y ahí se arruina justamente la estadística que este catálogo existe para permitir.');

it('encuentra por codigo', function (): void {
    expect(Cie10::buscar('A90')->pluck('codigo')->all())->toContain('A90');
});
