<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use Illuminate\Support\Collection;

/**
 * «¿Cómo se le agrega?» — las formas en que un producto entra a la cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA REGLA SALE DE LO QUE YA ESTÁ CARGADO (Mauricio, 10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Se puede dispensar en envase, caja o tableta» — y sin meter datos
 * que no hacen falta. La ficha ya tiene las dos respuestas:
 *
 *   · «Se dispensa en» es la unidad MÍNIMA: lo menos que se le puede
 *     entregar a un paciente. Es también la unidad del kardex y del
 *     precio, así que no es opcional ni repetida.
 *   · Cada presentación es un envase que existe en el estante. Un envase
 *     cerrado SIEMPRE se puede entregar cerrado: la caja de 100 que se
 *     vende sin abrir son 100 tabletas que salen del kardex.
 *
 * Así que las formas son:
 *
 *   · SUELTA — en «Se dispensa en»: la tableta, el ML, la ampolla.
 *   · FRACCIÓN — la parte de la unidad, solo si el catálogo la declara.
 *   · ENVASE — cada presentación con más de una unidad adentro, vendida
 *     sin abrir. MULTIPLICA: 1 CAJA X 100 = 100 TAB.
 *
 * Un interruptor por presentación para decir «esta sí se vende cerrada»
 * se probó y se sacó el mismo día: pedía contestar algo que la ficha ya
 * dice, y el envase sin marcar quedaba sin forma de venderse cerrado.
 *
 * ⚠️ El envase de UNA unidad no se ofrece: «AMPOLLA X 1» sin abrir es
 * lo mismo que una ampolla, y ofrecer dos botones que cobran igual es
 * pedirle a quien cobra que elija entre lo mismo.
 *
 * ⚠️ Solo lo que se almacena tiene envases que vender. En un honorario
 * las «presentaciones» son variantes —el Dr. Carlos, el Dr. Miguel—, no
 * cajas en un estante.
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
     * que se ofrecen. Nunca vacío: la unidad suelta está siempre.
     *
     * @param Collection<int, ItemPresentacion> $presentaciones las del ítem
     *
     * @return list<string>
     */
    public function de(Item $item, Collection $presentaciones): array
    {
        $claves = [self::SUELTA];

        if ($this->seFracciona($item)) {
            $claves[] = self::FRACCION;
        }

        if (! $item->mueveInventario()) {
            return $claves;
        }

        foreach ($presentaciones as $presentacion) {
            if ($this->traeMasDeUna($presentacion)) {
                $claves[] = self::ENVASE.$presentacion->id;
            }
        }

        return $claves;
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
     * La presentación detrás de una clave de envase, o nulo cuando la
     * clave no es un envase o el envase no está entre los del producto.
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
     * ¿Esta forma vende el envase sin abrir?
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     */
    public function esEnvaseEntero(?string $clave, Collection $presentaciones): bool
    {
        return $this->presentacionDe($clave, $presentaciones) instanceof ItemPresentacion;
    }

    /**
     * Lo tecleado, llevado a la unidad del kardex.
     *
     * Devuelve `null` cuando la forma no se puede resolver para este
     * producto —una presentación de otro ítem, una fracción en algo que
     * no se fracciona, una clave que no es de la lista—. Nunca un cero:
     * el cero es una cantidad legal y confundirlos sería cobrar la nada.
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

        return $cantidad->por($presentacion->unidades_por_presentacion);
    }

    /**
     * Cuántos envases sin abrir se están vendiendo, o nulo cuando no se
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

    private function traeMasDeUna(ItemPresentacion $presentacion): bool
    {
        $contenido = $presentacion->unidades_por_presentacion;

        return is_numeric($contenido) && Decimal::de($contenido)->mayorQue('1');
    }
}
