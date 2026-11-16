<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\ConsumoDeCargo;
use App\Models\ConsumoDeServicio;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE GASTA UN EXAMEN, AL CARGARLO (16-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «una caja de 25 kits es para 25 exámenes; se usa 1, quedan
 * 24; ingresa otra caja de 26, serían 50… cuando el stock es 0 no se
 * pueden agregar más de esos exámenes en el hospital, pero sí de
 * laboratorio externo».
 *
 * Cada examen dice qué gasta (`ConsumoDeServicio`, en su ficha). Al
 * cargarlo, esto saca esas pruebas del estante del laboratorio, del lote
 * que vence primero, y anota qué salió de dónde (`ConsumoDeCargo`). Al
 * anular el cargo, las devuelve.
 *
 *   · Kit en stock infinito: no se mira el estante, se anota cuánto se
 *     usó. Es como arrancan los kits: nada se bloquea hasta que
 *     laboratorio recibe la primera caja y le apaga el infinito.
 *   · Kit con stock real y sin pruebas: el examen NO se carga. El de
 *     afuera sí, porque no gasta nada.
 *   · Lote vencido: no se usa. Un resultado hecho con reactivo vencido
 *     no sirve, y el sistema no lo va a ofrecer como si sirviera.
 *
 * 🔴 Corre DENTRO de la transacción del cargo, después del candado de la
 * cuenta, y toma los candados en el orden de siempre: cuenta → costo →
 * existencia. Si falta una prueba, se cae el cargo entero: no queda un
 * examen cobrado que no gastó lo que gasta.
 */
final class GastosDelServicio
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
        private readonly ConsultorDeExistencias $existencias,
    ) {}

    /**
     * Saca del estante lo que gasta el examen del cargo recién asentado.
     *
     * `$original`: el cargo que se está rehaciendo —un descuento aplicado
     * a la cuenta anula y vuelve a cargar—. Rehacer es rehacerlo IGUAL
     * (§9.M12, §9.M20): gasta lo mismo y del mismo lote que el original,
     * que la anulación acaba de devolver; y si el original no gastó nada,
     * el nuevo tampoco.
     *
     * @throws CargoException
     */
    public function descontar(Cargo $cargo, Item $examen, ?Cargo $original = null): void
    {
        if ($original instanceof Cargo) {
            $this->repetir($cargo, $original);

            return;
        }

        /** @var Collection<int, ConsumoDeServicio> $recetas */
        $recetas = $examen->gasta()->whereHas('insumo')->with('insumo')->orderBy('id')->get();

        if ($recetas->isEmpty()) {
            return;
        }

        $almacen = $this->almacenDelLaboratorio($cargo->sede_id);

        if (! $almacen instanceof Almacen) {
            throw CargoException::sinAlmacenDelLaboratorio($examen->nombre);
        }

        /*
         * Dos glucosas en la misma línea gastan dos pruebas.
         */
        $veces = Decimal::de($cargo->cantidad);

        foreach ($recetas as $receta) {
            $insumo = $receta->insumo;
            $cantidad = $receta->cantidadDecimal()->por($veces);

            if (self::seAnotaSinContar($insumo)) {
                $this->anotar($cargo, $insumo, $almacen, null, null, $cantidad, null);

                continue;
            }

            $this->sacar($cargo, $examen, $insumo, $almacen, $cantidad);
        }
    }

    /**
     * Devuelve al estante lo que gastó el cargo que se está anulando.
     *
     * Contra el mismo lote y el mismo almacén, con el costo congelado del
     * día que salió —como la anulación de un medicamento—. Lo anotado en
     * infinito solo deja de contar.
     */
    public function devolver(Cargo $cargo): void
    {
        $gastos = ConsumoDeCargo::query()
            ->deTodasLasSedes()
            ->with(['insumo', 'almacen', 'lote'])
            ->where('cargo_id', $cargo->id)
            ->vigentes()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($gastos as $gasto) {
            $devolucion = null;

            /*
             * Un kit que salió con existencia y después pasó a infinito:
             * lo que vuelve no tiene a dónde volver —al marcarlo infinito
             * su existencia se dio de baja—. Igual que un medicamento.
             */
            if (! $gasto->sin_existencia && ! $gasto->insumo->tieneStockInfinito()) {
                $this->costos->vigenteBloqueado($gasto->insumo, $gasto->almacen);

                $devolucion = $this->movimientos->registrar(
                    item: $gasto->insumo,
                    lote: $gasto->lote_id === null ? null : $gasto->lote,
                    almacen: $gasto->almacen,
                    tipo: TipoMovimiento::EntradaPorDevolucion,
                    cantidad: $gasto->cantidadDecimal(),
                    motivo: 'Anulación del cargo '.$cargo->id,
                    referencia: (string) $cargo->id,
                    ocurridoEn: now(),
                    costoUnitario: $gasto->costo_unitario === null ? null : Decimal::de($gasto->costo_unitario),
                );

                $this->costos->sincronizarCantidadBase($gasto->insumo, $gasto->almacen);
            }

            $gasto->forceFill([
                'revertido_en'  => now(),
                'devolucion_id' => $devolucion instanceof MovimientoKardex ? $devolucion->id : null,
            ])->save();
        }
    }

    /**
     * Por qué este examen hoy no se puede cargar, o null si se puede.
     *
     * Es lo que la pantalla de cargar avisa al elegirlo, antes de que el
     * cargo se caiga con el mismo motivo.
     */
    public function faltaPara(Item $examen, int $sedeId): ?string
    {
        return $this->sinPruebasEntre([(int) $examen->id], $sedeId)[(int) $examen->id] ?? null;
    }

    /**
     * De una lista de ítems, los que hoy NO se pueden cargar por falta de
     * pruebas, con el motivo. Dos consultas para toda la lista: es lo que
     * marca «SIN PRUEBAS» en el buscador de la cuenta.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, string>
     */
    public function sinPruebasEntre(array $itemIds, int $sedeId): array
    {
        if ($itemIds === []) {
            return [];
        }

        /** @var Collection<int, ConsumoDeServicio> $recetas */
        $recetas = ConsumoDeServicio::query()
            ->whereHas('insumo')
            ->with('insumo')
            ->whereIn('item_id', $itemIds)
            ->get();

        if ($recetas->isEmpty()) {
            return [];
        }

        $almacen = $this->almacenDelLaboratorio($sedeId);

        if (! $almacen instanceof Almacen) {
            $sinAlmacen = [];

            foreach ($recetas as $receta) {
                $sinAlmacen[(int) $receta->item_id] = 'Esta sede no tiene almacén de laboratorio.';
            }

            return $sinAlmacen;
        }

        $contados = array_values($recetas
            ->filter(fn (ConsumoDeServicio $receta): bool => ! self::seAnotaSinContar($receta->insumo))
            ->pluck('insumo_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());

        $hay = $this->usablesEn($contados, $almacen);

        $faltan = [];

        foreach ($recetas as $receta) {
            if (self::seAnotaSinContar($receta->insumo)) {
                continue;
            }

            $disponible = Decimal::de($hay[(int) $receta->insumo_id] ?? '0');

            if ($disponible->menorQue($receta->cantidadDecimal())) {
                $faltan[(int) $receta->item_id] = 'No quedan pruebas de «'.$receta->insumo->nombre.'» en '.$almacen->nombre.'.';
            }
        }

        return $faltan;
    }

    /**
     * El estante del laboratorio de la sede: un stock de servicio del
     * servicio de laboratorio (`Almacen::esDelLaboratorio()`). Si hubiera
     * dos, el más viejo.
     */
    public function almacenDelLaboratorio(int $sedeId): ?Almacen
    {
        return Almacen::query()
            ->deTodasLasSedes()
            ->where('sede_id', $sedeId)
            ->delLaboratorio()
            ->vigentes()
            ->orderBy('id')
            ->first();
    }

    // ── Por dentro ────────────────────────────────────────────────────

    /**
     * Lo que se anota sin mirar el estante: el kit en stock infinito, y lo
     * que ni siquiera se guarda (una receta mal armada no puede trabar un
     * examen: la ficha solo ofrece lo que se guarda, pero la base no lo
     * sabe).
     */
    private static function seAnotaSinContar(Item $insumo): bool
    {
        return $insumo->tieneStockInfinito() || ! $insumo->mueveInventario();
    }

    /**
     * Saca del estante, FEFO, sin lotes vencidos. Si no alcanza, no saca
     * nada y el cargo se cae.
     *
     * @throws CargoException
     */
    private function sacar(Cargo $cargo, Item $examen, Item $insumo, Almacen $almacen, Decimal $cantidad): void
    {
        /*
         * 🔴 ORDEN DE CANDADOS: costo → existencia, el mismo de
         * `RegistradorDeCargo` y `AnuladorDeCargo`.
         */
        $costo = $this->costos->vigenteBloqueado($insumo, $almacen);

        $hoy = now()->startOfDay();
        $tomas = [];
        $pendiente = $cantidad;

        foreach ($this->existencias->enOrdenFefo($insumo, $almacen) as $existencia) {
            if ($pendiente->esCero() || $pendiente->esNegativo()) {
                break;
            }

            $lote = $existencia->lote_id === null ? null : $existencia->lote;

            if ($lote instanceof Lote && $lote->fecha_vencimiento !== null && $lote->fecha_vencimiento->lessThan($hoy)) {
                continue;
            }

            $saldo = Decimal::de((string) $existencia->getAttribute('cantidad'));

            if ($saldo->esCero() || $saldo->esNegativo()) {
                continue;
            }

            $toma = $saldo->menorQue($pendiente) ? $saldo : $pendiente;
            $tomas[] = ['lote' => $lote, 'cantidad' => $toma];
            $pendiente = $pendiente->restar($toma);
        }

        if (! $pendiente->esCero() && ! $pendiente->esNegativo()) {
            throw CargoException::sinPruebas(
                examen: $examen->nombre,
                insumo: $insumo->nombre,
                almacen: $almacen->nombre,
                hacen: ItemPresentacion::sinCerosDeMas($cantidad->redondeado(4)),
                hay: ItemPresentacion::sinCerosDeMas($cantidad->restar($pendiente)->redondeado(4)),
            );
        }

        foreach ($tomas as $toma) {
            $movimiento = $this->movimientos->registrar(
                item: $insumo,
                lote: $toma['lote'],
                almacen: $almacen,
                tipo: TipoMovimiento::SalidaPorConsumoDeServicio,
                cantidad: $toma['cantidad'],
                motivo: 'Examen '.$examen->nombre,
                referencia: (string) $cargo->id,
                ocurridoEn: $cargo->ocurrido_en,
                costoUnitario: $costo,
            );

            $this->anotar($cargo, $insumo, $almacen, $toma['lote'], $movimiento, $toma['cantidad'], $costo);
        }

        $this->costos->sincronizarCantidadBase($insumo, $almacen);
    }

    /**
     * Rehacer un cargo: gastar lo mismo que el original, del mismo lote.
     */
    private function repetir(Cargo $cargo, Cargo $original): void
    {
        $gastos = ConsumoDeCargo::query()
            ->deTodasLasSedes()
            ->with(['insumo', 'almacen', 'lote'])
            ->where('cargo_id', $original->id)
            ->orderBy('id')
            ->get();

        foreach ($gastos as $gasto) {
            if ($gasto->sin_existencia || $gasto->insumo->tieneStockInfinito()) {
                $this->anotar($cargo, $gasto->insumo, $gasto->almacen, null, null, $gasto->cantidadDecimal(), null);

                continue;
            }

            $costo = $this->costos->vigenteBloqueado($gasto->insumo, $gasto->almacen);
            $lote = $gasto->lote_id === null ? null : $gasto->lote;

            $movimiento = $this->movimientos->registrar(
                item: $gasto->insumo,
                lote: $lote,
                almacen: $gasto->almacen,
                tipo: TipoMovimiento::SalidaPorConsumoDeServicio,
                cantidad: $gasto->cantidadDecimal(),
                motivo: 'Examen rehecho del cargo '.$original->id,
                referencia: (string) $cargo->id,
                ocurridoEn: $cargo->ocurrido_en,
                costoUnitario: $costo,
            );

            $this->anotar($cargo, $gasto->insumo, $gasto->almacen, $lote, $movimiento, $gasto->cantidadDecimal(), $costo);

            $this->costos->sincronizarCantidadBase($gasto->insumo, $gasto->almacen);
        }
    }

    private function anotar(
        Cargo $cargo,
        Item $insumo,
        Almacen $almacen,
        ?Lote $lote,
        ?MovimientoKardex $movimiento,
        Decimal $cantidad,
        ?Decimal $costo,
    ): void {
        ConsumoDeCargo::query()->forceCreate([
            'sede_id'         => $cargo->sede_id,
            'cargo_id'        => $cargo->id,
            'fecha_operacion' => $cargo->fecha_operacion->toDateString(),
            'insumo_id'       => $insumo->id,
            'almacen_id'      => $almacen->id,
            'lote_id'         => $lote?->id,
            'movimiento_id'   => $movimiento?->id,
            'cantidad'        => $cantidad->redondeado(4),
            'costo_unitario'  => $costo?->redondeado(6),
            'sin_existencia'  => ! $movimiento instanceof MovimientoKardex,
            'ocurrido_en'     => $cargo->ocurrido_en,
            'created_by'      => self::usuario(),
        ]);
    }

    /**
     * `auth()->id()` es `int|string|null` (§9.A8).
     */
    private static function usuario(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Cuánto hay de cada kit en el estante, sin contar lotes vencidos.
     *
     * @param list<int> $insumoIds
     *
     * @return array<int, string>
     */
    private function usablesEn(array $insumoIds, Almacen $almacen): array
    {
        if ($insumoIds === []) {
            return [];
        }

        $filas = Existencia::query()
            ->leftJoin('lotes', 'existencias.lote_id', '=', 'lotes.id')
            ->where('existencias.almacen_id', $almacen->id)
            ->whereIn('existencias.item_id', $insumoIds)
            ->where(fn (Builder $sub): Builder => $sub
                ->whereNull('lotes.fecha_vencimiento')
                ->orWhereDate('lotes.fecha_vencimiento', '>=', now()->toDateString()))
            ->selectRaw('existencias.item_id, sum(existencias.cantidad) as cantidad')
            ->groupBy('existencias.item_id')
            ->toBase()
            ->get();

        $hay = [];

        foreach ($filas as $fila) {
            $hay[(int) $fila->item_id] = (string) $fila->cantidad;
        }

        return $hay;
    }
}
