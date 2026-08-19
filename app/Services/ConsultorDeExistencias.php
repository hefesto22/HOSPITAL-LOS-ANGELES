<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\MovimientoKardex;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cuánto hay, dónde, y cuál sale primero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * FEFO Y NO FIFO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se despacha **lo que vence primero**, no lo que entró primero. Con
 * medicamentos la diferencia no es teórica: con FIFO, el lote viejo que
 * entró después se queda al fondo del estante hasta que vence, y el
 * hospital paga esa pérdida dos veces —el producto y la baja.
 *
 * Los lotes sin fecha de vencimiento van al final: no corren riesgo, así
 * que no tienen por qué desplazar a los que sí.
 */
final class ConsultorDeExistencias
{
    /**
     * Cuánto hay de este ítem en este almacén, sumando todos sus lotes.
     */
    public function totalEn(Item $item, Almacen $almacen): Decimal
    {
        $suma = Existencia::query()
            ->where('item_id', $item->id)
            ->where('almacen_id', $almacen->id)
            ->sum('cantidad');

        return Decimal::de(self::comoTexto($suma));
    }

    /**
     * Cuánto hay de este ítem en todo el hospital.
     */
    public function totalGlobal(Item $item): Decimal
    {
        $suma = Existencia::query()
            ->where('item_id', $item->id)
            ->sum('cantidad');

        return Decimal::de(self::comoTexto($suma));
    }

    /**
     * Los lotes con saldo en ese almacén, en el orden en que deben
     * salir: primero el que vence antes.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ TODAS LAS COLUMNAS LLEVAN SU TABLA ADELANTE
     * ─────────────────────────────────────────────────────────────────
     *
     * `lotes` también tiene `item_id`. En cuanto entra el join, un
     * `where('item_id', ...)` a secas deja de tener un solo significado y
     * PostgreSQL corta la consulta —«column reference is ambiguous»—: no
     * devuelve una fila de más, se cae. La regla acá es simple: **si hay
     * join, todo lleva prefijo**, incluso lo que hoy es único.
     *
     * El join va ANTES de los `where` a propósito, para que quien lea el
     * método vea primero que hay dos tablas en juego y entienda por qué
     * los filtros están calificados.
     *
     * @return Collection<int, Existencia>
     */
    public function enOrdenFefo(Item $item, Almacen $almacen): Collection
    {
        return Existencia::query()
            ->with('lote')
            ->leftJoin('lotes', 'existencias.lote_id', '=', 'lotes.id')
            ->where('existencias.item_id', $item->id)
            ->where('existencias.almacen_id', $almacen->id)
            ->conSaldo()
            ->orderByRaw('lotes.fecha_vencimiento asc nulls last')
            ->orderBy('existencias.id')
            ->select('existencias.*')
            ->get();
    }

    /**
     * Los lotes con saldo que ya vencieron a esa fecha.
     *
     * Es lo que el hospital tiene que sacar del estante y dar de baja. Un
     * medicamento vencido en el estante es un hallazgo de ARSA, no un
     * descuido menor.
     *
     * @return Collection<int, Existencia>
     */
    public function vencidosAl(CarbonInterface $fecha, ?Almacen $almacen = null): Collection
    {
        return Existencia::query()
            ->with(['lote', 'item', 'almacen'])
            ->conSaldo()
            ->when(
                $almacen instanceof Almacen,
                fn (Builder $consulta): Builder => $consulta->where('almacen_id', $almacen?->id),
            )
            ->whereHas('lote', function (Builder $sub) use ($fecha): void {
                $sub->whereNotNull('fecha_vencimiento')
                    ->whereDate('fecha_vencimiento', '<', $fecha->toDateString());
            })
            ->get();
    }

    /**
     * El saldo según el KARDEX, que es la verdad.
     *
     * ⚠️ Esto no es lo mismo que `totalEn()`. Aquello lee la tabla de
     * saldos —rápida, calculada—; esto suma los movimientos uno por uno.
     * Los dos números tienen que coincidir siempre, y hay un test que lo
     * verifica. El día que no coincidan, **el kardex gana** y el saldo se
     * recalcula: la historia no se ajusta al resumen.
     */
    public function segunElKardex(Item $item, Almacen $almacen): Decimal
    {
        $suma = MovimientoKardex::query()
            ->delItemEnElAlmacen($item->id, $almacen->id)
            ->sum('cantidad');

        return Decimal::de(self::comoTexto($suma));
    }

    /**
     * `sum()` devuelve lo que le da el driver —int, float o string— y
     * ninguna de las tres se puede pasar directo a `Decimal`, que
     * rechaza el punto flotante a propósito (§8.6.2).
     */
    private static function comoTexto(mixed $suma): string
    {
        if (is_string($suma)) {
            return $suma;
        }

        if (is_int($suma)) {
            return (string) $suma;
        }

        if (is_float($suma)) {
            return number_format($suma, 4, '.', '');
        }

        return '0';
    }
}
