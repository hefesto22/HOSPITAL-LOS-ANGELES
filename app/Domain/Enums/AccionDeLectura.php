<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué clase de lectura se registró.
 *
 * La distinción no es burocrática: **exportar y listar son las acciones
 * peligrosas**, no `ver`.
 *
 * Un empleado que abre un expediente deja una fila. Uno que exporta el
 * listado completo se lleva el hospital entero en un archivo, y en la
 * bitácora se ve igual de inocente si no se distingue la acción. Por eso
 * el §9.L exige permiso aparte para exportar y por eso la revisión de
 * accesos anómalos mira volumen por acción, no total de filas.
 */
enum AccionDeLectura: string
{
    case Ver = 'ver';
    case Listar = 'listar';
    case Buscar = 'buscar';
    case Exportar = 'exportar';
    case Imprimir = 'imprimir';

    /**
     * ¿Esta acción saca datos del sistema?
     *
     * Las que sí requieren revisión más agresiva y permiso propio.
     */
    public function extraeDatos(): bool
    {
        return match ($this) {
            self::Exportar, self::Imprimir => true,
            default                        => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ver      => 'Consultó',
            self::Listar   => 'Listó',
            self::Buscar   => 'Buscó',
            self::Exportar => 'Exportó',
            self::Imprimir => 'Imprimió',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Exportar, self::Imprimir => 'danger',
            self::Listar, self::Buscar     => 'warning',
            self::Ver                      => 'gray',
        };
    }
}
