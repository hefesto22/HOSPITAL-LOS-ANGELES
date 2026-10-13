<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\SihlaException;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Schemas\FormasDeVenta;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Services\CambiadorDeStockInfinito;
use App\Services\SincronizadorDeFormasDeVenta;
use App\Services\SincronizadorDePrincipiosActivos;
use App\Services\UbicadorDeProducto;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ficha de un producto de farmacia.
 *
 * Igual que en el catálogo: sin acción de borrar —se retira con fecha de
 * fin de vigencia— y con la calculadora de precio en la cabecera, que
 * acá sí tiene de dónde partir porque el producto tiene costo promedio.
 */
class EditProducto extends EditItem
{
    protected static string $resource = ProductoResource::class;

    /**
     * Las formas de venta no son columnas del producto: son sus
     * presentaciones, y se leen al abrir la ficha. Tampoco «Dónde hay»,
     * que son sus lugares mientras es infinito.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $producto = $this->getRecord();

        if ($producto instanceof Item) {
            $data = [...$data, ...FormasDeVenta::estadoDe($producto)];

            /*
             * Un infinito sin lugares anotados —de antes del 13-oct— abre
             * con farmacia marcada: así guardar la ficha lo deja ubicado,
             * en vez de trabarla con «marque al menos un almacén».
             */
            $ubicador = app(UbicadorDeProducto::class);
            $donde = $ubicador->dondeEsta($producto);

            $data['ubicaciones'] = $donde === [] && $producto->tieneStockInfinito()
                ? $ubicador->porDefecto()
                : $donde;
        }

        return $data;
    }

    /**
     * El producto y sus formas de venta se guardan juntos o no se guarda
     * ninguno: una ficha guardada a medias dejaría el nombre nuevo con
     * los envases viejos, sin aviso.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $formas = FormasDeVenta::paraGuardar($data[FormasDeVenta::CAMPO] ?? null);
        $sueltaPrincipal = FormasDeVenta::esSi($data[FormasDeVenta::SUELTA_PRINCIPAL] ?? null);

        /*
         * ─────────────────────────────────────────────────────────────
         * EL STOCK INFINITO NO SE GUARDA COMO UN CAMPO MÁS (13-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Marcarlo da de baja lo que había y quitarlo lo deja en cero:
         * mueve el kardex. Eso pasa por `CambiadorDeStockInfinito`, el
         * mismo de la acción en bloque, y no por el `update()` de la ficha.
         */
        $infinito = array_key_exists('stock_infinito', $data) ? (bool) $data['stock_infinito'] : null;

        /*
         * «Dónde hay» solo llega cuando el campo está a la vista —con el
         * infinito prendido—. Sin él, los lugares quedan como estaban.
         */
        $lugares = array_key_exists('ubicaciones', $data)
            ? UbicadorDeProducto::idsDelFormulario($data['ubicaciones'])
            : null;

        unset(
            $data[FormasDeVenta::CAMPO],
            $data[FormasDeVenta::SUELTA_PRINCIPAL],
            $data['stock_infinito'],
            $data['ubicaciones'],
        );

        try {
            return DB::transaction(function () use ($record, $data, $formas, $sueltaPrincipal, $infinito, $lugares): Model {
                $guardado = parent::handleRecordUpdate($record, $data);

                if ($guardado instanceof Item) {
                    app(SincronizadorDeFormasDeVenta::class)->sincronizar($guardado, $formas, $sueltaPrincipal);

                    if ($infinito !== null && (bool) $guardado->stock_infinito !== $infinito) {
                        app(CambiadorDeStockInfinito::class)->cambiar([$guardado], $infinito);
                    }

                    /*
                     * Después del cambio de bandera: al marcarlo, el
                     * cambiador ya lo dejó donde tenía existencia, y lo que
                     * se marcó en la ficha es la última palabra.
                     */
                    if ($lugares !== null && $lugares !== [] && $guardado->tieneStockInfinito()) {
                        app(UbicadorDeProducto::class)->dejarEn($guardado, $lugares);
                    }
                }

                return $guardado;
            });
        } catch (SihlaException $e) {
            /*
             * Las formas de venta y, desde el 13-oct-2026, el stock
             * infinito: dar de baja lo que había puede fallar con un error
             * de inventario, y tiene que verse igual que el de las formas.
             */
            Notification::make()
                ->danger()
                ->title('No se guardaron los cambios')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * ⚠️ Después de guardar y NO antes: Filament sincroniza la relación
     * muchos-a-muchos recién cuando el modelo ya existe, así que antes de
     * este punto la lista de principios todavía es la vieja.
     *
     * De acá sale `items.principio_activo`, que es lo que alimenta la
     * columna generada `nombre_busqueda` — el buscador del mostrador. El
     * porqué completo está en `SincronizadorDePrincipiosActivos`.
     */
    protected function afterSave(): void
    {
        /** @var Item $producto */
        $producto = $this->record;

        app(SincronizadorDePrincipiosActivos::class)->actualizarElTextoDe($producto);
    }
}
