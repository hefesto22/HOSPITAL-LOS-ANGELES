<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
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
 * DE DÓNDE SALE EL ENVASE SIN ABRIR (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Entero y de UN lote: del que entró en ese envase, o del que entró en
 * uno más grande que lo trae adentro —el BLISTER X 10 sale de un lote
 * que entró en CAJA X 100—. Al revés no: una caja no se arma con
 * blísteres. Ver `envasesQueLoTraen()` y `sinAbrirEnElLote()`.
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
     * @var array<int, Collection<int, ItemPresentacion>>
     */
    private array $retiradas = [];

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

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 DE QUÉ ENVASES RECIBIDOS SALE ESTE SIN ABRIR (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Mauricio: «el blíster es una presentación que se vende, pero casi
     * siempre llega en cajas: si una caja trae 100 pastillas y el blíster
     * son 10, de una caja se sacan 10 blísteres».
     *
     * Un envase sale sin abrir de un lote que entró:
     *
     *   · en ESE envase —la caja, de un lote de cajas—; o
     *   · en uno más grande que lo trae ENTERO adentro.
     *
     * «Lo trae entero adentro» pide tres cosas, y ninguna sobra:
     *
     *   · Que el grande sea de una unidad que trae envases
     *     (`unidades.contiene_envases`). Una CAJA X 100 trae blísteres; un
     *     FRASCO X 100 trae las tabletas sueltas, y de ahí no sale ningún
     *     blíster aunque 100 sea múltiplo de 10. Se marca una vez por
     *     tipo de envase, no por producto.
     *   · Que sean de tipos distintos. Una CAJA X 30 no trae cajas de 10:
     *     son dos cajas de dos laboratorios.
     *   · Que el contenido sea un múltiplo exacto. Una CAJA X 100 no trae
     *     blísteres de 14.
     *
     * 🔴 Al revés nunca: una caja sin abrir no se arma con blísteres. La
     * caja que se lleva el paciente tiene un lote impreso, y armada con
     * otra cosa el cargo diría un lote que no es el de la caja (§9.F9).
     *
     * @param Collection<int, ItemPresentacion> $presentaciones las del ítem
     *
     * @return list<int> las presentaciones de los lotes que sirven
     */
    public function envasesQueLoTraen(ItemPresentacion $envase, Collection $presentaciones): array
    {
        $ids = [$envase->id];

        foreach ($presentaciones as $grande) {
            if ($this->loTraeAdentro($grande, $envase)) {
                $ids[] = $grande->id;
            }
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * Y LOS ENVASES RETIRADOS QUE LO TRAEN (13-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Si la CAJA X 100 pasó a ser de 120, la caja vieja ya no se
         * ofrece (`SincronizadorDeFormasDeVenta`), pero las que quedan en
         * el estante siguen trayendo sus diez blísteres de 10, y esos sí
         * se venden sin abrir. Sin esto, lo que quedó de antes se podía
         * vender solo suelto.
         */
        foreach ($this->retiradasDe($envase->item_id) as $retirada) {
            if (! in_array($retirada->id, $ids, true) && $this->loTraeAdentro($retirada, $envase)) {
                $ids[] = $retirada->id;
            }
        }

        return $ids;
    }

    /**
     * Cuánto se puede sacar de un lote para entregar este envase sin
     * abrir: los envases ENTEROS que caben en su saldo, en unidades del
     * kardex. Cero si el lote no entró en un envase que lo traiga.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN ENVASE SIN ABRIR SALE ENTERO DE UN SOLO LOTE
     * ─────────────────────────────────────────────────────────────────
     *
     * Un lote de cajas con 60 tabletas es una caja abierta: ahí hay seis
     * blísteres enteros y ninguna caja sin abrir. Si la caja se armara
     * con esas 60 y 40 del lote siguiente, el cargo diría dos lotes por
     * una caja que el paciente se llevó entera de uno solo, y el kardex
     * quedaría con 60 tabletas en un estante donde no hay ninguna.
     *
     * Se cuenta como cuenta quien está frente al estante: lo suelto se
     * saca de lo que ya está abierto. De un lote de cajas con 95 tabletas
     * salen 9 blísteres enteros —el décimo es el que tiene 5—, y de uno
     * con 160 sale una caja sin abrir —la otra es la que tiene 60—.
     *
     * @param int|null $envaseDelLote en qué envase entró el lote
     * @param Collection<int, ItemPresentacion> $presentaciones las del ítem
     */
    public function sinAbrirEnElLote(
        ItemPresentacion $envase,
        ?int $envaseDelLote,
        Decimal $saldo,
        Collection $presentaciones,
    ): Decimal {
        $contenido = $this->contenidoDe($envase);

        if ($envaseDelLote === null
            || ! $contenido instanceof Decimal
            || ! $saldo->mayorQue('0')
            || ! in_array($envaseDelLote, $this->envasesQueLoTraen($envase, $presentaciones), true)
        ) {
            return Decimal::cero();
        }

        return $this->enterosQueCaben($saldo, $contenido)->por($contenido);
    }

    /**
     * Cuántos envases chicos trae uno grande, enteros, o nulo si no los
     * trae: CAJA X 100 trae 10 BLISTER X 10; FRASCO X 100 no trae
     * ninguno, CAJA X 30 no trae CAJA X 10, CAJA X 100 no trae BLISTER X
     * 14. Las tres condiciones están explicadas en `envasesQueLoTraen()`.
     *
     * Recibe la unidad y el número sueltos, y no presentaciones, porque
     * la ficha lo pregunta mientras se escribe —«1 CAJA = 100 TAB · trae
     * 10 BLISTER»—, antes de que exista ninguna presentación.
     */
    public function cuantosTrae(Unidad $envaseGrande, Decimal $deGrande, int $unidadChica, Decimal $deChica): ?Decimal
    {
        if (! $envaseGrande->contiene_envases
            || $envaseGrande->id === $unidadChica
            || ! $deChica->mayorQue('0')
            || ! $deGrande->mayorQue($deChica)
        ) {
            return null;
        }

        $enteros = $this->enterosQueCaben($deGrande, $deChica);

        return $enteros->por($deChica)->igualA($deGrande) ? $enteros : null;
    }

    /**
     * Los envases retirados del producto, una consulta por producto y por
     * instancia —nunca en una propiedad estática: quedaría vieja entre
     * pruebas (§9.C8)—. Casi siempre vuelve vacía.
     *
     * @return Collection<int, ItemPresentacion>
     */
    private function retiradasDe(int $itemId): Collection
    {
        return $this->retiradas[$itemId] ??= ItemPresentacion::onlyTrashed()
            ->with('unidad')
            ->where('item_id', $itemId)
            ->get();
    }

    private function loTraeAdentro(ItemPresentacion $grande, ItemPresentacion $chico): bool
    {
        if ($grande->id === $chico->id || $grande->item_id !== $chico->item_id) {
            return false;
        }

        $deGrande = $this->contenidoDe($grande);
        $deChico = $this->contenidoDe($chico);

        if (! $deGrande instanceof Decimal || ! $deChico instanceof Decimal) {
            return false;
        }

        /*
         * Explícito y no perezoso: quien arma la lista con `with('unidad')`
         * no paga nada, y quien no lo hizo paga una consulta en vez de
         * reventar con la carga perezosa prohibida (§13.2).
         */
        if (! $grande->relationLoaded('unidad')) {
            $grande->load('unidad');
        }

        return $this->cuantosTrae($grande->unidad, $deGrande, $chico->unidad_id, $deChico) instanceof Decimal;
    }

    /**
     * Lo que trae el envase, o nulo si no es un número positivo.
     */
    private function contenidoDe(ItemPresentacion $presentacion): ?Decimal
    {
        $contenido = $presentacion->unidades_por_presentacion;

        if (! is_numeric($contenido)) {
            return null;
        }

        $decimal = Decimal::de($contenido);

        return $decimal->mayorQue('0') ? $decimal : null;
    }

    /**
     * Cuántos envases enteros caben: 95 entre 10 son 9, no 9.5.
     */
    private function enterosQueCaben(Decimal $total, Decimal $contenido): Decimal
    {
        return Decimal::de(bcdiv($total->exacto(), $contenido->exacto(), 0));
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
