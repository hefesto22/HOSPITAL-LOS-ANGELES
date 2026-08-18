<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Carbon\CarbonInterface;

/**
 * Estado de archivo de un expediente.
 *
 * No es "abierto/cerrado": un expediente clínico no se cierra nunca
 * mientras el paciente viva. Esto es dónde vive la CARPETA y cuánto falta
 * para poder depurarla, que es un requisito de archivo, no clínico.
 *
 * Los plazos salen de `config('sihla.expediente')` y no están quemados
 * acá: la norma de conservación puede cambiar, y el día que cambie no se
 * puede depender de un despliegue para cumplirla.
 */
enum EstadoExpediente: string
{
    case Activo = 'activo';
    case Pasivo = 'pasivo';
    case Depurable = 'depurable';

    /**
     * Resuelve el estado a partir de la última atención.
     *
     * ⚠️ `Depurable` significa "cumplió el plazo legal de conservación",
     * NO "ya se destruyó". Destruir el expediente físico es un acto humano
     * con acta, y se registra aparte. Un enum que diga "depurado" cuando
     * la carpeta sigue en el estante es una mentira que alguien va a creer.
     */
    public static function resolverPara(
        ?CarbonInterface $ultimaAtencion,
        CarbonInterface $abiertoEl,
        CarbonInterface $hoy,
    ): self {
        $referencia = $ultimaAtencion ?? $abiertoEl;

        $anios = (int) $referencia->diffInYears($hoy);

        $activo = (int) config('sihla.expediente.anios_retencion_activo', 5);
        $pasivo = (int) config('sihla.expediente.anios_retencion_pasivo', 15);

        return match (true) {
            $anios <= $activo           => self::Activo,
            $anios <= $activo + $pasivo => self::Pasivo,
            default                     => self::Depurable,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo    => 'Activo',
            self::Pasivo    => 'Pasivo (archivo)',
            self::Depurable => 'Cumplió plazo de conservación',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Activo    => 'success',
            self::Pasivo    => 'gray',
            self::Depurable => 'warning',
        };
    }
}
