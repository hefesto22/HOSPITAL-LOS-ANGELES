<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sede;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Resuelve en qué sede está parado el usuario ahora mismo.
 *
 * Es la única fuente de verdad del alcance por sede (ADR-0002). El trait
 * BelongsToSede y todo Resource de Filament preguntan acá; nadie lee
 * `auth()->user()->sede_id` por su cuenta, porque el día que un usuario
 * pueda cambiar de sede hay que tocar un solo archivo.
 *
 * ⚠️ DECISIÓN DE SEGURIDAD, documentada a propósito:
 *
 * Sin usuario autenticado —consola, colas, seeders, migraciones— este
 * contexto devuelve "todas" y NO filtra.
 *
 * La alternativa (filtrar a vacío) es peor y no es obvio por qué: un job
 * de cola o un reporte programado devolvería CERO filas en silencio, y
 * un cierre de mes que reporta cero no se ve como un error, se ve como un
 * mes malo. Preferimos que el código de consola sea explícito con su sede.
 *
 * La protección real contra fuga entre sedes es el test obligatorio del
 * ADR-0002: un usuario de la sede A no puede abrir POR URL un registro de
 * la sede B.
 */
final class ContextoSede
{
    private const CLAVE_SESION = 'sihla.sede_actual';

    /**
     * Sede en la que se está trabajando ahora. Null en consola.
     */
    public static function actualId(): ?int
    {
        $usuario = self::usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        /** @var int|null $elegida */
        $elegida = Session::get(self::CLAVE_SESION);

        if (is_int($elegida) && self::puedeVer($usuario, $elegida)) {
            return $elegida;
        }

        /** @var int|null $propia */
        $propia = $usuario->getAttribute('sede_id');

        if (is_int($propia)) {
            return $propia;
        }

        return self::unicaSedeVigente();
    }

    /**
     * Ultimo recurso: si el hospital tiene UNA sola sede vigente, es esa.
     *
     * Existe por un caso que aparecio apenas se uso el panel: direccion y
     * soporte pueden ver TODAS las sedes, asi que no tienen `sede_id`
     * propio, y mientras no exista el selector de sede en el panel no hay
     * forma de que elijan una. El resultado era que el unico usuario que
     * puede hacer todo no podia registrar un paciente.
     *
     * ⚠️ Solo devuelve algo cuando hay EXACTAMENTE una sede vigente. Con
     * dos, esto deja de responder a proposito: ahi ya no hay un default
     * obvio, y adivinar es como se termina con el expediente de un
     * paciente colgando de la sede equivocada. El dia que se abra la
     * segunda sede, este metodo devuelve null y el sistema exige elegir.
     */
    private static function unicaSedeVigente(): ?int
    {
        /** @var list<int> $vigentes */
        $vigentes = Sede::query()
            ->vigentesEn(now())
            ->limit(2)
            ->pluck('id')
            ->all();

        return count($vigentes) === 1 ? $vigentes[0] : null;
    }

    /**
     * IDs de sede que este usuario puede ver.
     *
     * `null` significa TODAS — sin filtro. Es distinto de un arreglo
     * vacío, que significaría "ninguna" y dejaría al usuario sin datos.
     *
     * @return list<int>|null
     */
    public static function idsVisibles(): ?array
    {
        $usuario = self::usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        if (self::veTodas($usuario)) {
            return null;
        }

        $actual = self::actualId();

        return $actual === null ? [] : [$actual];
    }

    /**
     * Cambia la sede activa. Valida contra lo que el usuario puede ver:
     * un id de sede en la sesión no es una credencial.
     */
    public static function establecer(int $sedeId): bool
    {
        $usuario = self::usuario();

        if (! $usuario instanceof User || ! self::puedeVer($usuario, $sedeId)) {
            return false;
        }

        Session::put(self::CLAVE_SESION, $sedeId);

        return true;
    }

    public static function olvidar(): void
    {
        Session::forget(self::CLAVE_SESION);
    }

    /**
     * Solo dirección y soporte cruzan sedes. Todos los demás quedan en la
     * suya: es el §9.L5 (rol + relación + sede + turno).
     */
    private static function veTodas(User $usuario): bool
    {
        return $usuario->hasRole([
            ShieldUtils::getSuperAdminName(),
            'direccion',
        ]);
    }

    private static function puedeVer(User $usuario, int $sedeId): bool
    {
        if (self::veTodas($usuario)) {
            return true;
        }

        return $usuario->getAttribute('sede_id') === $sedeId;
    }

    private static function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}
