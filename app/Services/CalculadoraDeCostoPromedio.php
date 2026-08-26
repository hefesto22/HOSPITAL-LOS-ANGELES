<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\CostoPromedio;
use App\Models\Item;
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
 * 🔴 `cantidad_base` TIENE QUE SEGUIR A LA EXISTENCIA REAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es el promedio ponderado **MÓVIL**: se calcula contra lo que hay hoy en
 * el estante, no contra el histórico de compras. La consecuencia está
 * escrita en `docs/dominio-inventario-y-precios.md`: *si un producto se
 * agota y se vuelve a comprar, el promedio arranca del costo nuevo*.
 *
 * Eso solo se cumple si `cantidad_base` BAJA cuando baja la existencia.
 * Con una cantidad base que solo sube:
 *
 *     Compra 1 · 100 u a L 10,00  →  base 100 · promedio L 10,00
 *     Se despachan las 100        →  base sigue en 100   ← acá se rompe
 *     Compra 2 ·  10 u a L 20,00  →  (100×10 + 10×20)/110 = L 10,909091
 *
 * El promedio correcto es L 20,00. Con el equivocado, y aplicando margen
 * objetivo 120 % sobre un descuento legal máximo del 25 %, el precio de
 * lista sale en L 32,00 en vez de L 58,67: un paciente de tercera edad
 * paga L 24,00 por algo que costó L 20,00. El piso de margen del 120 %
 * queda en 20 %, y nadie se entera hasta el cierre del año.
 *
 * Por eso `sincronizarCantidadBase()` existe y por eso **la llama todo
 * movimiento que cambie la existencia sin traer costo nuevo**: los
 * ajustes hoy, la dispensación cuando exista.
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
     * Vuelve a poner `cantidad_base` igual a lo que hay de verdad, sin
     * tocar el costo unitario.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ SE RECALCULA Y NO SE APLICA UN DELTA
     * ─────────────────────────────────────────────────────────────────
     *
     * Sumarle a `cantidad_base` la misma cantidad que se movió sería más
     * barato y estaría bien **mientras nadie se equivoque nunca**. Un
     * recálculo contra `existencias` no puede derivar: es idempotente,
     * se autorrepara si alguna vez quedó torcida, y cuesta una suma
     * indexada sobre los pocos lotes que ese ítem tiene en ese almacén.
     *
     * En una tabla que decide el precio de venta de todo el catálogo, esa
     * diferencia vale mucho más que la consulta que ahorra.
     *
     * ─────────────────────────────────────────────────────────────────
     * EL COSTO NO SE TOCA, Y ES LA REGLA ENTERA DE UN AJUSTE
     * ─────────────────────────────────────────────────────────────────
     *
     * Un ajuste dice *cuántos hay*, no *cuánto valen*. No trae
     * información de costo: nadie le pagó nada a nadie por las cinco
     * ampollas que aparecieron en el estante. Mezclarlas al promedio con
     * un costo inventado —cero, o el último de compra— movería el precio
     * de venta de todo el producto por un error de conteo.
     *
     * ⚠️ Va DENTRO de la transacción y DESPUÉS del movimiento: lee la
     * existencia ya movida, con la fila del costo bloqueada, así que dos
     * ajustes simultáneos del mismo ítem se serializan y el segundo lee
     * lo que dejó el primero.
     *
     * @return Decimal el costo unitario vigente, que NO cambió
     */
    public function sincronizarCantidadBase(Item $item, Almacen $almacen): Decimal
    {
        $fila = $this->filaBloqueada($item, $almacen);

        $fila->cantidad_base = $this->existenciaReal($item, $almacen)->paraBase(4);
        $fila->actualizado_en = now();
        $fila->save();

        return Decimal::de($fila->costo_unitario);
    }

    /**
     * El costo vigente, leído con la fila bloqueada.
     *
     * Es lo que se usa para valorizar un ajuste: tomar el candado ANTES
     * de mover nada serializa las operaciones sobre ese ítem y ese
     * almacén, y evita que dos ajustes simultáneos valoricen contra
     * promedios distintos y después uno pise al otro.
     *
     * ⚠️ Solo dentro de una transacción.
     */
    public function vigenteBloqueado(Item $item, Almacen $almacen): Decimal
    {
        return Decimal::de($this->filaBloqueada($item, $almacen)->costo_unitario);
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
     * La suma de las existencias reales de ese ítem en ese almacén,
     * sumando todos sus lotes.
     *
     * La suma la hace PostgreSQL sobre `numeric`, que es aritmética
     * exacta igual que bcmath. Traerse las filas a PHP para sumarlas
     * daría el mismo número con más viajes.
     */
    public function existenciaReal(Item $item, Almacen $almacen): Decimal
    {
        $suma = DB::table('existencias')
            ->where('existencias.item_id', $item->id)
            ->where('existencias.almacen_id', $almacen->id)
            ->sum('existencias.cantidad');

        return Decimal::de(self::comoTexto($suma));
    }

    /**
     * La fila del costo, creándola en cero si es la primera vez.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 `insertOrIgnore` Y NO `create()` CON try/catch
     * ─────────────────────────────────────────────────────────────────
     *
     * Este método corre SIEMPRE dentro de una transacción, y en
     * PostgreSQL un `INSERT` que revienta contra un índice único **aborta
     * la transacción entera**: todo lo que venga después falla con
     * «current transaction is aborted», incluido el `SELECT` del bloque
     * `catch`. El try/catch de toda la vida acá no atrapa nada — necesita
     * un SAVEPOINT para servir de algo.
     *
     * `insertOrIgnore` emite `INSERT ... ON CONFLICT DO NOTHING`, que no
     * lanza y no aborta: si otra transacción ganó la carrera, esperamos a
     * que commitee, no insertamos, y la releemos bloqueada.
     *
     * (Es la diferencia con `RegistradorDeMovimiento::saldoDe()` y con
     * `AbridorDeConteo::abrir()`, que hacen su try/catch FUERA de la
     * transacción — ahí el patrón sí es correcto.)
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

        DB::table('costos_promedio')->insertOrIgnore([
            'item_id'        => $item->id,
            'almacen_id'     => $almacen->id,
            'costo_unitario' => '0',
            'cantidad_base'  => '0',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

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
