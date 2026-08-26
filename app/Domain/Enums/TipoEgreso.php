<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Cómo terminó el encuentro (§9.K9).
 *
 * Tipificarlo no es burocracia: la fuga y el alta voluntaria tienen
 * consecuencias económicas y legales distintas, y la defunción tiene
 * flujo propio —certificado, cuerpo, pertenencias— y bloquea cargos
 * nuevos salvo los autorizados.
 */
enum TipoEgreso: string
{
    case Domicilio = 'domicilio';
    case Traslado = 'traslado';
    case AltaVoluntaria = 'alta_voluntaria';
    case Fuga = 'fuga';
    case Defuncion = 'defuncion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Domicilio      => 'A domicilio',
            self::Traslado       => 'Traslado a otro centro',
            self::AltaVoluntaria => 'Alta voluntaria',
            self::Fuga           => 'Fuga',
            self::Defuncion      => 'Defunción',
        };
    }

    /**
     * ¿Exige firma del paciente o del responsable?
     */
    public function exigeFirma(): bool
    {
        return $this === self::AltaVoluntaria;
    }

    /**
     * La fuga NO es un dato faltante: es un evento registrado, con
     * responsable y saldo (§9.H12). Dejarla como una cuenta abierta
     * eterna ensucia toda la cartera por cobrar.
     */
    public function dejaSaldoIncobrable(): bool
    {
        return $this === self::Fuga;
    }

    public function color(): string
    {
        return match ($this) {
            self::Domicilio      => 'success',
            self::Traslado       => 'info',
            self::AltaVoluntaria => 'warning',
            self::Fuga           => 'danger',
            self::Defuncion      => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }
}
