<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Producto — la ficha de farmacia.
 *
 * ⚠️ Existe SEPARADA de `ItemPolicy` a propósito, y es la mitad del
 * sentido de que `Producto` sea un modelo propio: Shield nombra los
 * permisos por modelo, así que farmacia puede recibir `Create:Producto`
 * sin recibir `Create:Item`. Con un solo modelo, quien puede dar de alta
 * una ampolla podría también cambiarle el precio a una cesárea.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * hay policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`.
 *
 * Un producto no se borra: se retira con fecha de fin de vigencia. Su
 * kardex tiene que seguir siendo consultable veinte años.
 */
class ProductoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Producto');
    }

    public function view(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('View:Producto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Producto');
    }

    public function update(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Update:Producto');
    }

    /**
     * Acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Producto $producto): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Restore:Producto');
    }

    public function forceDelete(AuthUser $authUser, Producto $producto): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Producto');
    }

    public function replicate(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Replicate:Producto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Producto');
    }
}
