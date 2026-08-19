<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\ValueObjects\PrecioResuelto;
use App\Models\Convenio;
use App\Models\Item;
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
 *   2. Precio de lista.
 *
 * Y en cada peldaño, la fila de esta sede le gana a la que vale para
 * todas. El orden lo arma `Tarifario::scopeResolviendoPara`, así que acá
 * no hay `if`: se pide la primera y esa es.
 *
 * ⚠️ **Falta un peldaño en el medio.** El incremento 2f agrega el
 * porcentaje pactado del convenio —«el IHSS paga lista menos 15 %»— que
 * entra entre el 1 y el 2: cuando no hay precio propio negociado pero sí
 * un factor acordado, la lista se multiplica por él. Hasta entonces un
 * convenio sin fila propia paga la lista tal cual, que es exactamente lo
 * que hoy hace CONTADO.
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
    ): PrecioResuelto {
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara($convenio->id, $sede?->id)
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
     * El precio de lista, sin pagador de por medio.
     *
     * Es lo que muestra el mostrador antes de saber quién paga, y lo que
     * la calculadora escribe cuando se guarda un precio derivado del
     * costo.
     *
     * @throws PrecioNoDefinidoException
     */
    public function deLista(Item $item, CarbonInterface $fechaServicio, ?Sede $sede = null): PrecioResuelto
    {
        /*
         * `resolviendoPara(null, ...)` ya restringe a las filas sin
         * convenio: pasar el nulo es la forma de pedir la lista.
         */
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara(null, $sede?->id)
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
    public function hayPrecio(Item $item, Convenio $convenio, CarbonInterface $fecha, ?Sede $sede = null): bool
    {
        return Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara($convenio->id, $sede?->id)
            ->vigentesEn($fecha)
            ->exists();
    }
}
