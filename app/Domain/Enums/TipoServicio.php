<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipo de servicio o área de la sede (§8.1).
 *
 * El tipo NO es cosmético: determina si el área admite encuentros de
 * hospitalización, si tiene camas, y si su consumo se imputa a la cuenta
 * del paciente o al centro de costo del área.
 */
enum TipoServicio: string
{
    case Emergencia = 'emergencia';
    case ConsultaExterna = 'consulta_externa';
    case Hospitalizacion = 'hospitalizacion';
    case Quirofano = 'quirofano';
    case Laboratorio = 'laboratorio';
    case Imagenes = 'imagenes';
    case Farmacia = 'farmacia';
    case Bodega = 'bodega';
    case Administrativo = 'administrativo';

    /**
     * ¿Este servicio tiene camas y entra en el censo?
     */
    public function tieneCamas(): bool
    {
        return match ($this) {
            self::Hospitalizacion, self::Emergencia => true,
            default                                 => false,
        };
    }

    /**
     * ¿Se atienden pacientes acá? Bodega y administración no.
     */
    public function esAsistencial(): bool
    {
        return match ($this) {
            self::Bodega, self::Administrativo => false,
            default                            => true,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emergencia      => 'Emergencia',
            self::ConsultaExterna => 'Consulta externa',
            self::Hospitalizacion => 'Hospitalización',
            self::Quirofano       => 'Quirófano',
            self::Laboratorio     => 'Laboratorio',
            self::Imagenes        => 'Imágenes / Rayos X',
            self::Farmacia        => 'Farmacia',
            self::Bodega          => 'Bodega',
            self::Administrativo  => 'Administrativo',
        };
    }
}
