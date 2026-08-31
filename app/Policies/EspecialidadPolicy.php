<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Especialidad;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Especialidad.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * la autorización termina en `Response::allow()`. Los permisos pueden
 * estar perfectamente sembrados y no servir de nada.
 *
 * Los nombres son los que genera Shield con `separator: ':'` y
 * `case: 'pascal'`. Escribirlos en snake_case los deja sin casar y el
 * permiso nunca se concede.
 *
 * Acá no se borra: se cierra la vigencia.
 */
class EspecialidadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Especialidad');
    }

    public function view(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return $authUser->can('View:Especialidad');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Especialidad');
    }

    public function update(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return $authUser->can('Update:Especialidad');
    }

    public function delete(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return $authUser->can('Restore:Especialidad');
    }

    public function forceDelete(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Especialidad');
    }

    public function replicate(AuthUser $authUser, Especialidad $especialidad): bool
    {
        return $authUser->can('Replicate:Especialidad');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Especialidad');
    }
}
