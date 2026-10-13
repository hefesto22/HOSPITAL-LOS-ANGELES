<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Prende o apaga el stock infinito de uno o varios productos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA (Mauricio, 13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Al MARCARLO infinito, lo que tenía «pasa a infinito»: su existencia
 *     se da de baja, con una línea en el kardex que lo dice.
 *   · MIENTRAS es infinito no se le recibe mercadería; lo que se usa no
 *     descuenta nada, pero queda en el cargo —qué, cuánto, cuándo y de
 *     dónde— para el reporte de consumo.
 *   · Al PASARLO A REAL arranca en cero, y desde ahí se recibe lo contado.
 *
 * Y DÓNDE ESTÁ (13-oct-2026, la segunda vuelta): el infinito no tiene
 * cantidad pero sí lugares (`UbicadorDeProducto`). Al marcarlo queda en
 * los almacenes donde tenía existencia —si tenía en bodega y en el carrito
 * rojo, sigue estando ahí—, y si no tenía en ninguno, en farmacia. Al
 * pasarlo a real se le cierran todos: desde ahí está donde esté su saldo.
 *
 * Así un producto en infinito nunca tiene existencia, y no hay dos
 * números distintos diciendo cuánto hay.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN SERVICIO Y NO UN UPDATE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cambiar la bandera mueve el kardex, y eso no puede vivir en un evento
 * del modelo (§9.A12): se dispararía también en seeders e imports. Acá
 * pasan la ficha del producto y la acción en bloque del listado.
 *
 * 🔴 Los estupefacientes y psicotrópicos se SALTAN al marcar, y se cuentan
 * aparte para decirlo en pantalla: su libro exige existencia real ante
 * ARSA (§9.F11).
 */
final class CambiadorDeStockInfinito
{
    public const MOTIVO_AL_MARCAR = 'PASÓ A STOCK INFINITO: LO QUE HABÍA YA NO SE CUENTA';

    public const MOTIVO_AL_QUITAR = 'PASÓ A STOCK REAL: ARRANCA EN CERO';

    public const MOTIVO_DE_LOS_LUGARES = 'PASÓ A STOCK REAL: ESTÁ DONDE ESTÉ SU EXISTENCIA';

    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
        private readonly UbicadorDeProducto $ubicaciones,
        private readonly FormasDeCobro $formas,
    ) {}

    /**
     * Marcar otra vez uno que ya es infinito no cambia la bandera, pero sí
     * da de baja lo que le haya quedado: así se limpian los que se
     * marcaron antes de que existiera esta regla.
     *
     * @param iterable<Item> $items
     *
     * @return array{cambiados: int, controlados: int}
     */
    public function cambiar(iterable $items, bool $infinito): array
    {
        /** @var array{cambiados: int, controlados: int} $resultado */
        $resultado = DB::transaction(function () use ($items, $infinito): array {
            $cambiados = 0;
            $controlados = 0;

            foreach ($items as $item) {
                if (! $item->mueveInventario()) {
                    continue;
                }

                if ($infinito && $item->es_controlado) {
                    $controlados++;

                    continue;
                }

                $cambia = (bool) $item->stock_infinito !== $infinito;

                /*
                 * La baja va ANTES de prender la bandera: con la bandera
                 * puesta ya no se podría mover nada de este producto.
                 *
                 * 🔴 Al QUITAR, solo si de verdad era infinito. La acción
                 * en bloque se aplica a una selección, y un producto que ya
                 * estaba en real tiene existencia CONTADA: pasarle «quitar
                 * infinito» no puede darle de baja lo que hay en el
                 * estante.
                 */
                $dondeHabia = $infinito || $cambia
                    ? $this->dejarEnCero($item, $infinito ? self::MOTIVO_AL_MARCAR : self::MOTIVO_AL_QUITAR)
                    : [];

                if ($cambia) {
                    $item->stock_infinito = $infinito;
                    $item->save();
                }

                if ($infinito) {
                    $this->dejarloDondeEstaba($item, $dondeHabia);
                } elseif ($cambia) {
                    $this->ubicaciones->cerrarTodas($item, self::MOTIVO_DE_LOS_LUGARES);
                }

                if ($cambia || $dondeHabia !== []) {
                    $cambiados++;
                }
            }

            return ['cambiados' => $cambiados, 'controlados' => $controlados];
        });

        return $resultado;
    }

    /**
     * «Si tenía stock pasa a infinito»: queda en cada almacén donde había
     * algo, con los envases que había —cajas enteras, blísteres, o solo
     * unidades sueltas—. Si no había en ninguno y tampoco tiene lugares
     * anotados, en farmacia —donde nace todo producto nuevo—, de todo.
     *
     * @param list<array{almacen: Almacen, envases: list<int>}> $dondeHabia
     */
    private function dejarloDondeEstaba(Item $item, array $dondeHabia): void
    {
        foreach ($dondeHabia as $lugar) {
            $this->ubicaciones->agregarEnvases($item, $lugar['almacen'], $lugar['envases']);
        }

        if ($this->ubicaciones->dondeEsta($item) !== []) {
            return;
        }

        foreach (Almacen::query()->whereIn('id', $this->ubicaciones->porDefecto())->get() as $farmacia) {
            $this->ubicaciones->ubicar($item, $farmacia);
        }
    }

    /**
     * Da de baja todo lo que el producto tenga, en cada estante y lote,
     * con un ajuste negativo valorizado al costo vigente —igual que
     * `RegistradorDeAjuste`—. Devuelve los almacenes donde había algo,
     * sin repetir, con los envases que había ENTEROS en cada uno: un lote
     * de cajas con 60 tabletas es una caja abierta, y ahí hay blísteres
     * pero ninguna caja (`FormasDeCobro::sinAbrirEnElLote()`).
     *
     * Se buscan los almacenes de TODAS las sedes: la bandera es del
     * producto, no de una sede, y una existencia escondida por el filtro
     * de sede quedaría viva debajo de un producto infinito.
     *
     * @return list<array{almacen: Almacen, envases: list<int>}>
     */
    private function dejarEnCero(Item $item, string $motivo): array
    {
        /*
         * Con candado (§9.G2): entre leer el saldo y asentar la baja, una
         * dispensación no puede sacar nada de estas filas.
         */
        $filas = Existencia::query()
            ->where('item_id', $item->id)
            ->conSaldo()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var array<int, array{almacen: Almacen, envases: list<int>}> $dondeHabia */
        $dondeHabia = [];

        $envases = $this->ubicaciones->envasesDe($item);

        foreach ($filas as $fila) {
            $almacen = Almacen::query()->deTodasLasSedes()->find($fila->almacen_id);

            if (! $almacen instanceof Almacen) {
                continue;
            }

            $cantidad = $fila->cantidadDecimal();
            $vigente = $this->costos->vigenteBloqueado($item, $almacen);
            $lote = $fila->lote_id === null ? null : $fila->lote;

            $dondeHabia[$almacen->id] ??= ['almacen' => $almacen, 'envases' => []];

            foreach ($envases as $envase) {
                $enteros = $this->formas->sinAbrirEnElLote($envase, $lote?->item_presentacion_id, $cantidad, $envases);

                if ($enteros->mayorQue('0') && ! in_array((int) $envase->id, $dondeHabia[$almacen->id]['envases'], true)) {
                    $dondeHabia[$almacen->id]['envases'][] = (int) $envase->id;
                }
            }

            $this->movimientos->registrar(
                item: $item,
                lote: $lote,
                almacen: $almacen,
                tipo: TipoMovimiento::AjusteNegativo,
                cantidad: $cantidad,
                motivo: $motivo,
                referencia: 'STOCK INFINITO',
                costoUnitario: $vigente,
                costoPromedioDespues: $vigente,
            );

            $this->costos->sincronizarCantidadBase($item, $almacen);
        }

        return array_values($dondeHabia);
    }
}
