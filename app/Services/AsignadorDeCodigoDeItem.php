<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CategoriaItem;
use Illuminate\Support\Facades\DB;

/**
 * Propone el siguiente código del catálogo: `MED-0027`, `LAB-0049`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO USA `AsignadorDeCorrelativo`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ese servicio numera POR SEDE —su contador es `(sede, tipo, año)` y su
 * formato lleva el código de sede adentro: `EXP-HLA-2026-00000042`—.
 * El catálogo es de la ORGANIZACIÓN, no de la sede (ADR-0003): un mismo
 * `MED-0027` tiene que significar lo mismo en las dos sedes. Con un
 * contador por sede, la sede 2 emitiría su propio `MED-0027` para otro
 * medicamento y el código dejaría de identificar nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CONTINÚA LA NUMERACIÓN QUE YA EXISTE, NO EMPIEZA DE CERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El número sale del MÁXIMO ya cargado con ese prefijo, y el ancho
 * también: si la familia va en tres dígitos (`HOS-023`), el siguiente es
 * `HOS-024` y no `HOS-0024`. Mezclar anchos rompe el orden alfabético
 * del listado, que es como la gente lee un tarifario impreso.
 *
 * ⚠️ Cuenta también los borrados. Un código retirado NO se reutiliza:
 * aparece en facturas viejas, y dos productos distintos con el mismo
 * código a diez años de distancia es exactamente lo que hace que una
 * auditoría no cierre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO PROPONE; LA BASE DECIDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * El número se calcula para MOSTRARLO en el formulario, donde se puede
 * corregir a mano —hay productos que ya traen código del proveedor o del
 * sistema viejo—. Por eso acá no hay candado: si dos personas cargan al
 * mismo tiempo y les toca el mismo número, quien guarde segundo choca
 * contra el índice único de `items.codigo` y la regla `unique` del
 * formulario se lo dice en limpio. Un lock que sostuviera el número
 * mientras alguien piensa duraría minutos y trabaría la carga entera.
 */
final class AsignadorDeCodigoDeItem
{
    /** Cuántos dígitos usar cuando la familia todavía no tiene ninguno. */
    private const ANCHO_POR_DEFECTO = 4;

    public function siguiente(CategoriaItem $categoria): string
    {
        $prefijo = $categoria->codigo;

        [$ultimo, $ancho] = $this->ultimoDe($prefijo);

        return $prefijo.'-'.str_pad((string) ($ultimo + 1), $ancho, '0', STR_PAD_LEFT);
    }

    /**
     * ¿Este código tiene forma de autogenerado?
     *
     * Sirve para saber si se puede pisar cuando alguien cambia de
     * categoría a mitad de la carga. Un `PARAC500` tecleado a mano no se
     * toca; un `MED-0027` que puso el sistema, sí.
     */
    public function pareceAutogenerado(string $codigo): bool
    {
        return preg_match('/^[A-Z0-9]+-\d+$/', $codigo) === 1;
    }

    /**
     * El número más alto ya usado con ese prefijo, y con cuántos dígitos
     * se escribió.
     *
     * Se filtra por LIKE en la base y se valida el patrón en PHP: armar
     * un regex de PostgreSQL concatenando el prefijo dejaría que un
     * código de categoría con metacaracteres cambiara la consulta. Son
     * decenas de filas por prefijo, no miles.
     *
     * @return array{0: int, 1: int}
     */
    private function ultimoDe(string $prefijo): array
    {
        /** @var array<int, string> $codigos */
        $codigos = DB::table('items')
            ->where('codigo', 'like', $prefijo.'-%')
            ->pluck('codigo')
            ->all();

        $patron = '/^'.preg_quote($prefijo, '/').'-(\d+)$/';

        $mayor = 0;
        $ancho = self::ANCHO_POR_DEFECTO;

        foreach ($codigos as $codigo) {
            if (preg_match($patron, $codigo, $partes) !== 1) {
                continue;
            }

            $numero = (int) $partes[1];

            if ($numero >= $mayor) {
                $mayor = $numero;
                $ancho = mb_strlen($partes[1]);
            }
        }

        return [$mayor, $ancho];
    }
}
