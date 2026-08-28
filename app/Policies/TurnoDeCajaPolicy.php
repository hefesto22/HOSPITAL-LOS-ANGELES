<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TurnoDeCaja;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización del turno de caja.
 *
 * `create` es abrir el turno y `update` es cerrarlo con su arqueo. Que
 * el turno sea SUYO no lo decide el permiso: lo decide el servicio, que
 * solo encuentra el turno abierto de quien está operando.
 *
 * 🔴 `php artisan shield:generate --all --option=permissions`. Nunca
 * `--all` a secas.
 */
class TurnoDeCajaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TurnoDeCaja');
    }

    public function view(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
    {
        return $authUser->can('View:TurnoDeCaja');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TurnoDeCaja');
    }

    public function update(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
    {
        return $authUser->can('Update:TurnoDeCaja');
    }

    /**
     * 🔴 Un arqueo no se borra. Es el papel que dice cuánto había en la
     * gaveta y quién lo contó.
     */
    public function delete(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
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

    public function replicate(AuthUser $authUser, TurnoDeCaja $turnoDeCaja): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
