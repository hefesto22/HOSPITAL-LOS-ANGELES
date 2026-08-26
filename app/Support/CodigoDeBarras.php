<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dibuja el código interno del ítem como un Code 128-B en SVG.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL CÓDIGO DE BARRAS ES EL CÓDIGO INTERNO, Y NO EL DE LA CAJA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El hospital REENVASA: el blíster que sale de farmacia no lleva el EAN
 * del fabricante, y la misma «ACETAMINOFEN 500 MG TABLETA» se compra
 * unas veces de una marca y otras de otra. El EAN identifica la caja del
 * proveedor; lo que el hospital necesita identificar es su ítem.
 *
 * Así que la etiqueta la imprime el hospital, con `MED-0027` codificado.
 * Escanear esa etiqueta busca por código y encuentra el producto: no
 * hace falta ninguna columna nueva ni mantener una tabla de
 * equivalencias. El EAN del proveedor sigue viviendo en la presentación
 * de compra, que es donde sirve — para recibir mercadería.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ SE DIBUJA ACÁ Y NO CON UNA LIBRERÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Code 128-B es una tabla de 107 patrones y una suma de verificación. Un
 * paquete más —con su ciclo de actualizaciones y su superficie de
 * dependencias— para cien líneas deterministas y testeables no se paga.
 * Y sobre todo: sale SVG desde PHP, sin JavaScript, sin assets que
 * compilar y sin nada que pueda fallar en una máquina distinta.
 *
 * Code 128-B cubre mayúsculas, dígitos y guiones, que es todo lo que
 * puede tener un código del catálogo (§8.10).
 */
final class CodigoDeBarras
{
    /**
     * Los 107 patrones de Code 128. Cada dígito es el ANCHO de un
     * módulo, alternando barra y espacio, empezando por barra.
     *
     * @var list<string>
     */
    private const PATRONES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const INICIO_B = 104;

    private const FIN = 106;

    /**
     * El SVG completo, listo para meter en la pantalla o en la etiqueta.
     *
     * @param int $modulo ancho en px de la barra más angosta
     * @param int $alto alto de las barras, sin contar el texto
     */
    public static function svg(string $texto, int $modulo = 2, int $alto = 60): string
    {
        $codificable = self::codificable($texto);

        if ($codificable === '') {
            return '';
        }

        $anchos = self::anchos($codificable);
        $total = array_sum($anchos) * $modulo;
        $altoTotal = $alto + 18;

        $barras = '';
        $x = 0;
        $esBarra = true;

        foreach ($anchos as $ancho) {
            $w = $ancho * $modulo;

            if ($esBarra) {
                $barras .= sprintf(
                    '<rect x="%d" y="0" width="%d" height="%d" fill="currentColor"/>',
                    $x,
                    $w,
                    $alto,
                );
            }

            $x += $w;
            $esBarra = ! $esBarra;
        }

        $etiqueta = sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="monospace" '
            .'font-size="13" letter-spacing="2" fill="currentColor">%s</text>',
            (int) ($total / 2),
            $alto + 15,
            htmlspecialchars($codificable, ENT_QUOTES, 'UTF-8'),
        );

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
            .'viewBox="0 0 %d %d" role="img" aria-label="Código de barras %s">%s%s</svg>',
            $total,
            $altoTotal,
            $total,
            $altoTotal,
            htmlspecialchars($codificable, ENT_QUOTES, 'UTF-8'),
            $barras,
            $etiqueta,
        );
    }

    /**
     * Lo que de verdad se puede codificar: Code 128-B cubre los
     * caracteres imprimibles ASCII del 32 al 126. Una tilde o una ñ en
     * un código no se dibuja mal — no se dibuja, y por eso se descarta
     * el código entero antes que imprimir una etiqueta que el lector va
     * a leer distinto de lo que dice el papel.
     */
    public static function codificable(string $texto): string
    {
        $texto = trim($texto);

        return preg_match('/^[\x20-\x7E]+$/', $texto) === 1 ? $texto : '';
    }

    /**
     * La secuencia de anchos: inicio, datos, verificación y fin.
     *
     * @return list<int>
     */
    private static function anchos(string $texto): array
    {
        $valores = [self::INICIO_B];
        $suma = self::INICIO_B;
        $posicion = 1;

        foreach (str_split($texto) as $caracter) {
            $valor = ord($caracter) - 32;
            $valores[] = $valor;

            /*
             * La suma de verificación pondera cada carácter por su
             * posición. Sin ella un lector aceptaría cualquier ruido como
             * si fuera un código válido, y en farmacia eso es dispensar
             * el medicamento equivocado sin que nada avise.
             */
            $suma += $valor * $posicion;
            $posicion++;
        }

        $valores[] = $suma % 103;
        $valores[] = self::FIN;

        $anchos = [];

        foreach ($valores as $valor) {
            foreach (str_split(self::PATRONES[$valor]) as $digito) {
                $anchos[] = (int) $digito;
            }
        }

        return $anchos;
    }
}
