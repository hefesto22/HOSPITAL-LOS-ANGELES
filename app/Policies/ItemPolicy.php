<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Item.
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
 * Un ítem no se borra: se retira poniéndole fecha de fin de vigencia.
 * Borrarlo dejaría cargos apuntando a un ítem inexistente y una factura
 * que ya no se puede reimprimir.
 */
class ItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Item');
    }

    public function view(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('View:Item');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Item');
    }

    public function update(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Update:Item');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Item $item): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Restore:Item');
    }

    public function forceDelete(AuthUser $authUser, Item $item): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Item');
    }

    public function replicate(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Replicate:Item');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Item');
    }
}
