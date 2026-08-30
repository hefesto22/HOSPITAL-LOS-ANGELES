<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exceptions\CargoException;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Qué parte del catálogo puede cargarle cada quien al paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA CON EL PERMISO DE SHIELD
 * ─────────────────────────────────────────────────────────────────────
 *
 * `Create:Cargo` contesta «¿puede cargar?». La pregunta que falta es
 * «¿puede cargar QUÉ?», y esa no la puede contestar un permiso de
 * Shield: los permisos son por MODELO, y acá el mismo modelo `Cargo`
 * sirve para un hemograma y para una cesárea.
 *
 * El laboratorio registra al paciente ambulatorio, le abre la cuenta y
 * le carga los exámenes. Con el permiso a secas también podría cargarle
 * una noche de hospitalización — no por maldad, sino porque el selector
 * se lo ofrece y el catálogo entero está a un clic.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE DERIVA DEL TIPO DEL ÍTEM, COMO LOS ALMACENES DEL TIPO DEL ESTANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mismo patrón que `AlmacenesDelUsuario`, y por la misma razón: el dato
 * ya está en el ítem, así que una tabla de «qué puede cargar cada rol»
 * sería una pantalla más para decir lo que el catálogo ya dice.
 *
 * El mapa vive en `config/sihla.php`. Un rol que NO aparece ahí no tiene
 * restricción: caja, admisión, enfermería y dirección cargan cualquier
 * cosa, que es lo que tienen que poder hacer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN USUARIO NO SE FILTRA — Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Misma decisión que `ContextoSede` y `AlmacenesDelUsuario`: en consola,
 * colas y seeders no hay usuario, y filtrar a vacío haría que un comando
 * de demo no pudiera cargar nada, en silencio.
 */
final class CatalogoDelRol
{
    /**
     * Los tipos de ítem que ese usuario puede cargar, o null si no tiene
     * restricción.
     *
     * @return list<string>|null
     */
    public static function tiposQuePuedeCargar(?User $usuario = null): ?array
    {
        $usuario ??= self::usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        $mapa = config('sihla.catalogo.cargos_por_rol', []);

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
         * Un usuario con un rol restringido Y uno sin restricción —el
         * laboratorista que además cubre caja los sábados— NO queda
         * restringido: gana el permiso más amplio. Lo contrario haría
         * que sumar un rol quitara acceso, que es la clase de sorpresa
         * que termina en «el sistema se rompió».
         */
        if (! $restringido || self::tieneAlgunRolSinRestriccion($usuario, $mapa)) {
            return null;
        }

        return array_values(array_unique($permitidos));
    }

    public static function puedeCargar(Item $item, ?User $usuario = null): bool
    {
        $tipos = self::tiposQuePuedeCargar($usuario);

        return $tipos === null || in_array($item->tipo->value, $tipos, true);
    }

    /**
     * La misma verificación, pero del lado del servicio.
     *
     * Que el selector ya filtre no alcanza: es un método público de
     * Livewire y el id del ítem llega del cliente. Lo que limita lo que
     * se le puede cobrar a un paciente tiene que estar donde se escribe.
     *
     * @throws CargoException
     */
    public static function exigirQuePuedaCargar(Item $item): void
    {
        if (self::puedeCargar($item)) {
            return;
        }

        throw CargoException::noEsDeSuArea($item->nombre, $item->tipo->etiqueta());
    }

    /**
     * Acota una consulta de ítems a lo que este usuario puede cargar.
     *
     * @template TModelo of Item
     *
     * @param Builder<TModelo> $consulta
     */
    public static function filtrar(Builder $consulta): void
    {
        $tipos = self::tiposQuePuedeCargar();

        if ($tipos === null) {
            return;
        }

        $consulta->whereIn($consulta->qualifyColumn('tipo'), $tipos);
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
