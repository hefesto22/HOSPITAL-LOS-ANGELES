<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\FormasDeVentaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las formas de venta de un producto, guardadas de una vez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 TODAS JUNTAS, EN LA FICHA (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Al agregar blíster dirá de cuánto es el blíster, y caja pues es el
 * total de pastillas; así en automático se crean como se vende, y es
 * más fácil marcar qué mercadería entra.»
 *
 * Antes cada envase se cargaba en su propio modal, con nombre, vigencia
 * y fecha, después de haber guardado el producto. Ahora la ficha dice
 * «TABLETA suelta · BLÍSTER de 10 · CAJA de 100» y al guardar quedan
 * creadas las presentaciones: la recepción ofrece exactamente esas, y el
 * cobro también (`FormasDeCobro`).
 *
 * La unidad suelta no es una fila: es «Se dispensa en», que ya está en
 * la ficha. Acá solo van los envases.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO SE PUEDE, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un envase por el que ya entró mercadería —tiene lotes, líneas de
 * recepción, cargos, préstamos o precio propio— no se borra ni cambia
 * de tamaño. Si la CAJA X 100 pasara a ser CAJA X 50, cada lote que
 * entró en esa caja se leería con la mitad, y el kardex no avisa: se
 * descubre en el conteo (§9.G11). Si ahora viene distinta, es OTRA forma
 * de venta y se agrega al lado.
 *
 * Uno que nunca se usó se quita de verdad (borrado lógico): no explica
 * ningún lote ni ninguna factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PRINCIPAL LA ELIGE QUIEN CARGA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es `es_predeterminada`: la que el listado y los presupuestos muestran
 * primero, y el envase en que se piensa el precio. La elige la estrella
 * de la ficha:
 *
 *   · una fila marcada → esa presentación;
 *   · la suelta marcada → ninguna presentación, porque la principal es
 *     la unidad misma (la bolsa de suero que llega en CAJA X 12);
 *   · nadie marcó nada → la más grande, que acierta con las tabletas.
 */
final class SincronizadorDeFormasDeVenta
{
    /**
     * Las tablas que guardan de qué envase salió algo. Con una fila en
     * cualquiera, el envase ya explica algo que pasó.
     */
    private const DONDE_SE_USA = ['lotes', 'recepcion_lineas', 'cargos', 'prestamos', 'tarifarios'];

    /**
     * @param list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal?: bool}> $formas
     * @param bool $sueltaPrincipal la principal es la unidad suelta, no un envase
     *
     * @throws FormasDeVentaException
     */
    public function sincronizar(Item $item, array $formas, bool $sueltaPrincipal = false): void
    {
        DB::transaction(function () use ($item, $formas, $sueltaPrincipal): void {
            $actuales = ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->get();

            $usadas = $this->usadas(array_map(
                static fn (ItemPresentacion $presentacion): int => $presentacion->id,
                $actuales->all(),
            ));
            $limpias = $this->validadas($formas);

            /*
             * La más grande primero: es la que queda habitual al nacer.
             */
            usort(
                $limpias,
                static fn (array $una, array $otra): int => $otra['contenido']->comparar($una['contenido']),
            );

            $referidas = array_values(array_filter(array_map(
                static fn (array $forma): ?int => $forma['presentacion_id'],
                $limpias,
            )));

            $quedan = [];
            $principal = null;

            /*
             * ─────────────────────────────────────────────────────────
             * 1 · LAS QUE YA EXISTÍAN, CON SUS CAMBIOS
             * ─────────────────────────────────────────────────────────
             *
             * Primero, para que el índice único (producto, envase,
             * contenido) vea los valores nuevos antes de crear nada.
             */
            foreach ($limpias as $forma) {
                $presentacion = $this->deEsteProducto($actuales, $forma['presentacion_id']);

                if (! $presentacion instanceof ItemPresentacion) {
                    continue;
                }

                $this->actualizar($item, $presentacion, $forma['unidad'], $forma['contenido'], $usadas);
                $quedan[] = $presentacion->id;

                if ($forma['principal']) {
                    $principal = $presentacion->id;
                }
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 2 · LAS NUEVAS
             * ─────────────────────────────────────────────────────────
             *
             * Si ya hay una igual que nadie reclamó —se quitó la fila y
             * se volvió a agregar—, es esa: se queda, no se duplica.
             */
            foreach ($limpias as $forma) {
                if ($this->deEsteProducto($actuales, $forma['presentacion_id']) instanceof ItemPresentacion) {
                    continue;
                }

                $igual = $actuales->first(
                    fn (ItemPresentacion $existente): bool => ! in_array($existente->id, $referidas, true)
                        && ! in_array($existente->id, $quedan, true)
                        && $this->esLaMisma($existente, $forma['unidad'], $forma['contenido']),
                );

                $id = $igual instanceof ItemPresentacion
                    ? $igual->id
                    : ItemPresentacion::query()->create([
                        'item_id'                   => $item->id,
                        'unidad_id'                 => $forma['unidad']->id,
                        'unidades_por_presentacion' => $forma['contenido']->paraBase(),
                        'nombre'                    => $this->nombre($item, $forma['unidad'], $forma['contenido']),
                        'vigencia_desde'            => now()->toDateString(),
                    ])->id;

                $quedan[] = $id;

                if ($forma['principal']) {
                    $principal = $id;
                }
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 3 · LAS QUE SE QUITARON
             * ─────────────────────────────────────────────────────────
             */
            foreach ($actuales as $presentacion) {
                if (in_array($presentacion->id, $quedan, true)) {
                    continue;
                }

                if (in_array($presentacion->id, $usadas, true)) {
                    throw FormasDeVentaException::noSeQuita($presentacion->nombre);
                }

                $presentacion->delete();
            }

            $this->marcarLaPrincipal($item, $principal, $sueltaPrincipal);
        });
    }

    /**
     * De estas presentaciones, las que ya explican algo que pasó: un
     * lote, una recepción, un cargo, un préstamo o un precio propio.
     *
     * Una consulta por tabla, no una por presentación.
     *
     * @param array<int, int> $ids
     *
     * @return list<int>
     */
    public function usadas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $usadas = [];

        foreach (self::DONDE_SE_USA as $tabla) {
            foreach (DB::table($tabla)->whereIn('item_presentacion_id', $ids)->distinct()->pluck('item_presentacion_id') as $id) {
                $usadas[] = (int) $id;
            }
        }

        return array_values(array_unique($usadas));
    }

    /**
     * Lo que manda la ficha, comprobado: envase que existe, contenido
     * positivo y ninguna repetida.
     *
     * @param list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal?: bool}> $formas
     *
     * @return list<array{presentacion_id: int|null, unidad: Unidad, contenido: Decimal, principal: bool}>
     *
     * @throws FormasDeVentaException
     */
    private function validadas(array $formas): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $forma): ?int => $forma['unidad_id'],
            $formas,
        )));

        $unidades = Unidad::query()->whereIn('id', $ids)->get();

        $limpias = [];
        $vistas = [];

        foreach ($formas as $forma) {
            $unidad = $forma['unidad_id'] === null ? null : $unidades->firstWhere('id', $forma['unidad_id']);

            if (! $unidad instanceof Unidad) {
                throw FormasDeVentaException::sinEnvase();
            }

            $texto = $forma['unidades_por_presentacion'];
            $contenido = is_string($texto) && is_numeric($texto) ? Decimal::de($texto) : null;

            if (! $contenido instanceof Decimal || ! $contenido->mayorQue('0')) {
                throw FormasDeVentaException::sinContenido($unidad->codigo);
            }

            $clave = $unidad->id.':'.$contenido->paraBase();

            if (isset($vistas[$clave])) {
                throw FormasDeVentaException::repetida(
                    $unidad->codigo.' X '.ItemPresentacion::sinCerosDeMas($contenido->paraBase()),
                );
            }

            $vistas[$clave] = true;

            $limpias[] = [
                'presentacion_id' => $forma['presentacion_id'],
                'unidad'          => $unidad,
                'contenido'       => $contenido,
                'principal'       => $forma['principal'] ?? false,
            ];
        }

        return $limpias;
    }

    /**
     * @param list<int> $usadas
     *
     * @throws FormasDeVentaException
     */
    private function actualizar(
        Item $item,
        ItemPresentacion $presentacion,
        Unidad $unidad,
        Decimal $contenido,
        array $usadas,
    ): void {
        if ($this->esLaMisma($presentacion, $unidad, $contenido)) {
            return;
        }

        if (in_array($presentacion->id, $usadas, true)) {
            throw FormasDeVentaException::yaEntroMercaderia($presentacion->nombre);
        }

        $presentacion->forceFill([
            'unidad_id'                 => $unidad->id,
            'unidades_por_presentacion' => $contenido->paraBase(),
            'nombre'                    => $this->nombre($item, $unidad, $contenido),
        ])->save();
    }

    /**
     * La presentación que la fila dice ser, solo si es de este producto.
     * Un id de otro producto —un formulario manipulado— no se toca: se
     * trata como fila nueva.
     *
     * @param Collection<int, ItemPresentacion> $actuales
     */
    private function deEsteProducto(Collection $actuales, ?int $id): ?ItemPresentacion
    {
        if ($id === null) {
            return null;
        }

        $presentacion = $actuales->firstWhere('id', $id);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    private function esLaMisma(ItemPresentacion $presentacion, Unidad $unidad, Decimal $contenido): bool
    {
        return $presentacion->unidad_id === $unidad->id
            && is_numeric($presentacion->unidades_por_presentacion)
            && Decimal::de($presentacion->unidades_por_presentacion)->igualA($contenido);
    }

    /**
     * «CAJA X 100 TAB», con la misma regla que usaba el modal de antes
     * (`ItemPresentacion::comoSeEnvasa()`): el producto no se repite
     * adelante, se le pega al mostrar.
     */
    private function nombre(Item $item, Unidad $unidad, Decimal $contenido): string
    {
        $dispensacion = $item->unidadDispensacion;

        return ItemPresentacion::comoSeEnvasa(
            $unidad->codigo,
            ItemPresentacion::sinCerosDeMas($contenido->paraBase()),
            $dispensacion instanceof Unidad ? $dispensacion->codigo : 'UND',
        );
    }

    /**
     * Deja la estrella donde quedó en la ficha.
     *
     * Las presentaciones nuevas nacen marcando la primera como habitual
     * (`ItemPresentacion::booted()`), así que esto corre al final y pisa
     * lo que haya hecho ese automático.
     */
    private function marcarLaPrincipal(Item $item, ?int $principal, bool $sueltaPrincipal): void
    {
        if ($principal === null && $sueltaPrincipal) {
            /*
             * `update()` masivo: no dispara eventos de modelo, y no hay
             * ninguna presentación que tenga que quedar marcada.
             */
            ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->where('es_predeterminada', true)
                ->update(['es_predeterminada' => false]);

            return;
        }

        $elegida = $principal === null
            ? ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->orderByDesc('unidades_por_presentacion')
                ->orderBy('id')
                ->first()
            : ItemPresentacion::query()->find($principal);

        /*
         * `save()` y no un `update()` masivo: el `saving` del modelo es
         * el que desmarca a la anterior, y la base además impide dos.
         */
        if ($elegida instanceof ItemPresentacion && ! $elegida->es_predeterminada) {
            $elegida->es_predeterminada = true;
            $elegida->save();
        }
    }
}
