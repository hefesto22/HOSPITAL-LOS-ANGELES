<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrincipioActivo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Un principio activo es vocabulario del catálogo, igual que una unidad
 * de medida: quien puede dar de alta un producto puede dar de alta lo que
 * ese producto necesita para existir.
 *
 * ⚠️ Sin esta clase Filament **deja pasar a todo el mundo**: cuando no
 * hay policy para el modelo, `get_authorization_response()` termina en
 * `Response::allow()`. Es el agujero que documenta
 * `PoliticasDelCatalogoTest`, y ya se abrió dos veces.
 *
 * Se apoya en los permisos de `Item` a propósito y no en unos propios:
 * repartirlos por separado permitiría el estado absurdo de poder crear un
 * medicamento pero no poder decir qué lleva adentro.
 */
class PrincipioActivoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Item');
    }

    public function view(AuthUser $authUser, PrincipioActivo $principio): bool
    {
        return $authUser->can('View:Item');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Item');
    }

    public function update(AuthUser $authUser, PrincipioActivo $principio): bool
    {
        return $authUser->can('Update:Item');
    }

    /**
     * Nunca. Un principio activo que veinte productos declaran no se
     * borra: se retira con fecha de fin de vigencia. Borrarlo dejaría a
     * esos veinte sin poder explicar qué llevan, y la llave foránea lo
     * impide igual — pero es mejor no ofrecer el botón que explicar el
     * error después.
     */
    public function delete(AuthUser $authUser, PrincipioActivo $principio): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
