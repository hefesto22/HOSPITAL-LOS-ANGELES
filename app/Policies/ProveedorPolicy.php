<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proveedor;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Proveedor.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`.
 *
 * Un proveedor no se borra: se desactiva. Borrarlo dejaría entradas de
 * compra apuntando a un proveedor inexistente, y una compra cuyo origen
 * desapareció es un kardex que no se puede explicar.
 */
class ProveedorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Proveedor');
    }

    public function view(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return $authUser->can('View:Proveedor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Proveedor');
    }

    public function update(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return $authUser->can('Update:Proveedor');
    }

    public function delete(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return $authUser->can('Restore:Proveedor');
    }

    public function forceDelete(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Proveedor');
    }

    public function replicate(AuthUser $authUser, Proveedor $proveedor): bool
    {
        return $authUser->can('Replicate:Proveedor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Proveedor');
    }
}
