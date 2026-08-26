<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cuenta;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de la cuenta del paciente.
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
 * `Update:Cuenta` es agregar cargos, congelar y cerrar. Shield genera once
 * acciones fijas y ninguna se llama «cobrar».
 *
 * ⚠️ Lo que esta policy NO resuelve es el §9.L13: **el costo y el margen
 * son un permiso, no una columna.** `Ver:Costo` se chequea aparte, en la
 * tabla, en el infolist, en el export y en el PDF. Los cuatro.
 */
class CuentaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cuenta');
    }

    public function view(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return $authUser->can('View:Cuenta');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cuenta');
    }

    /**
     * Solo mientras la cuenta esté viva. Una cerrada no admite cargos —
     * lo que sí admite el sistema es registrar el hecho tardío en otra
     * parte, que es la diferencia entre ordenar el egreso y obligar a
     * mentir (§8.6.3).
     */
    public function update(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return $cuenta->estaViva() && $authUser->can('Update:Cuenta');
    }

    /**
     * Una cuenta no se borra. Se anula con motivo, y sus cargos quedan.
     * Borrar una cuenta es borrar la explicación de una factura.
     */
    public function delete(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Cuenta $cuenta): bool
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

    public function replicate(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
