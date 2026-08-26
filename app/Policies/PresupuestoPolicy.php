<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Presupuesto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Presupuesto (ADR-0008).
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`. Los
 * permisos pueden estar perfectamente sembrados y no servir de nada.
 *
 * Los nombres son los que genera Shield con `separator: ':'` y
 * `case: 'pascal'`. En snake_case no casan y el permiso nunca se concede.
 *
 * 🔴 Los permisos se crean con
 * `php artisan shield:generate --all --option=permissions`.
 * **NUNCA con `--all` a secas**: ese comando ignora
 * `policies.generate => false` y reescribe TODAS las policies con su
 * plantilla, devolviéndole a dirección la capacidad de borrar.
 */
class PresupuestoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Presupuesto');
    }

    public function view(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return $authUser->can('View:Presupuesto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Presupuesto');
    }

    public function update(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return $authUser->can('Update:Presupuesto');
    }

    /**
     * Acá no se borra.
     *
     * Una plantilla se retira con vigencia y un presupuesto se anula con
     * motivo: los dos tienen que seguir explicando el papel que alguien
     * firmó hace ocho meses.
     */
    public function delete(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return $authUser->can('Restore:Presupuesto');
    }

    public function forceDelete(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Presupuesto');
    }

    public function replicate(AuthUser $authUser, Presupuesto $presupuesto): bool
    {
        return $authUser->can('Replicate:Presupuesto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Presupuesto');
    }
}
