<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ajuste;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Ajuste.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo** (ver
 * `RecepcionPolicy`). Escrita a mano; `shield:generate` no la toca
 * porque `policies.generate => false`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `update` DEVUELVE FALSE Y NO ES UN OLVIDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un ajuste asentado es append-only: un trigger de PostgreSQL rechaza
 * cualquier `UPDATE`. Conceder el permiso mostraría un botón de editar
 * que al guardar reventaría con un error de base — prometer algo que el
 * sistema no puede cumplir es peor que no ofrecerlo.
 *
 * Un ajuste equivocado se corrige con OTRO ajuste, de tipo corrección y
 * con su explicación. Misma regla que la factura y la nota clínica
 * firmada (§9.0.3).
 */
class AjustePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Ajuste');
    }

    public function view(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return $authUser->can('View:Ajuste');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Ajuste');
    }

    public function update(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, Ajuste $ajuste): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
