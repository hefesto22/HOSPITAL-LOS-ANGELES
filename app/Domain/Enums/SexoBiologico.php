<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Sexo biológico — dato CLÍNICO, no administrativo.
 *
 * ⚠️ Está separado de `Genero` a propósito, y la razón es de seguridad
 * del paciente, no de corrección política:
 *
 *   Este campo determina los **rangos de referencia de laboratorio**
 *   (hemoglobina, hematocrito, creatinina, ferritina), el cálculo de dosis
 *   y varios protocolos clínicos. Si a un paciente trans con género
 *   femenino el laboratorio le aplica rangos femeninos cuando su
 *   fisiología es masculina, una hemoglobina de 13.5 se informa como
 *   normal cuando en realidad es anemia — y nadie la investiga.
 *
 * `Indeterminado` existe porque los recién nacidos con genitales ambiguos
 * y los pacientes intersexuales son reales, y forzarlos a uno de los dos
 * valores mete un dato falso en el expediente.
 *
 * `Desconocido` es distinto de indeterminado: es el NN de emergencia
 * todavía sin evaluar. Confundirlos borra información (§8.2-4).
 */
enum SexoBiologico: string
{
    case Masculino = 'masculino';
    case Femenino = 'femenino';
    case Indeterminado = 'indeterminado';
    case Desconocido = 'desconocido';

    /**
     * ¿Sirve para elegir rangos de referencia de laboratorio?
     *
     * Con indeterminado o desconocido, el laboratorio debe informar el
     * resultado SIN rango en vez de inventar uno.
     */
    public function defineRangosDeReferencia(): bool
    {
        return match ($this) {
            self::Masculino, self::Femenino => true,
            default                         => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Masculino     => 'Masculino',
            self::Femenino      => 'Femenino',
            self::Indeterminado => 'Indeterminado',
            self::Desconocido   => 'Desconocido / sin evaluar',
        };
    }

    public function abreviatura(): string
    {
        return match ($this) {
            self::Masculino     => 'M',
            self::Femenino      => 'F',
            self::Indeterminado => 'I',
            self::Desconocido   => '?',
        };
    }
}
