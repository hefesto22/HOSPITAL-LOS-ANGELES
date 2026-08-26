<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\ValueObjects\Decimal;

/**
 * Lo que teclea una persona, convertido a `Decimal` — o nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ ESTO EXISTE: EL CERO SILENCIOSO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada pantalla tenía su propio conversor, y los tres hacían lo mismo:
 * ante cualquier cosa que no fuera un entero o una cadena de dígitos,
 * devolvían **`'0'`**.
 *
 * En una pantalla de recepción eso falla ruidoso —una cantidad cero
 * rebota con un error del dominio—. En la pantalla de **contar**, no:
 * ahí el cero es un valor legal y esperado, «el estante está vacío». Así
 * que un `12.5` que llegara del navegador como número decimal se
 * guardaba como **contado 0** contra su saldo congelado, y al cerrar el
 * conteo se asentaba la baja del lote completo en un kardex que ya no se
 * puede editar. Sin un solo mensaje de error.
 *
 * Es exactamente la regla que el módulo declara proteger —«nada queda en
 * cero por omisión»— rota por el conversor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NULO SIGNIFICA «NO ENTIENDO ESTO», Y NO ES LO MISMO QUE CERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Devuelve `null` en vez de adivinar. Quien llama decide qué excepción
 * del dominio lanzar, con el mensaje que corresponde a su pantalla. Un
 * conversor no puede saber si un campo vacío es un error o un valor
 * omitido a propósito; el que sí lo sabe es el caso de uso.
 */
final class NumeroDeFormulario
{
    /**
     * Acepta lo que de verdad puede llegar de Livewire: entero, decimal
     * o cadena. Todo lo demás —arreglos, nulos, texto— es `null`.
     *
     * El `float` se normaliza con `number_format` a cuatro decimales, que
     * es la escala del kardex, y **nunca** entra a `Decimal` como float:
     * ese es el punto exacto donde el §8.6.2 se rompería.
     */
    public static function aDecimal(mixed $valor, int $decimales = 4): ?Decimal
    {
        if (is_int($valor)) {
            return Decimal::de((string) $valor);
        }

        if (is_float($valor)) {
            if (! is_finite($valor)) {
                return null;
            }

            return Decimal::de(number_format($valor, $decimales, '.', ''));
        }

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        if ($texto === '') {
            return null;
        }

        /*
         * Se acepta la coma como separador decimal: en Honduras se
         * teclea así en la mitad de los teclados, y rechazarlo produce
         * un cero silencioso o un error incomprensible.
         */
        $texto = str_replace(',', '.', $texto);

        /*
         * Se admiten las formas que una persona escribe de verdad —«.5»,
         * «5.», «0005»— pero NO la notación científica ni los signos:
         * un «1e3» tecleado por accidente son mil unidades que nadie
         * quiso poner, y el signo lo pone el tipo de movimiento, jamás
         * el número (§ `TipoMovimiento`).
         */
        if (preg_match('/^(?:\d+\.?\d*|\.\d+)$/', $texto) !== 1) {
            return null;
        }

        return Decimal::de(self::normalizar($texto));
    }

    /**
     * Igual, pero con un valor de respaldo cuando no se entiende.
     *
     * Solo para campos donde el respaldo NO puede confundirse con un
     * dato válido —una tolerancia, un tope—. Nunca para una cantidad
     * contada.
     */
    public static function aDecimalO(mixed $valor, Decimal $respaldo, int $decimales = 4): Decimal
    {
        return self::aDecimal($valor, $decimales) ?? $respaldo;
    }

    /**
     * «.5» → «0.5», «5.» → «5», «0005» → «5». Lo que bcmath entiende.
     *
     * @return numeric-string
     */
    private static function normalizar(string $texto): string
    {
        if (str_starts_with($texto, '.')) {
            $texto = '0'.$texto;
        }

        if (str_ends_with($texto, '.')) {
            $texto = rtrim($texto, '.');
        }

        /*
         * Ceros de más a la izquierda, pero solo si después viene otro
         * dígito: «0.5» tiene que seguir siendo «0.5».
         */
        $texto = preg_replace('/^0+(?=\d)/', '', $texto) ?? $texto;

        /** @var numeric-string $texto */
        return $texto;
    }
}
