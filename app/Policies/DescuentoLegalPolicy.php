<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DescuentoLegal;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de DescuentoLegal.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`. Los
 * permisos pueden estar perfectamente sembrados y no servir de nada.
 *
 * Los nombres son los que genera Shield con `separator: ':'` y
 * `case: 'pascal'` (ver `config/filament-shield.php`). Escribirlos en
 * snake_case acá los deja sin casar y el permiso nunca se concede.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NI SE EDITA NI SE BORRA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cambiar un porcentaje de ley es cerrar el vigente y abrir uno nuevo
 * con fecha. Un `UPDATE` sobre la fila vigente borraría la respuesta a
 * «cuánto se descontaba el día del servicio», que es exactamente lo que
 * hay que poder mostrar cuando llega una denuncia a la línea 115 por una
 * factura de hace dos años.
 *
 * Y un `DELETE` es peor: las facturas ya emitidas con ese porcentaje
 * quedarían sin explicación.
 */
class DescuentoLegalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DescuentoLegal');
    }

    public function view(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return $authUser->can('View:DescuentoLegal');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DescuentoLegal');
    }

    /**
     * Ver el encabezado: no se edita, se carga uno nuevo con fecha.
     */
    public function update(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return false;
    }

    /**
     * Ver el encabezado: no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return $authUser->can('Restore:DescuentoLegal');
    }

    public function forceDelete(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DescuentoLegal');
    }

    public function replicate(AuthUser $authUser, DescuentoLegal $descuentoLegal): bool
    {
        return $authUser->can('Replicate:DescuentoLegal');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DescuentoLegal');
    }
}
