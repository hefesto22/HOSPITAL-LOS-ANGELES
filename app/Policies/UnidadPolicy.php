<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Unidad;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Unidad.
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
 * Una unidad no se borra: dejaría ítems sin unidad de kardex. La FK lo
 * impide con `restrictOnDelete`, y acá se niega antes de intentarlo.
 */
class UnidadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Unidad');
    }

    public function view(AuthUser $authUser, Unidad $unidad): bool
    {
        return $authUser->can('View:Unidad');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Unidad');
    }

    public function update(AuthUser $authUser, Unidad $unidad): bool
    {
        return $authUser->can('Update:Unidad');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Unidad $unidad): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Unidad $unidad): bool
    {
        return $authUser->can('Restore:Unidad');
    }

    public function forceDelete(AuthUser $authUser, Unidad $unidad): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Unidad');
    }

    public function replicate(AuthUser $authUser, Unidad $unidad): bool
    {
        return $authUser->can('Replicate:Unidad');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Unidad');
    }
}
