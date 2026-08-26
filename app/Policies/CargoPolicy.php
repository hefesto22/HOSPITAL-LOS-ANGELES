<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cargo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de Cargo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 `update` DEVUELVE `false` SIEMPRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un cargo asentado no se edita — ni pendiente, ni facturado, ni por
 * dirección. Se corrige asentando una reversa que lo apunta, igual que
 * en el kardex (§9.0.3). Un trigger de la base rechaza el UPDATE de
 * todos modos; que la policy diga lo mismo evita ofrecer un botón que
 * termina en un error de PostgreSQL frente al paciente.
 *
 * La anulación viaja en `Create:Cargo`, porque lo que hace es CREAR la
 * fila de reversa. Quien puede cargar puede corregir lo que cargó — y el
 * motivo obligatorio de diez caracteres es el control real.
 */
class CargoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cargo');
    }

    public function view(AuthUser $authUser, Cargo $cargo): bool
    {
        return $authUser->can('View:Cargo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cargo');
    }

    public function update(AuthUser $authUser, Cargo $cargo): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Cargo $cargo): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Cargo $cargo): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Cargo $cargo): bool
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

    public function replicate(AuthUser $authUser, Cargo $cargo): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
