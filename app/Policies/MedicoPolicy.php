<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Medico;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Medico.
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
class MedicoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Medico');
    }

    public function view(AuthUser $authUser, Medico $medico): bool
    {
        return $authUser->can('View:Medico');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Medico');
    }

    public function update(AuthUser $authUser, Medico $medico): bool
    {
        return $authUser->can('Update:Medico');
    }

    public function delete(AuthUser $authUser, Medico $medico): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Medico $medico): bool
    {
        return $authUser->can('Restore:Medico');
    }

    public function forceDelete(AuthUser $authUser, Medico $medico): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Medico');
    }

    public function replicate(AuthUser $authUser, Medico $medico): bool
    {
        return $authUser->can('Replicate:Medico');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Medico');
    }
}
