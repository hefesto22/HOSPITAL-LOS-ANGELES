<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\ValueObjects\PrecioResuelto;
use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\CarbonInterface;

/**
 * `precio(ítem, convenio, fecha, sede)` — con una sola respuesta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA ESCALERA
 * ─────────────────────────────────────────────────────────────────────
 *
 *   1. Precio firmado con ese pagador para ese ítem.
 *   2. Porcentaje pactado con ese pagador, sobre la lista.
 *   3. Precio de lista.
 *
 * Y en cada peldaño, la fila de esta sede le gana a la que vale para
 * todas. El orden entre el 1 y el 3 lo arma
 * `Tarifario::scopeResolviendoPara`, así que la consulta ya devuelve la
 * ganadora; el 2 se aplica solo cuando la ganadora resultó ser la lista.
 *
 * Que el precio negociado le gane al porcentaje es lo que hace que «las
 * dos formas» convivan: el factor cubre el catálogo entero, y los pocos
 * ítems que se negociaron uno por uno lo pisan.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA FECHA NO TIENE DEFAULT
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es la fecha del SERVICIO, no la de hoy ni la de facturación. Reimprimir
 * la factura de un ingreso de marzo tiene que dar el precio de marzo; si
 * diera el de hoy, cada reimpresión sería un documento distinto del que
 * el paciente firmó.
 */
final class ResolutorDePrecio
{
    /**
     * @throws PrecioNoDefinidoException
     */
    public function para(
        Item $item,
        Convenio $convenio,
        CarbonInterface $fechaServicio,
        ?Sede $sede = null,
        ?ItemPresentacion $presentacion = null,
    ): PrecioResuelto {
        /*
         * 🔴 El envase viene del LOTE del que se está sirviendo, no de
         * una elección de quien cobra.
         *
         * El frasco de 60 ML y el de 80 ML costaron distinto el
         * mililitro; si los dos se cobraran igual, el margen del hospital
         * dependería de cuál estaba abierto y nadie lo sabría. Con el
         * envase acá, el precio sigue al mismo frasco que el costo.
         *
         * Nulo cae al precio del producto entero, que es lo que existía
         * antes y sigue siendo el respaldo.
         */
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara($convenio->id, $sede?->id, $presentacion?->id)
            ->vigentesEn($fechaServicio)
            ->first();

        if (! $fila instanceof Tarifario) {
            throw PrecioNoDefinidoException::paraElItem(
                $item->codigo,
                $item->nombre,
                $fechaServicio->format('d/m/Y'),
            );
        }

        /*
         * Si la ganadora es un precio firmado para este ítem, ahí termina:
         * lo negociado uno por uno pisa al porcentaje general.
         */
        if (! $fila->esPrecioDeLista()) {
            return PrecioResuelto::desde($fila);
        }

        $condicion = $this->condicionVigente($convenio, $fechaServicio);

        return $condicion instanceof ConvenioCondicion
            ? PrecioResuelto::conFactor($fila, $condicion)
            : PrecioResuelto::desde($fila);
    }

    /**
     * El porcentaje pactado con ese pagador, si lo hay ese día.
     */
    public function condicionVigente(Convenio $convenio, CarbonInterface $fecha): ?ConvenioCondicion
    {
        $condicion = ConvenioCondicion::query()
            ->where('convenio_id', $convenio->id)
            ->vigentesEn($fecha)
            ->first();

        return $condicion instanceof ConvenioCondicion ? $condicion : null;
    }

    /**
     * El precio de lista, sin pagador de por medio.
     *
     * Es lo que muestra el mostrador antes de saber quién paga, y lo que
     * la calculadora escribe cuando se guarda un precio derivado del
     * costo.
     *
     * @throws PrecioNoDefinidoException
     */
    public function deLista(
        Item $item,
        CarbonInterface $fechaServicio,
        ?Sede $sede = null,
        ?ItemPresentacion $presentacion = null,
    ): PrecioResuelto {
        /*
         * `resolviendoPara(null, ...)` ya restringe a las filas sin
         * convenio: pasar el nulo es la forma de pedir la lista.
         */
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara(null, $sede?->id, $presentacion?->id)
            ->vigentesEn($fechaServicio)
            ->first();

        if (! $fila instanceof Tarifario) {
            throw PrecioNoDefinidoException::paraElItem(
                $item->codigo,
                $item->nombre,
                $fechaServicio->format('d/m/Y'),
            );
        }

        return PrecioResuelto::desde($fila);
    }

    /**
     * ¿Se puede cobrar este ítem ese día?
     *
     * Para la pantalla, que necesita avisar antes de que alguien arme una
     * cuenta con un ítem sin precio.
     */
    public function hayPrecio(
        Item $item,
        Convenio $convenio,
        CarbonInterface $fecha,
        ?Sede $sede = null,
        ?ItemPresentacion $presentacion = null,
    ): bool {
        /*
         * ⚠️ El envase entra también acá, no solo en `para()`.
         *
         * Sin él, la pantalla preguntaba por el precio del producto
         * entero, respondía «sí hay» y después `para()` resolvía el del
         * frasco —que puede no existir—. Las dos preguntas tienen que
         * mirar la misma fila.
         */
        return Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara($convenio->id, $sede?->id, $presentacion?->id)
            ->vigentesEn($fecha)
            ->exists();
    }
}
