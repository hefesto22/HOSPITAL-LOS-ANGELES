<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * El id de quien está haciendo esto, ya normalizado a entero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE USA `auth()->id()` DIRECTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Laravel declara `Auth::id()` como `int|string|null`, porque admite
 * llaves de texto —UUID, ULID— en la tabla de usuarios. Acá `users.id`
 * es `bigint`, así que ese tipo suelto produce dos problemas distintos:
 *
 *   · asignarlo a una columna declarada `int|null` es un error de tipo,
 *     y aparece en cada servicio que estampa quién hizo algo;
 *   · **y el peor**: compararlo con `===` contra un `int` nunca da
 *     verdadero si llegara como texto. El control de cuatro ojos de las
 *     entradas de compra es exactamente esa comparación, así que un id
 *     en string lo dejaría pasando siempre — en silencio.
 *
 * La conversión vive en un solo lugar. Si algún día el proyecto se
 * pasara a llaves de texto, se cambia acá y el analizador señala uno por
 * uno los lugares que hay que revisar.
 */
final class UsuarioAutenticado
{
    public static function id(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }
}
