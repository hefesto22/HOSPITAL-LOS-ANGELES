<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use Illuminate\Support\Collection;

/**
 * «¿Cómo se le agrega?» — las formas en que un producto entra a la cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA REGLA (Mauricio, 10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cuando son tabletas debería poderse poner si se dispensa en tableta o
 * caja. La unidad de envase está bien, porque es donde viene envasado, y
 * a veces el envase es un frasco que trae 100. Pero debería poderse
 * manejar el envase y cómo se dispensa.»
 *
 * Y se decide EN CADA PRESENTACIÓN —«también se vende entera»—, no en el
 * producto: la caja de 100 de acetaminofén se vende entera, el blíster
 * de 10 del mismo producto no. Al cobrar, quien atiende elige entre lo
 * que el catálogo permite; el sistema no elige por él.
 *
 * Tres formas, y ninguna más:
 *
 *   · SUELTA — en la unidad de dispensación: la tableta, la ampolla.
 *     Solo para lo que se entrega suelto (`seEntregaSuelta()`).
 *   · FRACCIÓN — el mililitro de lo que el catálogo declara fraccionable.
 *   · ENVASE — una presentación. Con «se vende entera» MULTIPLICA: una
 *     caja de 100 son 100 tabletas que salen del estante. Sin la marca es
 *     solo el ORIGEN —«de qué frasco se sirvió»— y la cantidad sigue en la
 *     unidad de dispensación, que es la regla del 3-sep-2026 para lo que
 *     no se entrega suelto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO ES UNA CONVERSIÓN Y NO UN PRECIO DISTINTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El kardex y el tarifario se llevan SIEMPRE en unidad de dispensación
 * (§8.7). Vender la caja entera no es otro precio: son cien tabletas.
 * Si algún día la caja se quiere cobrar más barata que cien sueltas, eso
 * es una fila de tarifario o un descuento con motivo, nunca un número
 * escondido en la conversión (§8.6.2-4).
 *
 * Vive acá y no en la pantalla de cobro por el §9.A11: la conversión
 * decide cuánto sale del estante y cuánto se cobra, y una regla así
 * tiene que poder probarse sin abrir un navegador.
 */
final class FormasDeCobro
{
    public const SUELTA = 'dispensacion';

    public const FRACCION = 'fraccion';

    public const ENVASE = 'presentacion:';

    /**
     * Las claves de las formas que admite el producto, en el orden en
     * que se ofrecen. Nunca vacío: un producto sin ninguna forma no se
     * podría cobrar, y eso es peor que cobrarlo en su unidad mínima.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones las del ítem
     *
     * @return list<string>
     */
    public function de(Item $item, Collection $presentaciones): array
    {
        $suelta = $this->seEntregaSuelta($item, $presentaciones);

        $claves = [];

        if ($suelta) {
            $claves[] = self::SUELTA;
        }

        if ($this->seFracciona($item)) {
            $claves[] = self::FRACCION;
        }

        foreach ($presentaciones as $presentacion) {
            /*
             * Lo que se entrega suelto NO ofrece el envase como origen:
             * «5 TAB de la caja» y «5 TAB» son la misma venta, y ofrecer
             * las dos es pedirle a quien cobra que elija entre dos
             * botones que dicen lo mismo. El envase aparece solo cuando
             * se puede vender entero, que es una venta distinta.
             */
            if ($presentacion->se_vende_entera || ! $suelta) {
                $claves[] = self::ENVASE.$presentacion->id;
            }
        }

        return $claves === [] ? [self::SUELTA] : $claves;
    }

    /**
     * La forma, cuando hay una sola y no hay nada que preguntar.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function unica(Item $item, Collection $presentaciones): ?string
    {
        $claves = $this->de($item, $presentaciones);

        return count($claves) === 1 ? $claves[0] : null;
    }

    /**
     * ¿Este producto se entrega suelto, sin envase?
     *
     * Una TABLETA o una AMPOLLA sí: son unidades de conteo, se sacan del
     * blíster y se entregan. Un jarabe medido en ML no, salvo que el
     * hospital lo fraccione —y eso es una decisión declarada en el
     * catálogo, no algo que se deduzca del producto—.
     *
     * Sin presentaciones cargadas también devuelve true: no hay envase
     * que ofrecer, así que la unidad suelta es lo único que hay.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function seEntregaSuelta(Item $item, Collection $presentaciones): bool
    {
        if ($item->fraccionable) {
            return true;
        }

        $magnitud = $item->unidadDispensacion?->magnitud;

        if ($magnitud === null || $magnitud === MagnitudDeMedida::Conteo) {
            return true;
        }

        return $presentaciones->isEmpty();
    }

    /**
     * La presentación detrás de una clave de envase, o nulo cuando la
     * clave no es un envase o el envase no es de este producto.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function presentacionDe(?string $clave, Collection $presentaciones): ?ItemPresentacion
    {
        if ($clave === null || ! str_starts_with($clave, self::ENVASE)) {
            return null;
        }

        $id = (int) mb_substr($clave, mb_strlen(self::ENVASE));

        $presentacion = $presentaciones->firstWhere('id', $id);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    /**
     * ¿Esta forma vende el envase entero?
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function esEnvaseEntero(?string $clave, Collection $presentaciones): bool
    {
        return $this->presentacionDe($clave, $presentaciones)?->se_vende_entera === true;
    }

    /**
     * Lo tecleado, llevado a la unidad del kardex.
     *
     * Devuelve `null` cuando la forma no se puede resolver para este
     * producto —una presentación de otro ítem, una fracción en algo que
     * no se fracciona, una clave que no es de la lista—. Nunca un cero:
     * el cero es una cantidad legal y confundirlos sería cobrar la nada.
     *
     * 🔴 Solo multiplica el envase marcado «se vende entera». Un envase
     * sin la marca es el origen y no toca la cantidad: «5 del frasco de
     * 120» son 5 ML. Multiplicar ahí era cobrar 600 y descontar 600 del
     * estante con 5 ML en la jeringa (regla del 3-sep-2026).
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function aUnidadesDeDispensacion(
        Item $item,
        string $clave,
        Decimal $cantidad,
        Collection $presentaciones,
    ): ?Decimal {
        if (! in_array($clave, $this->de($item, $presentaciones), true)) {
            return null;
        }

        if ($clave === self::SUELTA) {
            return $cantidad;
        }

        if ($clave === self::FRACCION) {
            $fracciones = $item->fracciones_por_unidad;

            return $fracciones === null ? null : $cantidad->entre($fracciones);
        }

        $presentacion = $this->presentacionDe($clave, $presentaciones);

        if (! $presentacion instanceof ItemPresentacion || $presentacion->item_id !== $item->id) {
            return null;
        }

        return $presentacion->se_vende_entera
            ? $cantidad->por($presentacion->unidades_por_presentacion)
            : $cantidad;
    }

    /**
     * Cuántos envases enteros se están vendiendo, o nulo cuando no se
     * vende por envase. Es lo que hace que el renglón se lea «1 CAJA» y
     * no «100».
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function envasesEnteros(?string $clave, Decimal $cantidad, Collection $presentaciones): ?Decimal
    {
        return $this->esEnvaseEntero($clave, $presentaciones) ? $cantidad : null;
    }

    private function seFracciona(Item $item): bool
    {
        return $item->fraccionable
            && $item->unidad_fraccion_id !== null
            && $item->fracciones_por_unidad !== null;
    }
}
