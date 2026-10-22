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
     * ¿Puede agregarle (o quitarle) líneas a ESTA cuenta? (21-oct-2026)
     *
     * Antes la pantalla pedía `update` para agregar, y `Update:Cuenta`
     * es también congelar, cerrar y ponerle descuento: laboratorio y
     * consulta externa abren la cuenta y le cargan, pero cerrarla y
     * rebajarla es de caja (`MatrizDePermisosSeeder`). Sin ese permiso,
     * agregar un ácido úrico daba 403 —«Error al cargar la página»—.
     *
     * Cargar es `Create:Cargo` sobre una cuenta viva. QUÉ puede cargar
     * cada rol lo sigue decidiendo `CatalogoDelRol`, del lado de la
     * pantalla y del servicio.
     */
    public function cargar(AuthUser $authUser, Cuenta $cuenta): bool
    {
        return $cuenta->estaViva() && $authUser->can('Create:Cargo');
    }

    /**
     * ¿Puede anular ESTA cuenta? (Mauricio, 21-oct-2026)
     *
     * Quien tiene `Update:Cuenta` —caja, admisión, enfermería y
     * dirección, los que ya la congelan y la cierran— anula cualquiera.
     * Quien solo la abre —laboratorio, consulta externa— anula LA QUE ÉL
     * ABRIÓ: corrige su propio error sin llamar a nadie, y no el de otro.
     *
     * Solo mira la fila y los permisos, a propósito: se pregunta por cada
     * tarjeta de «Cuentas abiertas», y una consulta acá serían veinte. Lo
     * que exige mirar los renglones —que todo lo vivo sea de su área—
     * lo verifica la pantalla al anular (`CatalogoDelRol`), y lo que
     * exige plata o estado lo vuelve a verificar `AnuladorDeCuenta` bajo
     * candado.
     */
    public function anular(AuthUser $authUser, Cuenta $cuenta): bool
    {
        if (! $cuenta->estaViva()) {
            return false;
        }

        if ($authUser->can('Update:Cuenta')) {
            return true;
        }

        $abrio = $cuenta->getAttribute('created_by');

        return $authUser->can('Create:Cuenta')
            && is_numeric($abrio)
            && (int) $abrio === (int) $authUser->getAuthIdentifier();
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
