<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CategoriaItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de las categorías del catálogo.
 *
 * Es estructura del tarifario, no operación diaria: crear y renombrar
 * categorías reordena cómo se leen los ingresos por área, así que solo
 * dirección las toca. Todos los demás las ven, porque el selector de
 * categoría aparece en la ficha de cualquier ítem.
 *
 * Una categoría no se borra: se le cierra la vigencia (§8.4).
 */
class CategoriaItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CategoriaItem');
    }

    public function view(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return $authUser->can('View:CategoriaItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CategoriaItem');
    }

    public function update(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return $authUser->can('Update:CategoriaItem');
    }

    public function delete(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return $authUser->can('Restore:CategoriaItem');
    }

    public function forceDelete(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CategoriaItem');
    }

    public function replicate(AuthUser $authUser, CategoriaItem $categoriaItem): bool
    {
        return $authUser->can('Replicate:CategoriaItem');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CategoriaItem');
    }
}
