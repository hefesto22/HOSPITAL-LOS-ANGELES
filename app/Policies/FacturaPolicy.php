<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Factura;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Factura — módulo fiscal.
 *
 * 🔴 Acá NADA se borra. Un rango de CAI y una factura emitida son el
 * rastro que el SAR audita: se desactiva el rango y se anula la factura,
 * con motivo, y las dos filas se quedan donde están.
 *
 * 🔴 Los permisos se crean con
 * `php artisan shield:generate --all --option=permissions`.
 * **NUNCA con `--all` a secas**: reescribe TODAS las policies.
 */
class FacturaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Factura');
    }

    public function view(AuthUser $authUser, Factura $Factura): bool
    {
        return $authUser->can('View:Factura');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Factura');
    }

    public function update(AuthUser $authUser, Factura $Factura): bool
    {
        return $authUser->can('Update:Factura');
    }

    public function delete(AuthUser $authUser, Factura $Factura): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Factura $Factura): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Factura $Factura): bool
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

    public function replicate(AuthUser $authUser, Factura $Factura): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
