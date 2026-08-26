<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Encuentro;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Encuentro.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe policy para el modelo, `get_authorization_response()` termina
 * en `Response::allow()`.
 *
 * Escrita a mano y NO generada por Shield. `config/filament-shield.php`
 * tiene `policies.generate => false` para que un `shield:generate` no la
 * pise — y aun así, `shield:generate --all` la pisaría igual, porque esa
 * bandera ignora la config. El comando correcto es
 * `--all --option=permissions` (lección registrada en memoria).
 *
 * `Update:Encuentro` es también dar de alta y cerrar. Shield genera once
 * acciones fijas y ninguna se llama «egresar».
 */
class EncuentroPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Encuentro');
    }

    public function view(AuthUser $authUser, Encuentro $encuentro): bool
    {
        return $authUser->can('View:Encuentro');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Encuentro');
    }

    /**
     * Solo mientras el encuentro esté vivo. Uno cerrado o anulado no
     * cambia: dejar el botón visible sería prometer algo que la base
     * rechaza.
     */
    public function update(AuthUser $authUser, Encuentro $encuentro): bool
    {
        return $encuentro->estaVivo() && $authUser->can('Update:Encuentro');
    }

    /**
     * Un encuentro no se borra nunca. Se anula con motivo y queda a la
     * vista: es la historia de una atención, y de esas no se borra
     * ninguna (§9.0.3).
     */
    public function delete(AuthUser $authUser, Encuentro $encuentro): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Encuentro $encuentro): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Encuentro $encuentro): bool
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

    public function replicate(AuthUser $authUser, Encuentro $encuentro): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
