<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Diagnostico;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede diagnosticar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR ROL Y NO POR PERMISO DE SHIELD, A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los permisos de Shield son sobre PANTALLAS: dirección los reparte
 * según quién necesita ver qué. Diagnosticar no es eso — es un acto
 * médico, y el expediente es prueba en un juicio. Un permiso llamado
 * `Create:Diagnostico` que dirección le pueda dar a la cajera para
 * destrabar una factura anularía la decisión en la primera semana de
 * apuro, sin que nadie sienta que decidió nada.
 *
 * Atado al rol `medico`, la pregunta deja de ser «¿quién tiene el
 * permiso?» y vuelve a ser «¿esta persona es médico en este hospital?»,
 * que es la única pregunta correcta.
 *
 * ⚠️ El costo real de esto: si el médico no diagnostica, la cuenta no se
 * le puede reclamar a la aseguradora. Es a propósito — la plata detenida
 * persigue mejor que cualquier recordatorio.
 *
 * Leer NO está restringido acá: quién puede ver un expediente lo decide
 * la bitácora de lectura y el break-the-glass de ADR-0004, que es otro
 * mecanismo y más fino que un booleano.
 */
class DiagnosticoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return true;
    }

    public function view(AuthUser $authUser, Diagnostico $diagnostico): bool
    {
        return true;
    }

    public function create(AuthUser $authUser): bool
    {
        return self::esMedico($authUser);
    }

    /**
     * «Actualizar» un diagnóstico es corregirlo o retractarlo — nunca
     * editarlo (ADR-0004). El servicio es el que garantiza que sea una
     * enmienda y no un UPDATE.
     */
    public function update(AuthUser $authUser, Diagnostico $diagnostico): bool
    {
        return self::esMedico($authUser);
    }

    /**
     * Nunca. Un diagnóstico no se borra: se retracta y queda tachado con
     * su motivo. Borrarlo destruye la evidencia de que alguien lo pensó.
     */
    public function delete(AuthUser $authUser, Diagnostico $diagnostico): bool
    {
        return false;
    }

    /**
     * El super admin pasa por acá y no por un `Gate::before`: en este
     * proyecto Shield tiene `define_via_gate => false`, así que el rol no
     * intercepta nada por su cuenta. Si esto no estuviera, el dueño del
     * sistema no podría probar su propio hospital.
     */
    private static function esMedico(AuthUser $authUser): bool
    {
        /** @var User $authUser */
        return $authUser->hasRole(ShieldUtils::getSuperAdminName())
            || $authUser->hasRole('medico');
    }
}
