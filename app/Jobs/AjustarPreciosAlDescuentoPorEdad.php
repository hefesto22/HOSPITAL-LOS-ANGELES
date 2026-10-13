<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ReajustadorDePrecios;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cambió un descuento por edad: los precios de farmacia se acomodan
 * (Mauricio, 13-oct-2026).
 *
 * Va a la cola y no en la pantalla del que cargó el porcentaje: recalcula
 * todos los productos con un mínimo guardado, y eso no se hace dentro del
 * request de nadie (§13.3).
 *
 * Se manda DESPUÉS de que el porcentaje nuevo quedó guardado
 * (`afterCommit`): corriendo antes, leería la ley vieja y no cambiaría
 * nada. Correr dos veces no hace daño: lo que ya cuadra no se toca.
 *
 * Si el porcentaje rige más adelante, el que lo aplica ese día es
 * `precios:seguir-el-descuento`, a las 00:10.
 */
final class AjustarPreciosAlDescuentoPorEdad implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(ReajustadorDePrecios $reajustador): void
    {
        $reajustador->alDescuentoPorEdad(now());
    }
}
