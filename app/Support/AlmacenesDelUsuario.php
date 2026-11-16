<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\AjusteException;
use App\Models\Almacen;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as ConsultaBase;
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
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ESTANTE DE UN SERVICIO: `servicio:laboratorio` (16-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Laboratorio trabaja en SU estante, no en todos los stocks de servicio:
 * el carrito rojo no es suyo. En el mapa, además de tipos de almacén, va
 * `servicio:<tipo de servicio>` = el stock de servicio de ese servicio.
 * Sigue siendo dato, no asignación uno por uno.
 */
final class AlmacenesDelUsuario
{
    /**
     * Lo que en el mapa no es un tipo de almacén sino «el stock de este
     * servicio».
     */
    private const SERVICIO = 'servicio:';

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
                ->where(self::condicion($tipos))
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
            $consulta->where(self::condicion($tipos));
        }

        return $consulta;
    }

    public static function puedeOperarEn(Almacen $almacen, ?User $usuario = null): bool
    {
        $tipos = self::tiposPermitidos($usuario);

        if ($tipos === null || in_array($almacen->tipo->value, $tipos, true)) {
            return true;
        }

        $servicios = self::separar($tipos)['servicios'];

        if ($servicios === [] || $almacen->tipo !== TipoAlmacen::StockDeServicio || $almacen->servicio_id === null) {
            return false;
        }

        $tipoDelServicio = DB::table('servicios')->where('id', $almacen->servicio_id)->value('tipo');

        return is_string($tipoDelServicio) && in_array($tipoDelServicio, $servicios, true);
    }

    /**
     * Los almacenes que el usuario VE, o null si ve todos (16-nov-2026).
     *
     * Bodega y farmacia ven todo el inventario —farmacia mira la bodega
     * antes de pedir— y operan solo lo suyo. Laboratorio no: su trabajo
     * son sus kits, y en «Existencias» y «Recibir mercadería» le aparece
     * solo su estante (Mauricio, 16-nov-2026). Qué roles ven solo lo suyo
     * es configuración: `sihla.inventario.ven_solo_su_almacen`.
     *
     * Con un rol que no está en esa lista, ve todo: sumar un rol no quita.
     *
     * @return list<int>|null
     */
    public static function idsQueVe(?User $usuario = null): ?array
    {
        $usuario ??= self::usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        $soloLoSuyo = config('sihla.inventario.ven_solo_su_almacen', []);

        if (! is_array($soloLoSuyo) || $soloLoSuyo === []) {
            return null;
        }

        $roles = $usuario->getRoleNames();

        if ($roles->isEmpty()) {
            return null;
        }

        foreach ($roles as $rol) {
            if (! in_array($rol, $soloLoSuyo, true)) {
                return null;
            }
        }

        $consulta = Almacen::query();
        $tipos = self::tiposPermitidos($usuario);

        if ($tipos !== null) {
            $consulta->where(self::condicion($tipos));
        }

        return array_values($consulta
            ->orderBy('almacenes.id')
            ->pluck('almacenes.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
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

    /**
     * La condición sobre `almacenes`: de esos tipos, o el stock de servicio
     * de esos servicios. Lista vacía = ninguno (Laravel escribe `0 = 1`).
     *
     * @param list<string> $permitidos
     *
     * @return Closure(Builder<Almacen>|ConsultaBase): void
     */
    private static function condicion(array $permitidos): Closure
    {
        ['tipos' => $tipos, 'servicios' => $servicios] = self::separar($permitidos);

        return function (Builder|ConsultaBase $consulta) use ($tipos, $servicios): void {
            $consulta->whereIn('almacenes.tipo', $tipos);

            if ($servicios !== []) {
                $consulta->orWhere(fn (Builder|ConsultaBase $sub): Builder|ConsultaBase => $sub
                    ->where('almacenes.tipo', TipoAlmacen::StockDeServicio->value)
                    ->whereIn(
                        'almacenes.servicio_id',
                        DB::table('servicios')->select('servicios.id')->whereIn('servicios.tipo', $servicios),
                    ));
            }
        };
    }

    /**
     * @param list<string> $permitidos
     *
     * @return array{tipos: list<string>, servicios: list<string>}
     */
    private static function separar(array $permitidos): array
    {
        $tipos = [];
        $servicios = [];

        foreach ($permitidos as $permitido) {
            if (str_starts_with($permitido, self::SERVICIO)) {
                $servicios[] = substr($permitido, strlen(self::SERVICIO));
            } else {
                $tipos[] = $permitido;
            }
        }

        return ['tipos' => $tipos, 'servicios' => $servicios];
    }

    private static function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}
