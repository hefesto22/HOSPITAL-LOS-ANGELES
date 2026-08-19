<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Convenio;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Convenio.
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
 * Un convenio no se borra: se le pone fecha de fin de vigencia. Borrarlo
 * dejaría cuentas y facturas apuntando a un pagador inexistente, y una
 * factura que no se puede reimprimir es un problema ante el SAR.
 */
class ConvenioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Convenio');
    }

    public function view(AuthUser $authUser, Convenio $convenio): bool
    {
        return $authUser->can('View:Convenio');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Convenio');
    }

    public function update(AuthUser $authUser, Convenio $convenio): bool
    {
        return $authUser->can('Update:Convenio');
    }

    /**
     * Ver el encabezado: acá no se borra, se cierra la vigencia.
     */
    public function delete(AuthUser $authUser, Convenio $convenio): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Convenio $convenio): bool
    {
        return $authUser->can('Restore:Convenio');
    }

    public function forceDelete(AuthUser $authUser, Convenio $convenio): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Convenio');
    }

    public function replicate(AuthUser $authUser, Convenio $convenio): bool
    {
        return $authUser->can('Replicate:Convenio');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Convenio');
    }
}
