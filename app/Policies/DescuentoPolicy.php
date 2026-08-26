<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Descuento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Descuento.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`. Los
 * permisos pueden estar perfectamente sembrados y no servir de nada.
 *
 * Los nombres son los que genera Shield con `separator: ':'` y
 * `case: 'pascal'` (ver `config/filament-shield.php`). Escribirlos en
 * snake_case acá los deja sin casar y el permiso nunca se concede.
 *
 * El descuento no se edita ni se borra: se cierra el vigente y se crea
 * uno nuevo con fecha. Un `UPDATE` sobre la fila vigente borraría la
 * única respuesta que importa — por qué a ese paciente se le descontó
 * eso en marzo. Y renombrarlo le sacaría el descuento a todos los ítems
 * que lo tenían marcado, porque el motor de cargos lo busca por nombre.
 */
class DescuentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Descuento');
    }

    public function view(AuthUser $authUser, Descuento $descuento): bool
    {
        return $authUser->can('View:Descuento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Descuento');
    }

    /**
     * Ver el encabezado: no se edita, se crea uno nuevo con fecha.
     */
    public function update(AuthUser $authUser, Descuento $descuento): bool
    {
        return false;
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Descuento $descuento): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Descuento $descuento): bool
    {
        return $authUser->can('Restore:Descuento');
    }

    public function forceDelete(AuthUser $authUser, Descuento $descuento): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Descuento');
    }

    public function replicate(AuthUser $authUser, Descuento $descuento): bool
    {
        return $authUser->can('Replicate:Descuento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Descuento');
    }
}
