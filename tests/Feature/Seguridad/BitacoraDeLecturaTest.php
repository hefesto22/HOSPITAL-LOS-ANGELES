<?php

declare(strict_types=1);

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\MotivoBreakTheGlass;
use App\Domain\Exceptions\SihlaException;
use App\Models\AccesoExpediente;
use App\Models\Sede;
use App\Models\User;
use App\Support\BitacoraDeLectura;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La bitácora de LECTURA es la diferencia entre poder identificar a quien
 * filtró un expediente y que responda el hospital (§9.L6, ADR-0004).
 */
function usuarioAutenticado(): User
{
    $sede = Sede::factory()->create();

    /** @var User $usuario */
    $usuario = User::factory()->create(['sede_id' => $sede->id, 'is_active' => true]);

    test()->actingAs($usuario);

    return $usuario;
}

it('registra una lectura simple', function (): void {
    $usuario = usuarioAutenticado();

    BitacoraDeLectura::registrar(
        recursoTipo: 'App\\Models\\Paciente',
        recursoId: 42,
        pacienteId: 42,
    );

    $acceso = AccesoExpediente::query()->firstOrFail();

    expect($acceso->user_id)->toBe($usuario->id)
        ->and($acceso->paciente_id)->toBe(42)
        ->and($acceso->accion)->toBe(AccionDeLectura::Ver)
        ->and($acceso->es_break_the_glass)->toBeFalse();
})->note('Leer no modifica nada: si no se registra la lectura, ese riesgo queda completamente descubierto.');

it('no registra nada cuando no hay usuario', function (): void {
    BitacoraDeLectura::registrar(recursoTipo: 'App\\Models\\Paciente', recursoId: 1);

    expect(AccesoExpediente::query()->count())->toBe(0);
})->note('En consola y en jobs el acceso no es de una persona; no hay a quién atribuirlo.');

it('NUNCA lanza excepcion aunque la escritura falle', function (): void {
    usuarioAutenticado();

    // Un recurso_tipo imposible fuerza el fallo del INSERT.
    BitacoraDeLectura::registrar(
        recursoTipo: str_repeat('x', 5000),
        recursoId: 1,
    );

    expect(true)->toBeTrue();
})->note('Entre perder una fila de bitácora y bloquear una atención a las 3 am, se pierde la fila — pero se reporta a Sentry.');

it('exige motivo y texto en un acceso de emergencia', function (): void {
    usuarioAutenticado();

    BitacoraDeLectura::registrarAccesoDeEmergencia(
        recursoTipo: 'App\\Models\\Paciente',
        motivo: MotivoBreakTheGlass::EmergenciaVital,
        motivoTexto: 'Politraumatizado sin documentos, requiere antecedentes de alergias.',
        recursoId: 7,
        pacienteId: 7,
    );

    $acceso = AccesoExpediente::query()->firstOrFail();

    expect($acceso->es_break_the_glass)->toBeTrue()
        ->and($acceso->motivo)->toBe(MotivoBreakTheGlass::EmergenciaVital)
        ->and($acceso->motivo_texto)->not->toBeEmpty();
});

it('la base rechaza un break-the-glass sin justificacion suficiente', function (): void {
    $usuario = usuarioAutenticado();

    DB::table('accesos_expediente')->insert([
        'sede_id'            => $usuario->sede_id,
        'user_id'            => $usuario->id,
        'recurso_tipo'       => 'App\\Models\\Paciente',
        'accion'             => 'ver',
        'es_break_the_glass' => true,
        'motivo'             => 'emergencia_vital',
        'motivo_texto'       => 'urgente',
        'ocurrido_en'        => now(),
    ]);
})->throws(QueryException::class)
    ->note('CHECK como defensa profunda: otra aplicación o un script van a escribir en esta base algún día.');

it('no permite modificar un registro de acceso', function (): void {
    usuarioAutenticado();
    BitacoraDeLectura::registrar(recursoTipo: 'App\\Models\\Paciente', recursoId: 1);

    $acceso = AccesoExpediente::query()->firstOrFail();
    $acceso->update(['motivo_texto' => 'otra cosa']);
})->throws(SihlaException::class)
    ->note('Una bitácora que se puede editar no es evidencia.');

it('no permite borrar un registro de acceso', function (): void {
    usuarioAutenticado();
    BitacoraDeLectura::registrar(recursoTipo: 'App\\Models\\Paciente', recursoId: 1);

    AccesoExpediente::query()->firstOrFail()->delete();
})->throws(SihlaException::class);

it('lista los accesos de emergencia pendientes de revision', function (): void {
    usuarioAutenticado();

    BitacoraDeLectura::registrar(recursoTipo: 'App\\Models\\Paciente', recursoId: 1);
    BitacoraDeLectura::registrarAccesoDeEmergencia(
        recursoTipo: 'App\\Models\\Paciente',
        motivo: MotivoBreakTheGlass::CoberturaDeTurno,
        motivoTexto: 'Cubro el turno del Dr. Sierra desde las 22:00.',
        recursoId: 2,
        pacienteId: 2,
    );

    expect(AccesoExpediente::query()->pendientesDeRevision()->count())->toBe(1);
})->note('Es la cola de trabajo del oficial de privacidad; la ventana de revisión es de 72 horas.');

it('permite marcar como revisado sin alterar el hecho registrado', function (): void {
    $usuario = usuarioAutenticado();

    BitacoraDeLectura::registrarAccesoDeEmergencia(
        recursoTipo: 'App\\Models\\Paciente',
        motivo: MotivoBreakTheGlass::Otro,
        motivoTexto: 'Solicitud de auditoría interna del caso 2026-114.',
        recursoId: 3,
        pacienteId: 3,
    );

    $acceso = AccesoExpediente::query()->firstOrFail();
    $textoOriginal = $acceso->motivo_texto;

    $acceso->marcarRevisado($usuario);

    $revisado = AccesoExpediente::query()->firstOrFail();

    expect($revisado->revisado_en)->not->toBeNull()
        ->and($revisado->revisado_por)->toBe($usuario->id)
        ->and($revisado->motivo_texto)->toBe($textoOriginal)
        ->and(AccesoExpediente::query()->pendientesDeRevision()->count())->toBe(0);
})->note('La revisión toca dos columnas propias; el hecho registrado no se altera nunca.');
