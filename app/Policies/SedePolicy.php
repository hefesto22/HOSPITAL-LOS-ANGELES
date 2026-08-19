<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sede;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Sede.
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
 * Una sede no se borra: todo lo transaccional cuelga de ella (ADR-0002).
 * Se cierra con fecha de fin de vigencia.
 */
class SedePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Sede');
    }

    public function view(AuthUser $authUser, Sede $sede): bool
    {
        return $authUser->can('View:Sede');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Sede');
    }

    public function update(AuthUser $authUser, Sede $sede): bool
    {
        return $authUser->can('Update:Sede');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Sede $sede): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Sede $sede): bool
    {
        return $authUser->can('Restore:Sede');
    }

    public function forceDelete(AuthUser $authUser, Sede $sede): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Sede');
    }

    public function replicate(AuthUser $authUser, Sede $sede): bool
    {
        return $authUser->can('Replicate:Sede');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Sede');
    }
}
