<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\RecepcionException;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Almacen;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\Recepcion;
use App\Models\RecepcionLinea;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * La puerta por la que la mercadería entra al hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN SOLO BOTÓN, CUATRO ESCRITURAS, UNA TRANSACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Por cada línea: se resuelve el lote, se asienta el movimiento del
 * kardex, se recalcula el costo promedio del almacén y se guarda la
 * línea con su costo congelado. Si cualquiera de las cuatro falla, no
 * queda ninguna.
 *
 * Ese «todo o nada» es lo que permite que no haya paso de confirmación:
 * quien recibe aprieta guardar una vez y el inventario ya está al día,
 * sin una ventana en la que la mercadería esté en el estante y el
 * sistema todavía no la vea.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CASO QUE HAY QUE PODER HACER EN DOS MINUTOS
 * ─────────────────────────────────────────────────────────────────────
 *
 *     100 cajas de acetaminofén de 100 tabletas a L 1.000 la caja
 *      50 cajas del mismo, de 50 tabletas, a L 500 la caja
 *
 * Dos líneas del MISMO ítem. Entran 12.500 tabletas y el costo promedio
 * queda en L 10,00. Ni una casilla de impuestos: el costo que se teclea
 * ya lo lleva adentro, porque los servicios de salud son exentos y ese
 * ISV no se acredita nunca.
 */
final class RegistradorDeRecepcion
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly ResolutorDeLote $lotes,
        private readonly CalculadoraDeCostoPromedio $costos,
    ) {}

    /**
     * @param list<LineaRecibida> $lineas
     *
     * @throws RecepcionException
     */
    public function registrar(
        Almacen $almacen,
        array $lineas,
        ?Proveedor $proveedor = null,
        ?string $referencia = null,
        ?CarbonInterface $fecha = null,
        ?string $notas = null,
    ): Recepcion {
        if ($lineas === []) {
            throw RecepcionException::sinLineas();
        }

        $fecha ??= now();

        /** @var Recepcion $recepcion */
        $recepcion = DB::transaction(function () use (
            $almacen,
            $lineas,
            $proveedor,
            $referencia,
            $fecha,
            $notas,
        ): Recepcion {
            $recepcion = Recepcion::query()->create([
                'almacen_id'      => $almacen->id,
                'proveedor_id'    => $proveedor?->id,
                'referencia'      => $referencia,
                'fecha_recepcion' => $fecha->toDateString(),
                'notas'           => $notas,
            ]);

            foreach ($lineas as $linea) {
                $this->asentar($recepcion, $almacen, $linea, $proveedor, $fecha);
            }

            return $recepcion;
        });

        return $recepcion->refresh();
    }

    /**
     * Una línea: lote, kardex, costo promedio y la fila congelada.
     */
    private function asentar(
        Recepcion $recepcion,
        Almacen $almacen,
        LineaRecibida $linea,
        ?Proveedor $proveedor,
        CarbonInterface $fecha,
    ): void {
        $numero = $linea->numeroDeLote();

        $lote = $numero === null
            ? null
            : $this->lotes->resolver(
                item: $linea->item,
                numero: $numero,
                vencimiento: $linea->vencimiento,
                proveedor: $proveedor?->nombre,
            );

        $unidades = $linea->cantidadEnUnidades();
        $costoUnitario = $linea->costoUnitario();

        /*
         * El promedio se recalcula ANTES de asentar el movimiento para
         * que la línea del kardex pueda guardar el promedio que quedó
         * vigente. Es el mismo orden que `saldo_despues`: primero mover,
         * después fotografiar.
         */
        $promedio = $this->costos->absorber($linea->item, $almacen, $unidades, $costoUnitario);

        $this->movimientos->registrar(
            item: $linea->item,
            lote: $lote,
            almacen: $almacen,
            tipo: TipoMovimiento::EntradaPorCompra,
            cantidad: $unidades,
            referencia: $recepcion->referencia,
            ocurridoEn: $fecha,
            costoUnitario: $costoUnitario,
            costoPromedioDespues: $promedio,
        );

        RecepcionLinea::query()->create([
            'recepcion_id'         => $recepcion->id,
            'item_id'              => $linea->item->id,
            'item_presentacion_id' => $linea->presentacion?->id,
            'lote_id'              => $lote instanceof Lote ? $lote->id : null,

            'cantidad_presentacion'     => $linea->cantidadPresentacion->paraBase(4),
            'unidades_por_presentacion' => $linea->unidadesPorPresentacion->paraBase(4),
            'costo_por_presentacion'    => $linea->costoPorPresentacion->paraBase(4),
            'costo_unitario'            => $costoUnitario->paraBase(6),

            'numero_lote'       => $numero,
            'fecha_vencimiento' => $linea->vencimiento?->toDateString(),
            'notas'             => $linea->notas,
        ]);
    }

    /**
     * Marcar que otra persona miró los números.
     *
     * No mueve nada: la mercadería entró cuando se guardó la recepción.
     * Lo que hace es sacarla del reporte de pendientes, y por eso no
     * puede hacerlo quien la cargó — firmarse el propio trabajo dejaría
     * a ese reporte sin significar nada. La base lo vuelve a exigir con
     * un CHECK.
     *
     * @throws RecepcionException
     */
    public function marcarRevisada(Recepcion $recepcion): Recepcion
    {
        if ($recepcion->estaRevisada()) {
            throw RecepcionException::yaEstaRevisada();
        }

        $quien = UsuarioAutenticado::id();

        /*
         * Sin usuario no hay revisión posible, y no es un tecnicismo: lo
         * único que agrega marcar revisada es QUIÉN la miró. La base
         * también lo exige —`revisada_en` y `revisada_por` van juntas o
         * ninguna—, así que sin esta guarda un comando terminaría en un
         * error de SQL crudo.
         */
        if ($quien === null) {
            throw RecepcionException::faltaQuienRevisa();
        }

        if ($quien === $recepcion->created_by) {
            throw RecepcionException::noSeRevisaUnoMismo();
        }

        $recepcion->revisada_en = now();
        $recepcion->revisada_por = $quien;
        $recepcion->save();

        return $recepcion->refresh();
    }
}
