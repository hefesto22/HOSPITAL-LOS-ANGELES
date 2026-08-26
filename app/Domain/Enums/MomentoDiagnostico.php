<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * ¿Con qué entró, y con qué salió?
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SON EL MISMO, Y LA DIFERENCIA ES INFORMACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un paciente entra por «dolor abdominal» y sale con «apendicitis
 * aguda». Guardar solo el de egreso borra que al ingreso no se sabía —y
 * con eso, la justificación de por qué se pidieron los estudios que se
 * cobraron—. Guardar solo el de ingreso deja el expediente diciendo que
 * el paciente tenía dolor abdominal, que no es un diagnóstico.
 *
 * La aseguradora compara los dos: entre ellos está el trabajo del
 * hospital. Y la notificación epidemiológica va con el de EGRESO, que es
 * el confirmado.
 */
enum MomentoDiagnostico: string
{
    case Ingreso = 'ingreso';
    case Egreso = 'egreso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ingreso => 'Al ingreso',
            self::Egreso  => 'Al egreso',
        };
    }

    /**
     * Al ingreso casi nunca hay certeza; al egreso debería haberla.
     * Es solo el valor que propone el formulario — quien firma decide.
     */
    public function naceConfirmado(): bool
    {
        return $this === self::Egreso;
    }
}
