<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Almacen;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Almacen.
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
 * Un almacén no se borra: se le pone fecha de fin de vigencia. Borrarlo
 * dejaría existencias y movimientos de kardex apuntando a un lugar que ya
 * no existe.
 */
class AlmacenPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Almacen');
    }

    public function view(AuthUser $authUser, Almacen $almacen): bool
    {
        return $authUser->can('View:Almacen');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Almacen');
    }

    public function update(AuthUser $authUser, Almacen $almacen): bool
    {
        return $authUser->can('Update:Almacen');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Almacen $almacen): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Almacen $almacen): bool
    {
        return $authUser->can('Restore:Almacen');
    }

    public function forceDelete(AuthUser $authUser, Almacen $almacen): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Almacen');
    }

    public function replicate(AuthUser $authUser, Almacen $almacen): bool
    {
        return $authUser->can('Replicate:Almacen');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Almacen');
    }
}
