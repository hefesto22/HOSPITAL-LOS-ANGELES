<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\FormasDeVentaException;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Schemas\FormasDeVenta;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Services\SincronizadorDeFormasDeVenta;
use App\Services\SincronizadorDePrincipiosActivos;
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
     * presentaciones, y se leen al abrir la ficha.
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

        unset($data[FormasDeVenta::CAMPO], $data[FormasDeVenta::SUELTA_PRINCIPAL]);

        try {
            return DB::transaction(function () use ($record, $data, $formas, $sueltaPrincipal): Model {
                $guardado = parent::handleRecordUpdate($record, $data);

                if ($guardado instanceof Item) {
                    app(SincronizadorDeFormasDeVenta::class)->sincronizar($guardado, $formas, $sueltaPrincipal);
                }

                return $guardado;
            });
        } catch (FormasDeVentaException $e) {
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
