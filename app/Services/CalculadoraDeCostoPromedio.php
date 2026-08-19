<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\CostoPromedio;
use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El costo promedio ponderado, por ítem y por almacén.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA CUENTA, Y POR QUÉ ESTA Y NO EL ÚLTIMO PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 *     nuevo = (tenía × costo_actual + entra × costo_de_lo_que_entra)
 *             ─────────────────────────────────────────────────────
 *                            tenía + entra
 *
 * Con el ejemplo real: había 12.500 tabletas a L 10,00 y entran 2.000 a
 * L 13,50. El promedio pasa a **L 10,482759**, no a L 13,50. Esa es toda
 * la diferencia: con el último precio, una compra chica y cara revalúa
 * TODO el inventario viejo y de ahí sale un margen que nunca existió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LAS DOS ENTRADAS SIMULTÁNEAS DEL MISMO PRODUCTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dos personas reciben el mismo ítem al mismo tiempo en la misma bodega.
 * Si las dos leen «hay 12.500 a L 10» y las dos escriben su promedio, la
 * segunda pisa a la primera y el costo de una compra entera desaparece.
 *
 * Por eso la fila se lee con `lockForUpdate()` dentro de la transacción:
 * la segunda espera, y cuando entra ya lee el promedio que dejó la
 * primera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TODO CON bcmath
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.6.2 sin excepciones. Un promedio ponderado es una división seguida
 * de multiplicaciones encadenadas: es exactamente donde el punto flotante
 * acumula el error que a los seis meses no cuadra con contabilidad.
 */
final class CalculadoraDeCostoPromedio
{
    /**
     * Mezcla lo que entra con lo que había y deja el promedio nuevo.
     *
     * ⚠️ Tiene que llamarse DENTRO de una transacción: el candado de la
     * fila solo vale hasta el commit.
     *
     * @param Decimal $cantidad en unidades de dispensación, siempre positiva
     * @param Decimal $costoUnitario lo que costó cada unidad, impuesto incluido
     */
    public function absorber(
        Item $item,
        Almacen $almacen,
        Decimal $cantidad,
        Decimal $costoUnitario,
    ): Decimal {
        $fila = $this->filaBloqueada($item, $almacen);

        $tenia = Decimal::de($fila->cantidad_base);
        $costoActual = Decimal::de($fila->costo_unitario);

        $totalDespues = $tenia->sumar($cantidad);

        /*
         * Si no había nada, el promedio ES el costo de lo que entra. Sin
         * esta guarda la fórmula dividiría entre cero cuando además la
         * cantidad que entra fuera cero.
         */
        $promedio = $totalDespues->esCero()
            ? $costoUnitario
            : $tenia->por($costoActual)
                ->sumar($cantidad->por($costoUnitario))
                ->entre($totalDespues);

        $fila->costo_unitario = $promedio->paraBase(6);
        $fila->cantidad_base = $totalDespues->paraBase(4);
        $fila->actualizado_en = now();
        $fila->save();

        return $promedio;
    }

    /**
     * Cuánto vale hoy una unidad, o cero si nunca se compró.
     *
     * Cero y no nulo a propósito: quien pregunta el costo de algo que
     * nunca entró está preguntando por inventario que no existe, y un
     * cero se suma sin romper nada. Que un producto CON existencia tenga
     * costo cero sí es un hallazgo, y lo contesta el reporte de
     * valorización, no este método.
     */
    public function vigente(Item $item, Almacen $almacen): Decimal
    {
        $fila = CostoPromedio::query()
            ->where('item_id', $item->id)
            ->where('almacen_id', $almacen->id)
            ->first();

        return $fila instanceof CostoPromedio
            ? Decimal::de($fila->costo_unitario)
            : Decimal::cero();
    }

    /**
     * Cuánto vale todo lo que hay de este ítem en ese almacén.
     */
    public function valorDeLaExistencia(Item $item, Almacen $almacen, Decimal $existencia): Decimal
    {
        return $existencia->por($this->vigente($item, $almacen));
    }

    /**
     * La fila del costo, creándola en cero si es la primera compra.
     *
     * Se crea FUERA del candado y se relee bloqueada, por lo mismo que en
     * el registrador de movimientos: en PostgreSQL un INSERT fallido
     * aborta la transacción entera, así que el try/catch de toda la vida
     * necesitaría un SAVEPOINT.
     */
    private function filaBloqueada(Item $item, Almacen $almacen): CostoPromedio
    {
        $bloqueada = fn (): ?CostoPromedio => CostoPromedio::query()
            ->where('item_id', $item->id)
            ->where('almacen_id', $almacen->id)
            ->lockForUpdate()
            ->first();

        $fila = $bloqueada();

        if ($fila instanceof CostoPromedio) {
            return $fila;
        }

        try {
            CostoPromedio::query()->create([
                'item_id'        => $item->id,
                'almacen_id'     => $almacen->id,
                'costo_unitario' => '0',
                'cantidad_base'  => '0',
            ]);
        } catch (QueryException $e) {
            /*
             * Otra transacción la creó primero. El índice único hizo su
             * trabajo; volvemos a buscar la que ganó.
             */
            $fila = $bloqueada();

            if ($fila instanceof CostoPromedio) {
                return $fila;
            }

            throw $e;
        }

        $fila = $bloqueada();

        if ($fila instanceof CostoPromedio) {
            return $fila;
        }

        throw new RuntimeException(
            'No se pudo crear ni encontrar la fila de costo promedio; esto no debería pasar.'
        );
    }

    /**
     * Recalcula el promedio desde cero, leyendo TODAS las recepciones.
     *
     * Es la contracara de `segunElKardex()`: la tabla de costos es un
     * caché y esto es la verdad. Sirve para reparar después de una
     * corrección manual y para el test que verifica que el caché no se
     * divorció de la historia.
     */
    public function recalcularDesdeLasRecepciones(Item $item, Almacen $almacen): Decimal
    {
        /*
         * Las dos sumas van en la MISMA consulta y calculadas por
         * PostgreSQL sobre `numeric`, que es aritmética exacta igual que
         * bcmath. Traerse las líneas a PHP para sumarlas una por una
         * daría el mismo número recorriendo dos años de compras.
         */
        $fila = DB::table('recepcion_lineas')
            ->join('recepciones', 'recepcion_lineas.recepcion_id', '=', 'recepciones.id')
            ->where('recepcion_lineas.item_id', $item->id)
            ->where('recepciones.almacen_id', $almacen->id)
            ->whereNull('recepciones.deleted_at')
            ->selectRaw('COALESCE(sum(recepcion_lineas.cantidad_dispensacion), 0) as cantidad')
            ->selectRaw(
                'COALESCE(sum(recepcion_lineas.cantidad_dispensacion '
                .'* recepcion_lineas.costo_unitario), 0) as costo'
            )
            ->first();

        if ($fila === null) {
            return Decimal::cero();
        }

        $cantidad = Decimal::de(self::comoTexto($fila->cantidad));
        $costo = Decimal::de(self::comoTexto($fila->costo));

        return $cantidad->esCero() ? Decimal::cero() : $costo->entre($cantidad);
    }

    private static function comoTexto(mixed $valor): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        if (is_int($valor)) {
            return (string) $valor;
        }

        if (is_float($valor)) {
            return number_format($valor, 6, '.', '');
        }

        return '0';
    }
}
