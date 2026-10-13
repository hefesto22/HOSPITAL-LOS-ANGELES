<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Factura;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Support\ContextoSede;
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

    /**
     * ─────────────────────────────────────────────────────────────────
     * FACTURA QUIEN TIENE LA GAVETA ABIERTA (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «facturar es exclusivo de las cajeras que estén en
     * turno». El permiso dice QUIÉN puede facturar (caja y dirección);
     * esto agrega CUÁNDO: con un turno de caja abierto en el que pueda
     * trabajar —el que abrió, o el de la gaveta si es de ese turno—.
     * La factura casi siempre se cobra en el acto —primero se salda— y
     * esa plata tiene que caer en una gaveta que alguien cuente al
     * cerrar.
     *
     * Los roles de `sihla.caja.facturan_sin_turno` (dirección) facturan
     * con o sin turno. Si en ese momento hay que cobrar, el abono les
     * pide la gaveta igual: eso lo cuida `ReceptorDeAbono`, no esto.
     *
     * Laboratorio y consulta externa no llegan ni a preguntar el turno:
     * no tienen el permiso.
     */
    public function create(AuthUser $authUser): bool
    {
        if (! $authUser->can('Create:Factura') || ! $authUser instanceof User) {
            return false;
        }

        if (self::facturaSinTurno($authUser)) {
            return true;
        }

        return app(AbridorDeTurnoDeCaja::class)->elQueOpera($authUser, ContextoSede::actualId()) instanceof TurnoDeCaja;
    }

    private static function facturaSinTurno(User $usuario): bool
    {
        $roles = config('sihla.caja.facturan_sin_turno', []);

        return is_array($roles) && $roles !== [] && $usuario->hasAnyRole(array_values(array_filter($roles, 'is_string')));
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
