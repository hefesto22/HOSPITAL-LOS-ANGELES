<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlantillaPresupuesto;
use Illuminate\Support\Str;

/**
 * El código de una plantilla sale de su nombre: CX-APENDICE, CX-CESAREA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE PIDE, Y POR QUÉ NO ES UN CORRELATIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * No se pide porque nadie de afuera lo audita: es interno, sirve para
 * encontrar la plantilla en un desplegable. Pedirlo era pedirle a quien
 * carga que invente una convención y después la recuerde.
 *
 * Y no es un número corrido —CX-0001, CX-0002— a propósito. Quien busca
 * una plantilla la busca por la cirugía, y «CX-CESAREA» se reconoce en
 * una lista de veinte; «CX-0007» hay que abrirlo para saber qué es.
 *
 * ⚠️ ASCII y sin espacios: el código viaja en URLs, en exportaciones a
 * Excel y en el buscador. «CX-CESÁREA» con tilde se rompe en alguno de
 * los tres, y siempre en el que nadie estaba mirando.
 *
 * ⚠️ El sufijo numérico solo aparece cuando hace falta. Dos plantillas
 * de cesárea —una programada y otra de urgencia— dan CX-CESAREA y
 * CX-CESAREA-2, y ahí el nombre completo desempata.
 */
final class AsignadorDeCodigoDePlantilla
{
    private const PREFIJO = 'CX-';

    /**
     * Cuántos sufijos se prueban antes de rendirse. Veinte plantillas de
     * la misma cirugía no existen; el tope está para que el ciclo termine
     * siempre, no porque alguien vaya a llegar.
     */
    private const INTENTOS = 20;

    public function siguiente(string $nombre): string
    {
        $base = self::PREFIJO.$this->raiz($nombre);

        if (! $this->existe($base)) {
            return $base;
        }

        for ($n = 2; $n <= self::INTENTOS; $n++) {
            $candidato = $base.'-'.$n;

            if (! $this->existe($candidato)) {
                return $candidato;
            }
        }

        /*
         * El último recurso: la hora. Feo, pero único, y prefiero un
         * código feo a un error de índice único en la cara de quien está
         * cargando el tarifario quirúrgico.
         */
        return $base.'-'.now()->format('His');
    }

    /**
     * La primera palabra útil del nombre, sin tildes y sin las palabras
     * que no distinguen nada.
     *
     * «CESAREA» de «CESAREA SEGMENTARIA»; «APENDICE» de «APENDICECTOMIA»
     * no —eso sería adivinar—, así que se corta a doce caracteres, que es
     * lo que entra sin volverse ilegible.
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
            static fn (string $p): bool => $p !== '' && ! in_array($p, ['DE', 'DEL', 'LA', 'EL', 'POR', 'CON'], true),
        ));

        $raiz = $palabras[0] ?? 'PLANTILLA';

        return mb_substr($raiz, 0, 12);
    }

    private function existe(string $codigo): bool
    {
        return PlantillaPresupuesto::query()
            ->where('codigo', $codigo)
            ->exists();
    }
}
