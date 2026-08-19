<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MargenObjetivo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de MargenObjetivo.
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
 * El margen no se edita ni se borra: se cierra el vigente y se abre uno
 * nuevo con fecha. Un `UPDATE` sobre la fila vigente borraría la única
 * respuesta que importa — por qué ese producto se vendía a ese precio en
 * marzo.
 */
class MargenObjetivoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MargenObjetivo');
    }

    public function view(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return $authUser->can('View:MargenObjetivo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MargenObjetivo');
    }

    /**
     * Ver el encabezado: el margen no se edita, se fija uno nuevo.
     */
    public function update(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return false;
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return $authUser->can('Restore:MargenObjetivo');
    }

    public function forceDelete(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MargenObjetivo');
    }

    public function replicate(AuthUser $authUser, MargenObjetivo $margenObjetivo): bool
    {
        return $authUser->can('Replicate:MargenObjetivo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MargenObjetivo');
    }
}
