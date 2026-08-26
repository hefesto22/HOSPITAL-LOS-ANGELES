<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El ciclo de vida del encuentro (§8.3).
 *
 *   abierto → en_atencion → alta_medica → alta_administrativa → cerrado
 *
 * Más `anulado`, que no es un estado del ciclo sino su interrupción.
 *
 * Los tres tiempos del egreso son columnas separadas y obligatorias
 * (§9.K8); estos estados son su reflejo consultable.
 */
enum EstadoEncuentro: string
{
    case Abierto = 'abierto';
    case EnAtencion = 'en_atencion';
    case AltaMedica = 'alta_medica';
    case AltaAdministrativa = 'alta_administrativa';
    case Cerrado = 'cerrado';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto            => 'Abierto',
            self::EnAtencion         => 'En atención',
            self::AltaMedica         => 'Alta médica',
            self::AltaAdministrativa => 'Alta administrativa',
            self::Cerrado            => 'Cerrado',
            self::Anulado            => 'Anulado',
        };
    }

    /**
     * ¿Sigue admitiendo cargos?
     *
     * 🔴 Ojo con la respuesta: `alta_medica` SÍ admite. §8.6.3 es
     * explícito — el cargo tardío siempre debe poder registrarse. Un
     * sistema que rechaza la transfusión de las 23:50 porque la cuenta
     * cerró a las 23:00 genera un expediente falso.
     *
     * Lo que se cierra no es la puerta: es la ventana sin autorización.
     */
    public function admiteCargos(): bool
    {
        return match ($this) {
            self::Abierto, self::EnAtencion, self::AltaMedica, self::AltaAdministrativa => true,
            self::Cerrado, self::Anulado                                                => false,
        };
    }

    public function estaVivo(): bool
    {
        return match ($this) {
            self::Abierto, self::EnAtencion, self::AltaMedica => true,
            default                                           => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function vivos(): array
    {
        return [self::Abierto, self::EnAtencion, self::AltaMedica];
    }

    /**
     * @return list<string>
     */
    public static function valoresVivos(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::vivos());
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierto            => 'success',
            self::EnAtencion         => 'info',
            self::AltaMedica         => 'warning',
            self::AltaAdministrativa => 'primary',
            self::Cerrado            => 'gray',
            self::Anulado            => 'danger',
        };
    }
}
