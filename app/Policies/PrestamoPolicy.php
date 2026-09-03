<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Prestamo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Prestamo.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo, `get_authorization_response()`
 * termina en `Response::allow()`. Los permisos pueden estar sembrados y
 * no servir de nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN PRÉSTAMO NO SE EDITA NI SE BORRA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Registrarlo movió el kardex. Editar la cantidad después dejaría el
 * documento diciendo una cosa y la existencia otra, sin que nada avise —
 * y borrarlo dejaría una entrada de inventario sin dueño, que es
 * exactamente el agujero que este módulo vino a tapar.
 *
 * Se salda con sus acciones —devolver o marcar pagado—, que sí escriben
 * el movimiento que corresponde.
 */
class PrestamoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Prestamo');
    }

    public function view(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return $authUser->can('View:Prestamo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Prestamo');
    }

    /**
     * ⚠️ `update` es lo que habilita SALDAR —«Devolver» y «Marcar
     * pagado»—, no editar el documento.
     *
     * El formulario de edición no existe: `PrestamoResource::canEdit()`
     * devuelve false. La diferencia importa — si esta policy negara
     * `update`, las dos acciones quedarían invisibles para todo el mundo
     * y la deuda no se podría cerrar nunca.
     */
    public function update(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return $authUser->can('Update:Prestamo');
    }

    public function delete(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return $authUser->can('Restore:Prestamo');
    }

    public function forceDelete(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Prestamo');
    }

    public function replicate(AuthUser $authUser, Prestamo $prestamo): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
