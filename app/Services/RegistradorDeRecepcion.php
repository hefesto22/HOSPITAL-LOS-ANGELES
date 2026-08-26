<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\PrecioNoDerivableException;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\Exceptions\RecepcionException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\Monto;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\Recepcion;
use App\Models\RecepcionLinea;
use App\Models\Tarifario;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly CalculadoraDePrecioDeLista $preciosSugeridos,
        private readonly FijadorDePrecio $precios,
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

                /*
                 * El envase viaja hasta el lote para que la existencia se
                 * pueda leer en frascos y no solo en mililitros.
                 */
                presentacion: $linea->presentacion,
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

        $this->sembrarLosPrecios($linea, $fecha);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL PRIMER INGRESO LE PONE PRECIO A LO QUE ENTRÓ
     * ─────────────────────────────────────────────────────────────────
     *
     * Un medicamento que entra a bodega sin precio de lista NO SE PUEDE
     * COBRAR: la caja lo busca, no lo encuentra, y o el paciente se va
     * sin pagarlo o alguien inventa un número en el mostrador. Las dos
     * cosas son plata que no vuelve, y las dos empiezan igual — nadie se
     * acordó de ponerle precio después de recibirlo.
     *
     * ─────────────────────────────────────────────────────────────────
     * UN SOLO PRECIO POR LÍNEA, EL DEL ENVASE QUE LLEGÓ
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes se sembraban dos: el del envase y uno «del producto entero»
     * como respaldo. Eso le dejaba a cada producto un precio que no le
     * correspondía a ninguna existencia — en acetaminofén se veían
     * CUATRO precios para TRES frascos, y el cuarto salía del promedio
     * del almacén, justo el número amontonado que se decidió no usar.
     *
     * 🔴 Y no era cosmético. `Tarifario::scopeResolviendoPara` cae al
     * precio sin envase cuando el específico no existe, y no avisa. Un
     * frasco nuevo cuyo precio no se pudo calcular —sin margen, costo en
     * cero, la excepción que se traga más abajo— se cobraba al número
     * del respaldo como si fuera suyo. El de 120 ML sale L 45.83 el
     * mililitro y el de 80 ML sale L 91.67: el doble. Un respaldo
     * equivocado no se ve en la factura, se ve en la utilidad del mes.
     *
     * El respaldo sigue existiendo, pero solo nace cuando hace falta:
     * cuando la línea llegó SIN envase declarado —a granel, o un ítem
     * que no maneja presentaciones—. Ahí es el único precio posible, y
     * ahí sí le corresponde existencia real.
     *
     * ⚠️ Solo si NO existe todavía. Un precio ya fijado es una decisión
     * de dirección con su fecha y su motivo: que una compra lo pisara
     * sería cambiar la lista sin que nadie lo haya pedido, y el cambio
     * aparecería recién en la utilidad del mes.
     */
    private function sembrarLosPrecios(LineaRecibida $linea, CarbonInterface $fecha): void
    {
        if (! $linea->item->tipo->precioDerivadoDelCosto()) {
            return;
        }

        /*
         * El costo es el de ESTA línea, no el promedio del almacén. El
         * frasco de 60 ML costó L 16.67 el mililitro y el de 80 ML costó
         * L 25.00: con el promedio, el margen del hospital dependería de
         * cuál frasco estaba abierto y nadie lo sabría.
         */
        $this->sembrarSiFalta(
            $linea->item,
            $linea->presentacion,
            $linea->costoUnitario(),
            $fecha,
        );
    }

    /**
     * ⚠️ NUNCA frena la recepción. Si el tipo no deriva precio del costo,
     * si no hay margen configurado para ese tipo, si el costo vino en
     * cero —donaciones—, la mercadería igual entró al estante. Reventar
     * acá dejaría el kardex sin la entrada por un problema de precios.
     */
    private function sembrarSiFalta(
        Item $item,
        ?ItemPresentacion $presentacion,
        Decimal $costo,
        CarbonInterface $fecha,
    ): void {
        $yaTienePrecio = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->when(
                $presentacion instanceof ItemPresentacion,
                fn (Builder $consulta): Builder => $consulta->where('item_presentacion_id', $presentacion?->id),
                fn (Builder $consulta): Builder => $consulta->whereNull('item_presentacion_id'),
            )
            ->vigentesEn($fecha)
            ->exists();

        if ($yaTienePrecio) {
            return;
        }

        try {
            $sugerido = $this->preciosSugeridos->para($item, Monto::de($costo), $fecha);

            /*
             * ⚠️ `motivo` es `varchar(255)`. El texto con el nombre del
             * envase adentro se pasaba de largo y la recepción entera
             * moría contra Postgres — la mercadería no entraba al estante
             * por un renglón de explicación.
             *
             * Se acorta el texto Y se corta a 255: lo primero es lo que
             * hace que quepa, lo segundo es lo que garantiza que un nombre
             * de presentación largo no vuelva a tumbar una compra.
             */
            $porQue = 'Calculado en el primer ingreso a bodega'
                .($presentacion instanceof ItemPresentacion ? ' de '.$presentacion->nombre : '')
                .': costo '.$sugerido->costo->formateado()
                .', margen '.$sugerido->margenObjetivoComoPorcentaje()
                .', dividido por el descuento de ley más alto de la categoría para que sea piso.';

            $this->precios->fijar(
                item: $item,
                convenio: null,
                sede: null,
                precio: $sugerido->lista,
                motivo: mb_substr($porQue, 0, 255),
                desde: $fecha,
                presentacion: $presentacion,
            );
        } catch (PrecioNoDerivableException|PrecioNoFijableException) {
            return;
        }
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
