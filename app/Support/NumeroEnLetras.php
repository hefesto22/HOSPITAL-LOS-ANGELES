<?php

declare(strict_types=1);

namespace App\Support;

/**
 * El monto escrito con palabras, para la factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UN ADORNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La factura hondureña lleva el total en letras, y no por tradición: es
 * la defensa contra el dígito alterado. Un «1,500.00» al que alguien le
 * agrega un cero se discute; un «MIL QUINIENTOS LEMPIRAS EXACTOS» al
 * lado, no.
 *
 * ⚠️ Los centavos van en números —«CON 45/100»— que es como se escribe
 * en Honduras y como lo esperan las auditorías.
 */
final class NumeroEnLetras
{
    /** @var list<string> */
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE',
    ];

    /** @var array<int, string> */
    private const DECENAS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    /** @var array<int, string> */
    private const CENTENAS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS',
        5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS',
        9 => 'NOVECIENTOS',
    ];

    /**
     * «MIL QUINIENTOS LEMPIRAS CON 45/100».
     */
    public static function lempiras(string $monto): string
    {
        $limpio = str_replace(',', '', trim($monto));

        if (! is_numeric($limpio)) {
            return '';
        }

        $partes = explode('.', number_format((float) $limpio, 2, '.', ''));

        $enteros = (int) ($partes[0] ?? '0');
        $centavos = str_pad($partes[1] ?? '00', 2, '0', STR_PAD_LEFT);

        $letras = $enteros === 0 ? 'CERO' : self::deEnteros($enteros);

        $moneda = $enteros === 1 ? 'LEMPIRA' : 'LEMPIRAS';

        /*
         * El orden es el del formulario que el hospital viene usando:
         * «TRES MIL SETECIENTOS VEINTITRÉS CON 61/100 LEMPIRAS». La
         * moneda va al final, después de los centavos.
         */
        return trim($letras).' CON '.$centavos.'/100 '.$moneda;
    }

    private static function deEnteros(int $numero): string
    {
        if ($numero < 0) {
            return 'MENOS '.self::deEnteros(-$numero);
        }

        if ($numero >= 1_000_000) {
            $millones = intdiv($numero, 1_000_000);
            $resto = $numero % 1_000_000;

            $texto = $millones === 1 ? 'UN MILLÓN' : self::deEnteros($millones).' MILLONES';

            return $resto === 0 ? $texto : $texto.' '.self::deEnteros($resto);
        }

        if ($numero >= 1000) {
            $miles = intdiv($numero, 1000);
            $resto = $numero % 1000;

            $texto = $miles === 1 ? 'MIL' : self::deEnteros($miles).' MIL';

            return $resto === 0 ? $texto : $texto.' '.self::deEnteros($resto);
        }

        if ($numero >= 100) {
            $centena = intdiv($numero, 100);
            $resto = $numero % 100;

            /* Cien exacto no es «ciento». */
            if ($centena === 1 && $resto === 0) {
                return 'CIEN';
            }

            $texto = self::CENTENAS[$centena] ?? '';

            return $resto === 0 ? $texto : $texto.' '.self::deEnteros($resto);
        }

        /*
         * ⚠️ Sin `?? ''` de acá para abajo: el analizador ya probó que
         * el índice cae adentro del arreglo —0 a 20 acá, 1 a 9 más
         * abajo— y un coalesce sobre algo que siempre existe es ruido
         * que esconde el día que deje de ser cierto.
         */
        if ($numero <= 20) {
            return self::UNIDADES[$numero];
        }

        if ($numero < 30) {
            /* Veintiuno, veintidós… van pegados. */
            return 'VEINTI'.mb_strtolower(self::UNIDADES[$numero - 20]);
        }

        $decena = intdiv($numero, 10);
        $unidad = $numero % 10;

        $texto = self::DECENAS[$decena] ?? '';

        return $unidad === 0 ? $texto : $texto.' Y '.self::UNIDADES[$unidad];
    }
}
