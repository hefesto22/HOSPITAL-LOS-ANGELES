<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Facades\Auth;

/**
 * Qué secciones del escritorio ve quien entra (Mauricio, 21-oct-2026).
 *
 * Lee `sihla.escritorio.secciones_por_rol`. Con varios roles se suman:
 * el laboratorista que cubre caja los sábados ve lo de los dos.
 *
 * ⚠️ Esto decide qué se MUESTRA. Cada widget vuelve a preguntar por su
 * permiso antes de leer nada: una línea de config no le abre a nadie la
 * plata del hospital.
 */
final class EscritorioDelRol
{
    public const RESUMEN_DEL_DIA = 'resumen_del_dia';

    public const CAJA = 'caja';

    public const LO_QUE_CARGUE = 'lo_que_cargue';

    public const INVENTARIO = 'inventario';

    public static function muestra(string $seccion, ?User $usuario = null): bool
    {
        $usuario ??= self::usuario();

        if (! $usuario instanceof User) {
            return false;
        }

        if ($usuario->hasRole(ShieldUtils::getSuperAdminName())) {
            return true;
        }

        $mapa = config('sihla.escritorio.secciones_por_rol', []);

        if (! is_array($mapa)) {
            return false;
        }

        foreach ($usuario->getRoleNames() as $rol) {
            $secciones = $mapa[$rol] ?? [];

            if (is_array($secciones) && in_array($seccion, $secciones, true)) {
                return true;
            }
        }

        return false;
    }

    private static function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}
