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
 *  ✗ **Notas clínicas y texto libre.** Además de volverse ilegibles, son
 *    append-only (ADR-0004): si se guardan mal no se corrigen después.
 *  ✗ **Correos y contraseñas.** La parte local de un correo es sensible a
 *    mayúsculas del lado del servidor.
 *  ✗ **Códigos externos con formato ajeno** — CIE-10, LOINC, ATC, códigos
 *    de barras, UIDs de DICOM. Se guardan tal como los define quien los
 *    emite.
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
