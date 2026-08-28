<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Abono;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización del abono a cuenta.
 *
 * ⚠️ Sin esta clase, Filament deja pasar a todo el mundo. Acá se recibe
 * PLATA: es el último lugar donde eso sería aceptable.
 *
 * Los nombres son los que genera Shield con `separator: ':'` y
 * `case: 'pascal'`.
 *
 * 🔴 Los permisos se crean con
 * `php artisan shield:generate --all --option=permissions`.
 * **NUNCA con `--all` a secas**: reescribe TODAS las policies.
 */
class AbonoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Abono');
    }

    public function view(AuthUser $authUser, Abono $abono): bool
    {
        return $authUser->can('View:Abono');
    }

    /**
     * Recibir plata.
     */
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Abono');
    }

    /**
     * Anular un recibo mal hecho. Es lo único que se puede cambiar de un
     * abono, y solo con el turno abierto — eso lo cuida el servicio.
     */
    public function update(AuthUser $authUser, Abono $abono): bool
    {
        return $authUser->can('Update:Abono');
    }

    /**
     * 🔴 Un recibo NUNCA se borra.
     *
     * Se imprimió, la familia lo tiene y el arqueo de esa noche lo contó.
     * Borrarlo dejaría un turno cuadrado contra un recibo que ya no
     * existe: la definición de un faltante que nadie puede explicar.
     */
    public function delete(AuthUser $authUser, Abono $abono): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Abono $abono): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Abono $abono): bool
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

    public function replicate(AuthUser $authUser, Abono $abono): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
