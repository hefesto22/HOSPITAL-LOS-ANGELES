<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\SihlaException;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Schemas\FormasDeVenta;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Services\SincronizadorDeFormasDeVenta;
use App\Services\SincronizadorDePrincipiosActivos;
use App\Services\UbicadorDeProducto;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un producto de farmacia.
 *
 * Hereda de `CreateItem` y solo cambia el Resource: el alta de un ítem
 * es el mismo caso de uso de los dos lados del catálogo, y duplicarlo
 * dejaría dos caminos que escriben lo mismo, uno de los cuales
 * envejecería.
 *
 * Lo que sí cambia: el producto nace con sus formas de venta —la caja,
 * el blíster— en la misma transacción, y al terminar se va a la ficha.
 */
class CreateProducto extends CreateItem
{
    protected static string $resource = ProductoResource::class;

    /**
     * Cuántos envases nacieron con el producto, para que el aviso diga
     * lo que hay y no lo que falta.
     */
    protected int $envasesCargados = 0;

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL PRODUCTO NACE CON SUS FORMAS DE VENTA (11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes el alta era la mitad: había que guardar, volver a la ficha y
     * cargar cada envase en su modal. Ahora la caja y el blíster vienen
     * en el mismo formulario y se guardan juntos con el producto, o no
     * se guarda nada (`SincronizadorDeFormasDeVenta`).
     *
     * Y, si nace con stock infinito, con sus lugares —«Dónde hay»—
     * (13-oct-2026): por defecto farmacia, o lo que se haya marcado.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $formas = FormasDeVenta::paraGuardar($data[FormasDeVenta::CAMPO] ?? null);
        $sueltaPrincipal = FormasDeVenta::esSi($data[FormasDeVenta::SUELTA_PRINCIPAL] ?? null);

        $lugares = UbicadorDeProducto::idsDelFormulario($data['ubicaciones'] ?? null);

        unset($data[FormasDeVenta::CAMPO], $data[FormasDeVenta::SUELTA_PRINCIPAL], $data['ubicaciones']);

        try {
            $producto = DB::transaction(function () use ($data, $formas, $sueltaPrincipal, $lugares): Model {
                $creado = parent::handleRecordCreation($data);

                if ($creado instanceof Item) {
                    app(SincronizadorDeFormasDeVenta::class)->sincronizar($creado, $formas, $sueltaPrincipal);

                    if ($creado->tieneStockInfinito()) {
                        $ubicador = app(UbicadorDeProducto::class);
                        $ubicador->dejarEn($creado, $lugares !== [] ? $lugares : $ubicador->porDefecto());
                    }
                }

                return $creado;
            });
        } catch (SihlaException $e) {
            Notification::make()
                ->danger()
                ->title('No se creó el producto')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }

        $this->envasesCargados = count($formas);

        return $producto;
    }

    /**
     * Se va a la ficha y no al listado: lo que sigue es el precio, y
     * mandar al listado obligaba a buscar de nuevo el producto que se
     * acababa de crear.
     */
    protected function getRedirectUrl(): string
    {
        $producto = $this->getRecord();

        return $producto instanceof Item
            ? $this->getResource()::getUrl('edit', ['record' => $producto])
            : $this->getResource()::getUrl('index');
    }

    /**
     * Sin envases el producto igual sirve —se recibe y se vende suelto—,
     * pero casi nunca es lo que se quiso: el aviso lo dice.
     */
    protected function getCreatedNotification(): ?Notification
    {
        if ($this->envasesCargados > 0) {
            return Notification::make()
                ->success()
                ->title('Producto creado con sus formas de venta');
        }

        return Notification::make()
            ->success()
            ->title('Producto creado — se vende solo suelto')
            ->body(
                'Si llega en caja o en blíster, agregalo en «Formas de venta»: así se recibe y se '
                .'cobra sin abrir.'
            );
    }

    /**
     * ⚠️ Después de guardar y NO antes: Filament sincroniza la relación
     * muchos-a-muchos recién cuando el modelo ya existe, así que antes de
     * este punto la lista de principios todavía es la vieja.
     *
     * De acá sale `items.principio_activo`, que es lo que alimenta la
     * columna generada `nombre_busqueda` — el buscador del mostrador. El
     * porqué completo está en `SincronizadorDePrincipiosActivos`.
     *
     * ⚠️ Y llama al padre. Antes no lo hacía, y eso dejaba muerto en
     * silencio todo lo que `CreateItem::afterCreate()` hace. Hoy no
     * cambia nada —el formulario de farmacia ya no pide precio de lista,
     * así que el padre sale por su propia guarda— pero un override que
     * tapa al padre sin decirlo es la forma de que el día que se agregue
     * algo allá, acá no pase.
     */
    protected function afterCreate(): void
    {
        parent::afterCreate();

        /** @var Item $producto */
        $producto = $this->record;

        app(SincronizadorDePrincipiosActivos::class)->actualizarElTextoDe($producto);
    }
}
