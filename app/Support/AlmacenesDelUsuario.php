<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exceptions\AjusteException;
use App\Models\Almacen;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * En qué estantes puede trabajar cada quien.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE DERIVA DEL TIPO DE ALMACÉN, NO DE UNA ASIGNACIÓN NUEVA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Bodega cuenta y ajusta la bodega central y los stocks de servicio;
 * farmacia, la farmacia. Podría modelarse asignando usuarios a almacenes
 * uno por uno, pero eso es una tabla más, una pantalla más y un mantenimiento
 * más para expresar algo que ya está en el dato: el **tipo** del almacén.
 *
 * El mapa vive en `config/sihla.php` y no acá, porque la clínica
 * siguiente tiene otra estructura de mando (§1.1). Un rol que no aparece
 * en el mapa —dirección, auditoría— no tiene restricción: ve y opera
 * todo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL FILTRO VA EN LA CONSULTA, NO EN LA POLICY POR FILA
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.L5: el alcance se aplica en `getEloquentQuery()`. Es lo que además
 * tapa el agujero típico de Filament —abrir por URL el registro de otro—,
 * porque el registro sencillamente no existe para esa consulta.
 *
 * Preguntarlo fila por fila desde la policy daría el mismo resultado y
 * veinticinco consultas por página: exactamente el N+1 invisible que el
 * §13.2 prohíbe.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN USUARIO NO SE FILTRA — Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Misma decisión, y por la misma razón, que `ContextoSede`: en consola,
 * colas y seeders no hay usuario, y filtrar a vacío haría que un reporte
 * programado devolviera cero filas en silencio. Un cierre que reporta
 * cero no se ve como un error, se ve como un mes malo.
 */
final class AlmacenesDelUsuario
{
    /**
     * Los tipos de almacén que ese usuario puede tocar, o null si no
     * tiene restricción.
     *
     * @return list<string>|null
     */
    public static function tiposPermitidos(?User $usuario = null): ?array
    {
        $usuario ??= self::usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        $mapa = config('sihla.inventario.almacenes_por_rol', []);

        if (! is_array($mapa) || $mapa === []) {
            return null;
        }

        $permitidos = [];
        $restringido = false;

        foreach ($mapa as $rol => $tipos) {
            if (! is_string($rol) || ! is_array($tipos) || ! $usuario->hasRole($rol)) {
                continue;
            }

            $restringido = true;

            foreach ($tipos as $tipo) {
                if (is_string($tipo)) {
                    $permitidos[] = $tipo;
                }
            }
        }

        /*
         * Un usuario con un rol restringido Y uno sin restricción —una
         * cajera que además es dirección— no queda restringido: gana el
         * permiso más amplio. Lo contrario haría que sumar un rol quitara
         * acceso, que es la clase de sorpresa que termina en «el sistema
         * se rompió».
         */
        if (! $restringido || self::tieneAlgunRolSinRestriccion($usuario, $mapa)) {
            return null;
        }

        return array_values(array_unique($permitidos));
    }

    /**
     * Filtra una consulta a los almacenes en los que el usuario trabaja.
     *
     * Va como subconsulta sobre `almacen_id` y no como join: no agrega
     * filas, no obliga a calificar columnas en el resto de la consulta y
     * cuesta un índice.
     *
     * @template TModelo of Model
     *
     * @param Builder<TModelo> $consulta
     */
    public static function filtrar(Builder $consulta, string $columna = 'almacen_id'): void
    {
        $tipos = self::tiposPermitidos();

        if ($tipos === null) {
            return;
        }

        $consulta->whereIn(
            $consulta->qualifyColumn($columna),
            DB::table('almacenes')
                ->select('almacenes.id')
                ->whereIn('almacenes.tipo', $tipos)
                ->whereNull('almacenes.deleted_at'),
        );
    }

    /**
     * Los almacenes que el usuario puede elegir en un formulario.
     *
     * @return Builder<Almacen>
     */
    public static function elegibles(): Builder
    {
        $consulta = Almacen::query();
        $tipos = self::tiposPermitidos();

        if ($tipos !== null) {
            $consulta->whereIn('almacenes.tipo', $tipos);
        }

        return $consulta;
    }

    public static function puedeOperarEn(Almacen $almacen, ?User $usuario = null): bool
    {
        $tipos = self::tiposPermitidos($usuario);

        return $tipos === null || in_array($almacen->tipo->value, $tipos, true);
    }

    /**
     * La misma verificación, pero del lado del servicio.
     *
     * Que la consulta ya filtre no alcanza: un comando, un import o una
     * pantalla futura pueden llamar al servicio con cualquier almacén, y
     * lo que protege el inventario tiene que estar donde se escribe.
     *
     * @throws AjusteException
     */
    public static function exigirAcceso(Almacen $almacen): void
    {
        if (self::puedeOperarEn($almacen)) {
            return;
        }

        throw AjusteException::noEsSuAlmacen($almacen->nombre);
    }

    /**
     * @param array<array-key, mixed> $mapa
     */
    private static function tieneAlgunRolSinRestriccion(User $usuario, array $mapa): bool
    {
        $restringidos = array_values(array_filter(array_keys($mapa), 'is_string'));

        foreach ($usuario->getRoleNames() as $rol) {
            if (is_string($rol) && ! in_array($rol, $restringidos, true)) {
                return true;
            }
        }

        return false;
    }

    private static function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}
