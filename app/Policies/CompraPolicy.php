<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compra;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Compra.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`.
 *
 * Escrita a mano y NO generada por Shield: `config/filament-shield.php`
 * tiene `policies.generate => false` justamente para que un
 * `shield:generate` no la pise. Ver el comentario de ese archivo.
 */
class CompraPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Compra');
    }

    public function view(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('View:Compra');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Compra');
    }

    public function update(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Update:Compra');
    }

    public function delete(AuthUser $authUser, Compra $compra): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Restore:Compra');
    }

    public function forceDelete(AuthUser $authUser, Compra $compra): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Compra');
    }

    public function replicate(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Replicate:Compra');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Compra');
    }
}
