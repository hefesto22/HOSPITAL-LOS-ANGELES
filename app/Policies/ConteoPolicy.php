<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conteo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Conteo.
 *
 * ⚠️ Sin esta clase, Filament **deja pasar a todo el mundo**: cuando no
 * existe una policy para el modelo y el panel no está en modo estricto,
 * `get_authorization_response()` termina en `Response::allow()`.
 *
 * Escrita a mano y NO generada por Shield: `config/filament-shield.php`
 * tiene `policies.generate => false` justamente para que un
 * `shield:generate` no la pise.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `Update:Conteo` ES CONTAR Y ES CERRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Shield genera once acciones fijas y ninguna se llama «contar» ni
 * «cerrar». Las dos viajan en `Update`, igual que la revisión de una
 * recepción — y por la misma razón eso no afloja el control: el CHECK
 * `conteos_cuatro_ojos` impide en la base que cierre quien abrió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ALCANCE POR ALMACÉN NO VA ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que bodega no pueda tocar la farmacia se resuelve en
 * `getEloquentQuery()` con `AlmacenesDelUsuario::filtrar()`, no fila por
 * fila desde acá: preguntarlo en la policy daría el mismo resultado y
 * veinticinco consultas por página de tabla (§13.2). Y filtrar la
 * consulta además tapa el agujero de abrir por URL el registro de otro,
 * porque para esa consulta el registro no existe (§9.L5).
 */
class ConteoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Conteo');
    }

    public function view(AuthUser $authUser, Conteo $conteo): bool
    {
        return $authUser->can('View:Conteo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Conteo');
    }

    /**
     * Contar, cerrar y anular. Solo mientras el conteo esté abierto: una
     * vez cerrado o anulado, la base rechaza cualquier cambio con un
     * trigger, así que dejar el botón visible sería prometer algo que el
     * sistema no puede cumplir.
     */
    public function update(AuthUser $authUser, Conteo $conteo): bool
    {
        return $conteo->estaAbierto() && $authUser->can('Update:Conteo');
    }

    /**
     * Un conteo no se borra: se anula con motivo, y anulado queda
     * visible. Borrarlo dejaría sin explicación la tarde que dos personas
     * pasaron contando el estante.
     */
    public function delete(AuthUser $authUser, Conteo $conteo): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Conteo $conteo): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Conteo $conteo): bool
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

    /**
     * Replicar un conteo no significa nada: lo que vale de un conteo es
     * el momento en que se hizo y quién lo hizo.
     */
    public function replicate(AuthUser $authUser, Conteo $conteo): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
