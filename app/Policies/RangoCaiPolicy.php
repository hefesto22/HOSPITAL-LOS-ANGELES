<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RangoCai;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de RangoCai — módulo fiscal.
 *
 * 🔴 Acá NADA se borra. Un rango de CAI y una factura emitida son el
 * rastro que el SAR audita: se desactiva el rango y se anula la factura,
 * con motivo, y las dos filas se quedan donde están.
 *
 * 🔴 Los permisos se crean con
 * `php artisan shield:generate --all --option=permissions`.
 * **NUNCA con `--all` a secas**: reescribe TODAS las policies.
 */
class RangoCaiPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RangoCai');
    }

    public function view(AuthUser $authUser, RangoCai $RangoCai): bool
    {
        return $authUser->can('View:RangoCai');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RangoCai');
    }

    public function update(AuthUser $authUser, RangoCai $RangoCai): bool
    {
        return $authUser->can('Update:RangoCai');
    }

    public function delete(AuthUser $authUser, RangoCai $RangoCai): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, RangoCai $RangoCai): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, RangoCai $RangoCai): bool
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

    public function replicate(AuthUser $authUser, RangoCai $RangoCai): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
