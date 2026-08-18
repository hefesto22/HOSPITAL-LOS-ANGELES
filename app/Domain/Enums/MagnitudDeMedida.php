<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué mide una unidad.
 *
 * Sirve para agrupar la lista en pantalla y para advertir cuando una
 * conversión se ve rara. **No se usa como restricción dura**, y eso es
 * deliberado: un frasco (conteo) contiene 100 ml (volumen), y esa
 * conversión entre magnitudes distintas es exactamente el caso normal de
 * una farmacia. Prohibirla obligaría a inventar una unidad "frasco de
 * 100 ml" por cada presentación, que es el problema que la tabla de
 * presentaciones existe para resolver.
 */
enum MagnitudDeMedida: string
{
    case Conteo = 'conteo';
    case Volumen = 'volumen';
    case Masa = 'masa';
    case Longitud = 'longitud';
    case Tiempo = 'tiempo';

    /**
     * ¿Tiene sentido una cantidad con decimales?
     *
     * Media ampolla que se descarta es merma; medio mililitro es una
     * dosis. Es el default de la unidad — cada unidad concreta lo puede
     * sobrescribir.
     */
    public function admiteFraccionPorNaturaleza(): bool
    {
        return $this !== self::Conteo;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Conteo   => 'Conteo',
            self::Volumen  => 'Volumen',
            self::Masa     => 'Masa',
            self::Longitud => 'Longitud',
            self::Tiempo   => 'Tiempo',
        };
    }
}
