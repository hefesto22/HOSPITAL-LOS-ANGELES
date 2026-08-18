<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\MotivoBreakTheGlass;
use App\Models\AccesoExpediente;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Única puerta de escritura de la bitácora de lectura (ADR-0004, §9.L6).
 *
 * ⚠️ DECISIÓN CENTRAL: ESTE MÉTODO NUNCA LANZA EXCEPCIÓN.
 *
 * Es una decisión incómoda y la escribo explícita para que nadie la
 * "arregle" después:
 *
 *   Si el registro de lectura falla —partición faltante, base saturada,
 *   disco lleno— y dejáramos que la excepción suba, el médico no puede
 *   abrir el expediente. **El mecanismo que existe para proteger al
 *   paciente se convertiría en el que le impide ser atendido** (§1.5).
 *
 * Entre perder una fila de bitácora y bloquear una atención a las 3 de la
 * mañana, se pierde la fila — pero se pierde A GRITOS: el fallo se reporta
 * al canal de errores y a Sentry, y el monitoreo de esa señal es
 * responsabilidad operativa (§13.7).
 *
 * Las tres defensas que hacen que ese caso sea casi imposible:
 *   1. partición DEFAULT en la tabla, que acepta cualquier fecha
 *   2. comando `sihla:crear-particiones` que va por delante del calendario
 *   3. es un solo INSERT, sin joins ni transacción
 */
final class BitacoraDeLectura
{
    /**
     * Registra un acceso de lectura.
     *
     * @param string $recursoTipo Clase del recurso leído (Paciente::class, Resultado::class…)
     * @param int|null $recursoId Id del recurso, si aplica
     * @param int|null $pacienteId Paciente afectado — lo que se consulta en un reclamo de privacidad
     */
    public static function registrar(
        string $recursoTipo,
        ?int $recursoId = null,
        ?int $pacienteId = null,
        AccionDeLectura $accion = AccionDeLectura::Ver,
        ?MotivoBreakTheGlass $motivo = null,
        ?string $motivoTexto = null,
    ): void {
        try {
            $usuario = Auth::user();

            // Sin usuario no hay a quién atribuir la lectura. Ocurre en
            // consola y en jobs; ahí el acceso no es de una persona.
            if (! $usuario instanceof User) {
                return;
            }

            $esBreakTheGlass = $motivo !== null;

            AccesoExpediente::query()->create([
                'sede_id'            => ContextoSede::actualId() ?? $usuario->getAttribute('sede_id'),
                'user_id'            => $usuario->getKey(),
                'paciente_id'        => $pacienteId,
                'recurso_tipo'       => $recursoTipo,
                'recurso_id'         => $recursoId,
                'accion'             => $accion,
                'motivo'             => $motivo,
                'motivo_texto'       => $motivoTexto,
                'es_break_the_glass' => $esBreakTheGlass,
                'ip'                 => Request::ip(),
                'terminal'           => mb_substr((string) Request::userAgent(), 0, 255),
                'ocurrido_en'        => now(),
            ]);
        } catch (Throwable $e) {
            // Ver el bloque de arriba: se reporta, no se propaga.
            report($e);
        }
    }

    /**
     * Acceso de emergencia: se permite SIEMPRE, se registra SIEMPRE.
     *
     * El motivo tipificado y el texto son obligatorios, y el CHECK de la
     * base exige al menos 10 caracteres de texto: un acceso de emergencia
     * sin justificar no es un acceso de emergencia, es un acceso.
     */
    public static function registrarAccesoDeEmergencia(
        string $recursoTipo,
        MotivoBreakTheGlass $motivo,
        string $motivoTexto,
        ?int $recursoId = null,
        ?int $pacienteId = null,
    ): void {
        self::registrar(
            recursoTipo: $recursoTipo,
            recursoId: $recursoId,
            pacienteId: $pacienteId,
            accion: AccionDeLectura::Ver,
            motivo: $motivo,
            motivoTexto: $motivoTexto,
        );
    }
}
