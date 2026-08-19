<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recepcion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Recepcion.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`.
 *
 * Escrita a mano y NO generada por Shield: `config/filament-shield.php`
 * tiene `policies.generate => false` justamente para que un
 * `shield:generate` no la pise. Ver el comentario de ese archivo.
 */
class RecepcionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Recepcion');
    }

    public function view(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return $authUser->can('View:Recepcion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Recepcion');
    }

    public function update(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return $authUser->can('Update:Recepcion');
    }

    public function delete(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return $authUser->can('Restore:Recepcion');
    }

    public function forceDelete(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Recepcion');
    }

    public function replicate(AuthUser $authUser, Recepcion $recepcion): bool
    {
        return $authUser->can('Replicate:Recepcion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Recepcion');
    }
}
