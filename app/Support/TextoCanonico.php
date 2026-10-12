<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Forma canónica de un texto que se guarda: MAYÚSCULAS, sin espacios de
 * sobra, y el vacío convertido en nulo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ MAYÚSCULAS, Y POR QUÉ **CON** TILDES Y Ñ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Las mayúsculas resuelven un problema real: el turno A escribe "josé
 * peña", el turno B "Jose Peña" y el turno C "JOSE PENA". En pantalla se
 * ve desprolijo y en un listado ordenado quedan separados.
 *
 * Quitar las tildes parece la continuación natural de esa idea, y NO lo
 * es. El nombre guardado es el que sale impreso en la factura, y la
 * factura tiene que coincidir letra por letra con el DNI del paciente —
 * que dice "PEÑA", con eñe, y "JOSÉ", con tilde. Si el SAR audita o una
 * aseguradora compara contra el documento, "PENA" y "PEÑA" no son la
 * misma persona: son dos apellidos distintos para el RNP.
 *
 * La uniformidad que uno busca al quitar tildes ya está resuelta en otro
 * lado y mejor: la columna generada `personas.nombre_busqueda` y
 * `App\Support\NormalizadorDeTexto` sí quitan tildes, así que BUSCAR
 * "pena" encuentra a "PEÑA". Se busca sin tildes y se imprime con ellas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DÓNDE NO SE USA ESTO, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 *  ✗ **Unidades de dosis y concentración.** `mg` y `Mg` no son lo mismo,
 *    `mcg` no es `MCG` y `pH` no es `PH`. Pasar unidades a mayúsculas es
 *    una causa documentada de error de medicación.
 *  ✗ **Correos y contraseñas.** La parte local de un correo es sensible a
 *    mayúsculas del lado del servidor.
 *  ✗ **Códigos externos con formato ajeno** — CIE-10, LOINC, ATC, códigos
 *    de barras, UIDs de DICOM. Se guardan tal como los define quien los
 *    emite.
 *
 * ─────────────────────────────────────────────────────────────────────
 * Y LO QUE SE TECLEA TAMBIÉN (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los motivos, las notas, los comentarios y las observaciones estuvieron
 * en la lista de arriba hasta el 11-oct-2026, por «ilegibles». Mauricio:
 * «que cuando escriba todo sea en mayúsculas». En la bitácora de una
 * cuenta, «error al agregarlo» quedaba al lado de «ACETAMINOFEN 500 MG
 * TABLETA», y se leía como dos sistemas pegados.
 *
 * Lo que los excluía era gusto, no seguridad. Las unidades sí son
 * seguridad, y siguen afuera. Un área de texto va con
 * `parrafoEnMayusculas()`, para no perder los renglones.
 */
final class TextoCanonico
{
    /**
     * MAYÚSCULAS, espacios colapsados, vacío convertido en nulo.
     *
     * `mb_strtoupper` con UTF-8 explícito y no `strtoupper`: esta última
     * no convierte "ñ" ni las vocales acentuadas, y "peña" quedaría
     * "PEñA" — que es peor que no haber hecho nada.
     */
    public static function mayusculas(?string $texto): ?string
    {
        $limpio = self::limpio($texto);

        return $limpio === null ? null : mb_strtoupper($limpio, 'UTF-8');
    }

    /**
     * Lo mismo que `mayusculas()`, pero respetando los renglones.
     *
     * Para lo que se escribe en un área de texto: un motivo largo, la nota
     * del cierre de caja, la observación de un diagnóstico. `mayusculas()`
     * colapsa todo espacio —saltos de línea incluidos— y una nota de tres
     * renglones quedaría pegada en uno. Acá se limpia cada renglón por su
     * lado, se quitan los vacíos de las puntas y nunca quedan dos
     * renglones en blanco seguidos.
     */
    public static function parrafoEnMayusculas(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $renglones = array_map(
            static fn (string $renglon): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $renglon)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $texto)),
        );

        $parrafo = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $renglones)));

        return $parrafo === '' ? null : mb_strtoupper($parrafo, 'UTF-8');
    }

    /**
     * Recorta, colapsa espacios internos y convierte "" en null.
     *
     * El vacío a nulo importa más de lo que parece: un formulario que
     * envía "" y un import que no manda el campo producen dos estados
     * distintos para el mismo hecho —"no hay dato"—, y después una
     * consulta con `whereNull` encuentra unos y no otros.
     */
    public static function limpio(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $colapsado = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return $colapsado === '' ? null : $colapsado;
    }
}
