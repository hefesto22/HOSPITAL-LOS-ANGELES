<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Servicio;
use Illuminate\Support\Str;

/**
 * El código de un área sale de su nombre: EMERG, HOSPI, QUIRO, LABOR.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE PIDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Nadie de afuera lo audita: es interno y sirve para reconocer el área en
 * un desplegable o en una exportación. Pedirlo era pedirle a quien crea
 * el área que invente una convención y después la recuerde — y quien
 * está creando un carrito, y abrió este formulario de paso, menos todavía.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEL NOMBRE Y NO DEL TIPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Al revés que en los almacenes, acá los nombres SÍ distinguen:
 * EMERGENCIA, QUIRÓFANO, HOSPITALIZACIÓN. Un correlativo por tipo daría
 * QX-01 y QX-02 para dos quirófanos que la gente llama por su nombre.
 *
 * Cinco caracteres es lo que hace falta para reconocerlo y lo que entra
 * en una columna angosta. Dos áreas que empiezan igual —CONSULTA EXTERNA
 * y CONSULTA GENERAL— dan CONSU y CONSU-2, y ahí el nombre completo
 * desempata.
 *
 * ⚠️ ASCII y sin espacios: el código viaja en URLs, en exportaciones a
 * Excel y en el buscador. «QUIRÓFANO» con tilde se rompe en alguno de los
 * tres, y siempre en el que nadie estaba mirando.
 */
final class AsignadorDeCodigoDeServicio
{
    /**
     * Cuántos sufijos se prueban antes de rendirse. El tope está para que
     * el ciclo termine siempre, no porque un hospital vaya a tener veinte
     * áreas que empiecen con la misma palabra.
     */
    private const INTENTOS = 20;

    private const LARGO = 5;

    public function siguiente(string $nombre, ?int $sedeId = null): string
    {
        $base = $this->raiz($nombre);

        if (! $this->existe($base, $sedeId)) {
            return $base;
        }

        for ($n = 2; $n <= self::INTENTOS; $n++) {
            $candidato = $base.'-'.$n;

            if (! $this->existe($candidato, $sedeId)) {
                return $candidato;
            }
        }

        /*
         * El último recurso: la hora. Feo, pero único, y prefiero un
         * código feo a un error de índice único en la cara de quien está
         * creando el área.
         */
        return $base.'-'.now()->format('His');
    }

    /**
     * La primera palabra útil del nombre, sin tildes y sin las palabras
     * que no distinguen nada.
     */
    private function raiz(string $nombre): string
    {
        $limpio = Str::of($nombre)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9 ]/', ' ')
            ->squish()
            ->toString();

        $palabras = array_values(array_filter(
            explode(' ', $limpio),
            static fn (string $p): bool => $p !== ''
                && ! in_array($p, ['DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y', 'CON'], true),
        ));

        $raiz = mb_substr($palabras[0] ?? '', 0, self::LARGO);

        /*
         * Un nombre que se quedó sin letras —«---», «123»— no puede
         * producir un código vacío: la columna es NOT NULL y el índice
         * único convertiría la segunda área así en un error de base de
         * datos.
         */
        return $raiz === '' ? 'AREA' : $raiz;
    }

    /**
     * ⚠️ `withTrashed()`: un área dada de baja sigue nombrada en los
     * encuentros y los cargos viejos. Reusar su código haría que dos
     * historias distintas se lean como una.
     */
    private function existe(string $codigo, ?int $sedeId): bool
    {
        $consulta = Servicio::withTrashed()->where('codigo', $codigo);

        if ($sedeId !== null) {
            $consulta->where('sede_id', $sedeId);
        }

        return $consulta->exists();
    }
}
