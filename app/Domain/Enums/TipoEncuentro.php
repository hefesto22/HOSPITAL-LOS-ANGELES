<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué clase de atención es. No es cosmético: define quién puede cargar
 * qué, cómo se numera y qué reglas de cuenta aplican.
 */
enum TipoEncuentro: string
{
    case Ambulatorio = 'ambulatorio';
    case Emergencia = 'emergencia';
    case Hospitalizacion = 'hospitalizacion';
    case Cirugia = 'cirugia';
    case Externo = 'externo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ambulatorio     => 'Consulta externa',
            self::Emergencia      => 'Emergencia',
            self::Hospitalizacion => 'Hospitalización',
            self::Cirugia         => 'Cirugía',
            self::Externo         => 'Referido / externo',
        };
    }

    /**
     * ¿Ocupa cama y por lo tanto censa?
     *
     * Hoy solo informa a la pantalla; el bloque 11 lo usa para el censo
     * de medianoche. Definirlo acá evita que cada módulo se invente su
     * propia lista (§9.K7).
     */
    public function ocupaCama(): bool
    {
        return $this === self::Hospitalizacion;
    }

    /**
     * ¿Se abre sin poder esperar a la identificación completa?
     *
     * En emergencia entra el NN, y la atención no espera a que alguien
     * encuentre la cédula (§8.2-4).
     */
    public function admiteRegistroIncompleto(): bool
    {
        return $this === self::Emergencia;
    }

    /**
     * El reloj de 24 horas para notificar el ingreso a la aseguradora
     * (§8.6.5). Fuera de emergencia, lo que aplica es la
     * precertificación con 5 días hábiles de anticipación.
     */
    public function exigeNotificacionEn24Horas(): bool
    {
        return $this === self::Emergencia;
    }

    public function icono(): string
    {
        return match ($this) {
            self::Ambulatorio     => 'heroicon-o-user',
            self::Emergencia      => 'heroicon-o-bolt',
            self::Hospitalizacion => 'heroicon-o-home-modern',
            self::Cirugia         => 'heroicon-o-scissors',
            self::Externo         => 'heroicon-o-arrow-right-circle',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ambulatorio     => 'info',
            self::Emergencia      => 'danger',
            self::Hospitalizacion => 'warning',
            self::Cirugia         => 'primary',
            self::Externo         => 'gray',
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
