<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Item;
use App\Models\Lote;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué se usó con los pacientes en un período, cuánto y de qué estante
 * salió: los números de «Consumo de productos».
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ FUERA DE LA PÁGINA (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio pidió que el consumo «se pueda imprimir». La hoja impresa es
 * una ruta web, no la página de Livewire, y tiene que decir EXACTAMENTE
 * lo mismo que la pantalla para el mismo período y el mismo estante. Si
 * cada una armara sus consultas, un día dirían dos cosas distintas. Las
 * reglas viven acá y las dos leen de acá.
 *
 * Lo que cuenta y lo que no —por fecha del hospital, solo pendientes o
 * facturados, la unidad de dispensación— está explicado en
 * `ConsumoDeProductos`.
 *
 * Todo filtra por la sede de quien mira: `Cargo` ya trae ese alcance.
 */
final class ConsumoDelPeriodo
{
    /**
     * El estante que significa «los cargos sin almacén»: los de antes del
     * 13-oct, cuando el estante de un producto sin existencia no se
     * guardaba.
     */
    public const SIN_ALMACEN = 0;

    /**
     * El papel cubre como máximo un año, como el Reporte del mes.
     */
    public const DIAS_MAXIMOS = 366;

    /**
     * Cuántos usos, uno por uno, entran en el papel. Un año de farmacia
     * son miles de renglones: de ahí para arriba se avisa en la hoja y se
     * pide un período más corto, en vez de mandar cien páginas a la
     * impresora.
     */
    public const USOS_EN_EL_PAPEL = 1500;

    /**
     * Los cargos de productos que se almacenan, vivos, del período. Es la
     * única fuente de la pantalla y del papel.
     *
     * ⚠️ Pendiente o facturado, y nada más: `anulado` y `anulacion` son
     * el error y su corrección —se cancelan— y `trasladado` ya cuenta en
     * la cuenta a la que se fue.
     *
     * @return Builder<Cargo>
     */
    public function usos(string $desde, string $hasta): Builder
    {
        return Cargo::query()
            ->whereIn('cargos.estado', [EstadoCargo::Pendiente->value, EstadoCargo::Facturado->value])
            ->whereBetween('cargos.fecha_operacion', [$desde, $hasta])
            ->whereHas('item', fn (Builder $item): Builder => $item->where('se_almacena', true));
    }

    /**
     * Los usos de un estante, o de todos si es nulo.
     *
     * @return Builder<Cargo>
     */
    public function usosDelAlmacen(string $desde, string $hasta, ?int $almacen): Builder
    {
        $consulta = $this->usos($desde, $hasta);

        if ($almacen === self::SIN_ALMACEN) {
            $consulta->whereNull('cargos.almacen_id');
        } elseif ($almacen !== null) {
            $consulta->where('cargos.almacen_id', $almacen);
        }

        return $consulta;
    }

    /**
     * El filtro «Origen»: lo que salió sin inventario (stock infinito) o
     * lo que descontó el kardex. Cualquier otro valor no filtra.
     *
     * @template TModelo of Model
     *
     * @param Builder<TModelo> $consulta
     */
    public static function filtrarPorOrigen(Builder $consulta, mixed $origen): void
    {
        if ($origen === 'sin_inventario') {
            $consulta->whereNull('cargos.movimiento_id');
        } elseif ($origen === 'inventario') {
            $consulta->whereNotNull('cargos.movimiento_id');
        }
    }

    /**
     * Cuántos usos tuvo cada estante (`SIN_ALMACEN` para los que no
     * tienen). Una sola consulta agrupada.
     *
     * @return array<int, int>
     */
    public function vecesPorAlmacen(string $desde, string $hasta): array
    {
        $filas = $this->usos($desde, $hasta)
            ->selectRaw('cargos.almacen_id, count(*) as veces')
            ->groupBy('cargos.almacen_id')
            ->toBase()
            ->get();

        $veces = [];

        foreach ($filas as $fila) {
            $veces[$fila->almacen_id === null ? self::SIN_ALMACEN : (int) $fila->almacen_id] = (int) $fila->veces;
        }

        return $veces;
    }

    /**
     * Los estantes de los que salió algo, con cuántos usos cada uno, por
     * nombre y «Sin estante» al final.
     *
     * @param array<int, int> $veces lo que devolvió `vecesPorAlmacen()`
     *
     * @return list<array{id: int, nombre: string, veces: int}>
     */
    public function almacenes(array $veces): array
    {
        if ($veces === []) {
            return [];
        }

        /*
         * `deTodasLasSedes()`: el cargo ya viene de la sede de quien mira,
         * y su almacén es de esa misma sede. Quitar el filtro solo evita
         * que un estante sin nombre deje la fila en blanco.
         */
        $lista = array_values(Almacen::query()
            ->deTodasLasSedes()
            ->whereIn('id', array_keys($veces))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Almacen $almacen): array => [
                'id'     => (int) $almacen->id,
                'nombre' => $almacen->nombre,
                'veces'  => $veces[(int) $almacen->id] ?? 0,
            ])
            ->all());

        if (isset($veces[self::SIN_ALMACEN])) {
            $lista[] = [
                'id'     => self::SIN_ALMACEN,
                'nombre' => 'Sin estante',
                'veces'  => $veces[self::SIN_ALMACEN],
            ];
        }

        return $lista;
    }

    /**
     * Una fila por producto usado en el período —en el estante elegido—,
     * del más usado al menos usado.
     *
     * CUATRO consultas en total, sin importar cuántos productos sean: los
     * totales por producto, el reparto por almacén, los nombres de los
     * almacenes y los productos. Un renglón que consultara su propio
     * reparto serían cien consultas en un mes movido (§13.2).
     *
     * @return list<array{id: int, nombre: string, codigo: string, infinito: bool, veces: int, cantidad: string, deDonde: string}>
     */
    public function porProducto(string $desde, string $hasta, ?int $almacen): array
    {
        $totales = $this->usosDelAlmacen($desde, $hasta, $almacen)
            ->selectRaw('cargos.item_id, count(*) as veces, sum(cargos.cantidad) as cantidad')
            ->groupBy('cargos.item_id')
            ->toBase()
            ->get();

        if ($totales->isEmpty()) {
            return [];
        }

        /*
         * El reparto por almacén, para la línea «FARMACIA 30 · EMERGENCIA
         * 5». Con un estante elegido arriba sería redundante, pero igual
         * sale de la misma consulta y dice lo mismo.
         */
        $reparto = $this->usosDelAlmacen($desde, $hasta, $almacen)
            ->selectRaw('cargos.item_id, cargos.almacen_id, sum(cargos.cantidad) as cantidad')
            ->groupBy('cargos.item_id', 'cargos.almacen_id')
            ->toBase()
            ->get();

        $idsDeAlmacen = $reparto->pluck('almacen_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $nombres = $idsDeAlmacen === []
            ? []
            : Almacen::query()
                ->deTodasLasSedes()
                ->whereIn('id', $idsDeAlmacen)
                ->pluck('nombre', 'id')
                ->all();

        $items = Item::query()
            ->with('unidadDispensacion')
            ->whereIn('id', $totales->pluck('item_id')->map(fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        /** @var array<int, list<array{nombre: string, cantidad: numeric-string}>> $porItem */
        $porItem = [];

        foreach ($reparto as $fila) {
            $porItem[(int) $fila->item_id][] = [
                'nombre'   => $fila->almacen_id === null ? 'SIN ESTANTE' : (string) ($nombres[(int) $fila->almacen_id] ?? '—'),
                'cantidad' => self::numero($fila->cantidad),
            ];
        }

        $filas = [];

        foreach ($totales as $total) {
            $item = $items->get((int) $total->item_id);

            if (! $item instanceof Item) {
                continue;
            }

            $donde = $porItem[(int) $item->id] ?? [];

            usort($donde, fn (array $a, array $b): int => bccomp($b['cantidad'], $a['cantidad'], 4));

            $filas[] = [
                'id'       => (int) $item->id,
                'nombre'   => $item->nombre,
                'codigo'   => (string) $item->codigo,
                'infinito' => $item->tieneStockInfinito(),
                'veces'    => (int) $total->veces,
                'cantidad' => self::conUnidad(self::numero($total->cantidad), $item->unidadDispensacion),
                'deDonde'  => implode(' · ', array_map(
                    fn (array $lugar): string => $lugar['nombre'].' '.self::sinCeros($lugar['cantidad']),
                    $donde,
                )),
            ];
        }

        /*
         * Del más usado al menos usado, y a igual uso por nombre. Por
         * VECES y no por cantidad: 500 ML de suero y 2 ampollas no se
         * comparan, pero cuántas veces se recurrió a cada uno sí.
         */
        usort($filas, fn (array $a, array $b): int => [$b['veces'], $a['nombre']] <=> [$a['veces'], $b['nombre']]);

        return $filas;
    }

    /**
     * Cada uso, uno por uno, para el papel: del más reciente al más
     * viejo, como la tabla de la pantalla, hasta `$limite`. `total` dice
     * cuántos hay en realidad, para avisar si no entraron todos.
     *
     * ⚠️ Sin enumerar columnas en el `with`: una columna que falte no da
     * error, devuelve NULL y el papel miente en silencio (la lección de
     * Existencias).
     *
     * @return array{total: int, filas: list<array{cuando: string, producto: string, codigo: string, cantidad: string, almacen: string, origen: string, paciente: string, cuenta: string}>}
     */
    public function unoPorUno(string $desde, string $hasta, ?int $almacen, ?int $producto, mixed $origen, int $limite): array
    {
        $consulta = $this->usosDelAlmacen($desde, $hasta, $almacen);

        if ($producto !== null) {
            $consulta->where('cargos.item_id', $producto);
        }

        self::filtrarPorOrigen($consulta, $origen);

        $total = (clone $consulta)->count();

        $cargos = $consulta
            ->with(['item.unidadDispensacion', 'almacen', 'lote', 'cuenta', 'encuentro.persona'])
            ->orderByDesc('cargos.ocurrido_en')
            ->orderByDesc('cargos.id')
            ->limit($limite)
            ->get();

        $filas = [];

        foreach ($cargos as $cargo) {
            $filas[] = [
                'cuando'   => $cargo->ocurrido_en->format('d/m/Y H:i'),
                'producto' => $cargo->item->nombre,
                'codigo'   => (string) $cargo->item->codigo,
                'cantidad' => self::conUnidad($cargo->cantidad, $cargo->item->unidadDispensacion),
                'almacen'  => $cargo->almacen instanceof Almacen ? $cargo->almacen->nombre : 'Sin estante',
                'origen'   => self::origenDe($cargo),
                'paciente' => $cargo->encuentro->persona->nombreCompleto(),
                'cuenta'   => (string) $cargo->cuenta->numero,
            ];
        }

        return ['total' => $total, 'filas' => $filas];
    }

    // ── Cómo se lee ───────────────────────────────────────────────────

    /**
     * «Stock infinito» o «Lote L-123»: si ese uso descontó inventario.
     */
    public static function origenDe(Cargo $cargo): string
    {
        if ($cargo->movimiento_id === null) {
            return 'Stock infinito';
        }

        return $cargo->lote instanceof Lote ? 'Lote '.$cargo->lote->numero : 'Del inventario';
    }

    /**
     * «35 TAB». Sin la unidad, el número no dice nada.
     */
    public static function conUnidad(string $cantidad, ?Unidad $unidad): string
    {
        $numero = self::sinCeros($cantidad);

        return $unidad instanceof Unidad ? $numero.' '.$unidad->codigo : $numero;
    }

    /**
     * Lo que devuelve `sum()` —int, float o string—, como cadena exacta.
     *
     * @return numeric-string
     */
    private static function numero(mixed $valor): string
    {
        if (is_string($valor) && is_numeric($valor)) {
            return Decimal::de($valor)->redondeado(4);
        }

        if (is_int($valor)) {
            return Decimal::de($valor)->redondeado(4);
        }

        if (is_float($valor)) {
            return Decimal::deFloat($valor)->redondeado(4);
        }

        return '0.0000';
    }

    private static function sinCeros(string $numero): string
    {
        if (! str_contains($numero, '.')) {
            return $numero;
        }

        $limpio = rtrim(rtrim($numero, '0'), '.');

        return $limpio === '' ? '0' : $limpio;
    }
}
