<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\ValueObjects\Decimal;
use App\Models\Convenio;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PlantillaPresupuesto;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Models\Sede;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Arma el presupuesto que se le entrega a la familia (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ NO REUSA `CalculadoraDeCargo`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque un presupuesto no es un cargo chiquito: es otra cosa.
 *
 * El cargo pasa por cobertura del seguro, política de descuento
 * comercial, tope autorizado por rol y verificación de existencia. Nada
 * de eso aplica a un estimado —no hay seguro que reparta todavía, nadie
 * autorizó nada y no sale un frasco de bodega— y forzarlo por ese camino
 * obligaría a inventarle una `CoberturaAplicada` falsa a cada línea.
 *
 * Lo que SÍ comparte, porque tiene que dar el mismo número: el
 * `ResolutorDePrecio` —mismo tarifario, mismo convenio, misma vigencia—
 * y el régimen de ISV del ítem.
 *
 * ⚠️ El descuento del Art. 30 NO se comparte, y es a propósito: el
 * presupuesto cotiza en BRUTO y la ley se aplica una sola vez, en el
 * cargo. Aplicarla en los dos lados cobraba el 25 % dos veces.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL NÚMERO SE GASTA AL CREAR, NO AL EMITIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un borrador descartado se lleva un número. Es a propósito: `numero` es
 * NOT NULL y único, y la alternativa —dejarlo nulo hasta emitir— pone al
 * sistema a repartir números en el momento en que alguien imprime, que
 * es cuando menos conviene fallar. Estos correlativos NO son fiscales
 * (`TipoCorrelativo` lo dice en su cabecera): los huecos no le importan
 * a nadie.
 */
final class CotizadorDePresupuesto
{
    public function __construct(
        private readonly ResolutorDePrecio $precios,
        private readonly AsignadorDeCorrelativo $correlativos,
    ) {}

    /**
     * Arma un presupuesto en BORRADOR a partir de una plantilla.
     *
     * `$encuentro` es opcional: mucha gente llega solo a preguntar
     * cuánto le sale y todavía no ingresó.
     */
    public function desdePlantilla(
        PlantillaPresupuesto $plantilla,
        Expediente $expediente,
        Convenio $convenio,
        Sede $sede,
        CarbonInterface $fecha,
        ?Encuentro $encuentro = null,
        ?string $titulo = null,
    ): Presupuesto {
        return DB::transaction(function () use (
            $plantilla,
            $expediente,
            $convenio,
            $sede,
            $fecha,
            $encuentro,
            $titulo
        ): Presupuesto {
            $presupuesto = $this->abrirBorrador(
                expediente: $expediente,
                convenio: $convenio,
                sede: $sede,
                titulo: $titulo ?? $plantilla->nombre,
                encuentro: $encuentro,
                plantilla: $plantilla,
            );

            $orden = 0;

            foreach ($plantilla->lineas()->with('item')->get() as $renglon) {
                $orden += 10;

                $this->agregarDelCatalogo(
                    presupuesto: $presupuesto,
                    item: $renglon->item,
                    cantidad: $renglon->cantidad,
                    fecha: $fecha,
                    orden: $orden,
                    opcional: $renglon->opcional,
                    nota: $renglon->nota,
                );
            }

            $this->recalcular($presupuesto);

            if (Decimal::de($plantilla->holgura_fraccion)->esCero()) {
                return $presupuesto->refresh();
            }

            $this->agregarHolgura(
                presupuesto: $presupuesto,
                monto: Decimal::de($presupuesto->total)
                    ->por($plantilla->holgura_fraccion)
                    ->redondeado(2),
                orden: $orden + 1000,
            );

            return $this->recalcular($presupuesto);
        });
    }

    /**
     * Abre un presupuesto vacío, para armarlo a mano sin plantilla.
     */
    public function abrirBorrador(
        Expediente $expediente,
        Convenio $convenio,
        Sede $sede,
        string $titulo,
        ?Encuentro $encuentro = null,
        ?PlantillaPresupuesto $plantilla = null,
    ): Presupuesto {
        return Presupuesto::create([
            'sede_id'       => $sede->id,
            'numero'        => $this->correlativos->siguiente($sede, TipoCorrelativo::Presupuesto),
            'expediente_id' => $expediente->id,
            'persona_id'    => $expediente->persona_id,
            'encuentro_id'  => $encuentro?->id,
            'convenio_id'   => $convenio->id,
            'plantilla_id'  => $plantilla?->id,
            'titulo'        => $titulo,
            'estado'        => EstadoPresupuesto::Borrador,
        ]);
    }

    /**
     * Una línea cotizada contra el tarifario.
     *
     * ⚠️ Si el ítem no tiene precio para ese convenio, la línea NO se
     * salta: entra con precio cero, marcada como manual y con la nota a
     * la vista. Un presupuesto al que le falta un renglón en silencio es
     * peor que uno que dice «esto hay que llenarlo»: el primero se
     * imprime y se firma sin que nadie note el hueco.
     *
     * @param numeric-string $cantidad
     */
    public function agregarDelCatalogo(
        Presupuesto $presupuesto,
        Item $item,
        string $cantidad,
        CarbonInterface $fecha,
        int $orden = 0,
        bool $opcional = false,
        ?string $nota = null,
        ?ItemPresentacion $presentacion = null,
    ): PresupuestoLinea {
        $convenio = $presupuesto->convenio;
        $sede = $presupuesto->sede;

        if (! $this->precios->hayPrecio($item, $convenio, $fecha, $sede, $presentacion)) {
            return $this->agregarLineaManual(
                presupuesto: $presupuesto,
                texto: $item->nombre,
                cantidad: $cantidad,
                precioUnitario: '0.0000',
                item: $item,
                orden: $orden,
                opcional: $opcional,
                nota: $nota ?? 'SIN PRECIO EN EL TARIFARIO — hay que ponerlo a mano',
            );
        }

        $precio = $this->precios->para($item, $convenio, $fecha, $sede, $presentacion);

        $bruto = $precio->precio->cantidad()->por($cantidad);

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL PRESUPUESTO COTIZA EN BRUTO. LA LEY SE APLICA UNA VEZ,
         * EN LA CUENTA.
         * ─────────────────────────────────────────────────────────────
         *
         * Antes cada renglón restaba acá el descuento del Art. 30, y
         * después el cargo del paquete se lo volvía a aplicar sobre el
         * total YA neto: a un paciente de 65 años, un presupuesto de
         * L 40,000 terminaba cobrándose L 23,400. El hospital regalaba
         * casi ocho mil sin que nadie lo decidiera.
         *
         * Ahora el descuento vive donde está el dinero de verdad —el
         * cargo—, se calcula una sola vez y con la categoría del renglón
         * que se factura, y sale IMPRESO en la factura, que es donde el
         * adulto mayor puede verificar que se lo dieron.
         *
         * ⚠️ Consecuencia visible: el papel del presupuesto muestra el
         * precio de lista. Para un paciente de 60+ la cuenta va a salir
         * MENOR que el presupuesto — nunca mayor, que es el lado seguro
         * del error. Falta agregarle al papel una línea informativa que
         * lo anticipe.
         */
        $descuento = Decimal::cero();

        return $this->escribirLinea(
            presupuesto: $presupuesto,
            item: $item,
            origen: OrigenLineaPresupuesto::Catalogo,
            texto: $presentacion instanceof ItemPresentacion
                ? $item->nombre.' — '.$presentacion->envase()
                : $item->nombre,
            cantidad: $cantidad,
            precioUnitario: $precio->precio->cantidad()->paraBase(4),
            bruto: $bruto,
            descuento: $descuento,
            orden: $orden,
            opcional: $opcional,
            nota: $nota,
            tarifarioId: $precio->fila?->id,
            origenPrecio: $precio->origen->value,
            /*
             * La categoría SÍ se guarda: dice bajo qué numeral del Art.
             * 30 cae este renglón, y es lo que explica el descuento que
             * después aparece en la factura. La fracción queda en cero
             * porque acá no se aplica ninguna.
             */
            categoriaLegal: $item->categoria_legal_descuento->value,
            fraccionLegal: null,
            presentacionId: $presentacion?->id,
        );
    }

    /**
     * Una línea con precio escrito a mano: el honorario del cirujano, que
     * cambia por médico, o algo que no está en el catálogo.
     *
     * @param numeric-string $cantidad
     * @param numeric-string $precioUnitario
     */
    public function agregarLineaManual(
        Presupuesto $presupuesto,
        string $texto,
        string $cantidad,
        string $precioUnitario,
        ?Item $item = null,
        int $orden = 0,
        bool $opcional = false,
        ?string $nota = null,
    ): PresupuestoLinea {
        $bruto = Decimal::de($precioUnitario)->por($cantidad);

        return $this->escribirLinea(
            presupuesto: $presupuesto,
            item: $item,
            origen: OrigenLineaPresupuesto::Manual,
            texto: $texto,
            cantidad: $cantidad,
            precioUnitario: $precioUnitario,
            bruto: $bruto,
            descuento: Decimal::cero(),
            orden: $orden,
            opcional: $opcional,
            nota: $nota,
        );
    }

    /**
     * El colchón, como línea visible.
     *
     * ⚠️ NUNCA lleva ítem —un CHECK de la base lo verifica— y nunca lleva
     * ISV: no es la venta de nada, es margen de error de la estimación.
     *
     * @param numeric-string $monto
     */
    public function agregarHolgura(Presupuesto $presupuesto, string $monto, int $orden = 9999): PresupuestoLinea
    {
        return $this->escribirLinea(
            presupuesto: $presupuesto,
            item: null,
            origen: OrigenLineaPresupuesto::Holgura,
            texto: 'HOLGURA DEL PRESUPUESTO',
            cantidad: '1.0000',
            precioUnitario: $monto,
            bruto: Decimal::de($monto),
            descuento: Decimal::cero(),
            orden: $orden,
            forzarExento: true,
        );
    }

    /**
     * Cambia la cantidad —o el precio, si la línea es manual— de un
     * renglón que ya existe.
     *
     * 🔴 NO vuelve a consultar el tarifario. La línea ya congeló su
     * precio cuando se cotizó, y volver a resolverlo acá haría que
     * corregir un «3» por un «4» le cambiara el precio a la familia por
     * debajo, porque el tarifario pudo moverse en el medio.
     *
     * ⚠️ Un trigger de la base rechaza esto si el presupuesto ya no está
     * en borrador.
     *
     * @param numeric-string $cantidad
     * @param numeric-string|null $precioUnitario
     */
    public function ajustarLinea(
        PresupuestoLinea $linea,
        string $cantidad,
        ?string $precioUnitario = null,
    ): PresupuestoLinea {
        $precio = $precioUnitario ?? $linea->precio_unitario;

        /*
         * 🔴 PISAR EL PRECIO DE UNA LÍNEA DE CATÁLOGO LA VUELVE «MANUAL».
         *
         * El honorario del cirujano cambia por médico y hay que poder
         * corregirlo — un presupuesto no es documento fiscal—. Pero el
         * número deja de venir del tarifario, y si la línea siguiera
         * diciendo «precio del tarifario» el reporte de presupuestado
         * contra real le echaría la culpa a la cotización cuando la
         * diferencia la puso una persona.
         *
         * Conserva el `item_id`: sigue siendo el mismo ítem, con precio
         * acordado.
         */
        $origen = $linea->origen === OrigenLineaPresupuesto::Catalogo
            && $precioUnitario !== null
            && ! Decimal::de($precioUnitario)->igualA($linea->precio_unitario)
                ? OrigenLineaPresupuesto::Manual
                : $linea->origen;

        $bruto = Decimal::de($precio)->por($cantidad);
        $brutoRedondeado = Decimal::de($bruto->redondeado(2));

        $descuento = Decimal::de(
            $brutoRedondeado->por($linea->descuento_legal_fraccion)->redondeado(2)
        );

        $subtotal = $brutoRedondeado->restar($descuento);
        $tasa = Decimal::de($linea->tasa_isv);
        $isv = Decimal::de($subtotal->por($tasa)->redondeado(2));
        $grava = ! $tasa->esCero();

        $linea->update([
            'cantidad'        => $cantidad,
            'precio_unitario' => $precio,
            'origen'          => $origen,
            'bruto'           => $brutoRedondeado->redondeado(2),
            'descuento'       => $descuento->redondeado(2),
            'subtotal'        => $subtotal->redondeado(2),
            'base_exenta'     => $grava ? '0.00' : $subtotal->redondeado(2),
            'base_gravada'    => $grava ? $subtotal->redondeado(2) : '0.00',
            'isv'             => $isv->redondeado(2),
            'total'           => $subtotal->sumar($isv)->redondeado(2),
        ]);

        $this->recalcular($linea->presupuesto);

        return $linea->refresh();
    }

    /**
     * Quita un renglón del presupuesto.
     *
     * Se borra y ya: mientras está en borrador, el papel no lo vio nadie
     * y una línea tachada solo ensucia. Lo que sí queda registrado es el
     * cambio DESPUÉS de emitir, y eso pasa por una revisión.
     */
    public function quitarLinea(PresupuestoLinea $linea): Presupuesto
    {
        $presupuesto = $linea->presupuesto;

        $linea->delete();

        return $this->recalcular($presupuesto);
    }

    /**
     * Suma las líneas y escribe los totales del encabezado.
     *
     * Las líneas OPCIONALES sí suman: el presupuesto tiene que decir el
     * techo, no el piso. Cotizar sin lo opcional es la forma más común de
     * quedar corto.
     */
    public function recalcular(Presupuesto $presupuesto): Presupuesto
    {
        $bruto = Decimal::cero();
        $descuento = Decimal::cero();
        $exento = Decimal::cero();
        $gravado = Decimal::cero();
        $isv = Decimal::cero();
        $total = Decimal::cero();
        $lineas = 0;

        foreach ($presupuesto->detalle()->get() as $linea) {
            $bruto = $bruto->sumar($linea->bruto);
            $descuento = $descuento->sumar($linea->descuento);
            $exento = $exento->sumar($linea->base_exenta);
            $gravado = $gravado->sumar($linea->base_gravada);
            $isv = $isv->sumar($linea->isv);
            $total = $total->sumar($linea->total);
            $lineas++;
        }

        $presupuesto->update([
            'total_bruto'     => $bruto->redondeado(2),
            'total_descuento' => $descuento->redondeado(2),
            'total_exento'    => $exento->redondeado(2),
            'total_gravado'   => $gravado->redondeado(2),
            'total_isv'       => $isv->redondeado(2),
            'total'           => $total->redondeado(2),
            'lineas'          => $lineas,
        ]);

        return $presupuesto->refresh();
    }

    /**
     * Lo emite: a partir de acá las líneas quedan de piedra —lo verifica
     * un trigger— y el papel tiene fecha de vencimiento.
     */
    public function emitir(Presupuesto $presupuesto, CarbonInterface $ahora): Presupuesto
    {
        $this->recalcular($presupuesto);

        $dias = $presupuesto->plantilla?->dias_vigencia;

        if (! is_int($dias) || $dias <= 0) {
            $porDefecto = config('sihla.presupuesto.dias_vigencia_por_defecto');
            $dias = is_int($porDefecto) && $porDefecto > 0 ? $porDefecto : 15;
        }

        $presupuesto->update([
            'estado'     => EstadoPresupuesto::Agregado,
            'emitido_en' => $ahora,
            'vence_el'   => $ahora->copy()->addDays($dias)->toDateString(),
        ]);

        return $presupuesto->refresh();
    }

    /**
     * @param numeric-string $cantidad
     * @param numeric-string $precioUnitario
     */
    private function escribirLinea(
        Presupuesto $presupuesto,
        ?Item $item,
        OrigenLineaPresupuesto $origen,
        string $texto,
        string $cantidad,
        string $precioUnitario,
        Decimal $bruto,
        Decimal $descuento,
        int $orden = 0,
        bool $opcional = false,
        ?string $nota = null,
        ?int $tarifarioId = null,
        ?string $origenPrecio = null,
        ?string $categoriaLegal = null,
        ?Decimal $fraccionLegal = null,
        bool $forzarExento = false,
        ?int $presentacionId = null,
    ): PresupuestoLinea {
        $brutoRedondeado = Decimal::de($bruto->redondeado(2));
        $descuentoRedondeado = Decimal::de($descuento->redondeado(2));
        $subtotal = $brutoRedondeado->restar($descuentoRedondeado);

        $regimen = $item?->regimen_isv;
        $grava = ! $forzarExento && $regimen !== null && $regimen->esGravado();
        $tasa = $grava ? Decimal::de($regimen->tasaComoTexto()) : Decimal::cero();

        $isv = Decimal::de($subtotal->por($tasa)->redondeado(2));

        return PresupuestoLinea::create([
            'presupuesto_id'           => $presupuesto->id,
            'orden'                    => $orden,
            'item_id'                  => $origen === OrigenLineaPresupuesto::Holgura ? null : $item?->id,
            'presentacion_id'          => $presentacionId,
            'origen'                   => $origen,
            'texto'                    => $texto,
            'cantidad'                 => $cantidad,
            'unidad_id'                => $item?->unidad_dispensacion_id,
            'precio_unitario'          => $precioUnitario,
            'tarifario_id'             => $tarifarioId,
            'origen_precio'            => $origenPrecio,
            'categoria_legal'          => $categoriaLegal,
            'descuento_legal_fraccion' => ($fraccionLegal ?? Decimal::cero())->paraBase(4),
            'descuento'                => $descuentoRedondeado->redondeado(2),
            'regimen_isv'              => $grava && $regimen !== null ? $regimen : RegimenIsv::Exento,
            'tasa_isv'                 => $tasa->paraBase(4),
            'bruto'                    => $brutoRedondeado->redondeado(2),
            'subtotal'                 => $subtotal->redondeado(2),
            'base_exenta'              => $grava ? '0.00' : $subtotal->redondeado(2),
            'base_gravada'             => $grava ? $subtotal->redondeado(2) : '0.00',
            'isv'                      => $isv->redondeado(2),
            'total'                    => $subtotal->sumar($isv)->redondeado(2),
            'opcional'                 => $opcional,
            'nota'                     => $nota,
        ]);
    }
}
