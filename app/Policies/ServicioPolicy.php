<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Servicio;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Servicio.
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
 * Un servicio no se borra: se cierra con fecha de fin de vigencia.
 * Borrarlo dejaría almacenes y atenciones colgando de la nada.
 */
class ServicioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Servicio');
    }

    public function view(AuthUser $authUser, Servicio $servicio): bool
    {
        return $authUser->can('View:Servicio');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Servicio');
    }

    public function update(AuthUser $authUser, Servicio $servicio): bool
    {
        return $authUser->can('Update:Servicio');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Servicio $servicio): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Servicio $servicio): bool
    {
        return $authUser->can('Restore:Servicio');
    }

    public function forceDelete(AuthUser $authUser, Servicio $servicio): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Servicio');
    }

    public function replicate(AuthUser $authUser, Servicio $servicio): bool
    {
        return $authUser->can('Replicate:Servicio');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Servicio');
    }
}
