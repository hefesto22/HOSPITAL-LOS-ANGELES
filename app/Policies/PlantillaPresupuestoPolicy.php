<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlantillaPresupuesto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de PlantillaPresupuesto (ADR-0008).
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
class PlantillaPresupuestoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PlantillaPresupuesto');
    }

    public function view(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return $authUser->can('View:PlantillaPresupuesto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PlantillaPresupuesto');
    }

    public function update(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return $authUser->can('Update:PlantillaPresupuesto');
    }

    /**
     * Acá no se borra.
     *
     * Una plantilla se retira con vigencia y un presupuesto se anula con
     * motivo: los dos tienen que seguir explicando el papel que alguien
     * firmó hace ocho meses.
     */
    public function delete(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return $authUser->can('Restore:PlantillaPresupuesto');
    }

    public function forceDelete(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PlantillaPresupuesto');
    }

    public function replicate(AuthUser $authUser, PlantillaPresupuesto $plantilla): bool
    {
        return $authUser->can('Replicate:PlantillaPresupuesto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PlantillaPresupuesto');
    }
}
