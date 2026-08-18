<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Género — dato ADMINISTRATIVO y de trato.
 *
 * Es opcional y NO se usa para nada clínico. Sirve para documentos, para
 * cómo se dirige el personal al paciente y para reportería demográfica.
 *
 * Los rangos de laboratorio, las dosis y los protocolos usan
 * `SexoBiologico`. Mezclarlos es el error que este par de enums existe
 * para impedir.
 */
enum Genero: string
{
    case Masculino = 'masculino';
    case Femenino = 'femenino';
    case NoBinario = 'no_binario';
    case Otro = 'otro';
    case NoDeclara = 'no_declara';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Masculino => 'Masculino',
            self::Femenino  => 'Femenino',
            self::NoBinario => 'No binario',
            self::Otro      => 'Otro',
            self::NoDeclara => 'Prefiere no declararlo',
        };
    }
}
