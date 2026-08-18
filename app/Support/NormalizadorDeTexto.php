<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte un texto en su CLAVE DE BÚSQUEDA: minúsculas, sin acentos y
 * con los espacios colapsados.
 *
 * ⚠️ ESTA CLASE TIENE UN GEMELO EN SQL Y LOS DOS TIENEN QUE COINCIDIR.
 *
 * La columna `personas.nombre_busqueda` es `GENERATED ALWAYS AS (...)
 * STORED`: la calcula PostgreSQL, no PHP. Esta clase reproduce esa misma
 * transformación para el lado del que BUSCA.
 *
 * Si las dos se separan, el síntoma es silencioso y feo: el paciente está
 * en la base, admisión escribe su nombre, no aparece, y admisión crea el
 * duplicado. No hay error, no hay excepción, solo dos expedientes.
 *
 * Por eso existe una prueba que compara el resultado de esta clase contra
 * el valor que calculó la base para la misma persona
 * (`tests/Feature/Mpi/PersonaTest.php`). Si alguien toca una de las dos y
 * no la otra, esa prueba se cae.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE USA LA EXTENSIÓN `unaccent`
 * ─────────────────────────────────────────────────────────────────────
 *
 * `unaccent()` es la respuesta obvia y es una trampa: no está marcada
 * IMMUTABLE (depende de un diccionario que se puede cambiar), así que
 * PostgreSQL la rechaza dentro de una columna generada y dentro de un
 * índice de expresión. El truco habitual es envolverla en una función
 * propia declarada IMMUTABLE — que es mentirle al planificador: si el
 * diccionario cambia, el índice queda corrupto sin avisar.
 *
 * `translate()` sí es IMMUTABLE de verdad, cubre todo lo que aparece en
 * nombres hondureños, y no depende de nada externo.
 */
final class NormalizadorDeTexto
{
    /**
     * Los mismos pares que usa el `translate()` de la migración de
     * `personas`. El orden no importa; la correspondencia posición a
     * posición sí.
     */
    private const ACENTOS = 'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç';

    private const SIN_ACENTOS = 'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc';

    /**
     * Clave de búsqueda de un texto libre.
     */
    public static function clave(string $texto): string
    {
        $sinAcentos = strtr($texto, self::mapa());

        $colapsado = preg_replace('/\s+/u', ' ', trim($sinAcentos)) ?? '';

        return mb_strtolower($colapsado, 'UTF-8');
    }

    /**
     * Clave de búsqueda de un nombre armado por partes.
     *
     * Los nulos y los vacíos se descartan antes de unir: una persona sin
     * segundo nombre no debe generar un espacio doble que después haya que
     * limpiar.
     *
     * @param array<int, string|null> $partes
     */
    public static function claveDeNombre(array $partes): string
    {
        $limpias = array_filter(
            array_map(static fn (?string $parte): string => trim($parte ?? ''), $partes),
            static fn (string $parte): bool => $parte !== '',
        );

        return self::clave(implode(' ', $limpias));
    }

    /**
     * @return array<string, string>
     */
    private static function mapa(): array
    {
        /** @var array<int, string> $desde */
        $desde = preg_split('//u', self::ACENTOS, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        /** @var array<int, string> $hacia */
        $hacia = preg_split('//u', self::SIN_ACENTOS, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_combine($desde, $hacia);
    }
}
