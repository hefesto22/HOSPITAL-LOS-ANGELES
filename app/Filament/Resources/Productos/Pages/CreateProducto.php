<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Services\SincronizadorDePrincipiosActivos;
use Filament\Notifications\Notification;

/**
 * Alta de un producto de farmacia.
 *
 * Hereda de `CreateItem` y solo cambia el Resource: el alta de un ítem
 * es el mismo caso de uso de los dos lados del catálogo, y duplicarlo
 * dejaría dos caminos que escriben lo mismo, uno de los cuales
 * envejecería.
 *
 * Lo que sí cambia es a dónde termina: en farmacia, guardar el producto
 * es la MITAD del alta.
 */
class CreateProducto extends CreateItem
{
    protected static string $resource = ProductoResource::class;

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 GUARDAR NO TERMINA EL ALTA: EMPIEZA LA SEGUNDA MITAD
     * ─────────────────────────────────────────────────────────────────
     *
     * `CreateItem` manda al listado, y para un servicio está bien: una
     * cesárea se da de alta con su precio y ya está completa.
     *
     * Un producto no. «ACETAMINOFEN TABLETA 800 MG» todavía no se puede
     * comprar, ni recibir, ni escanear, ni cobrar: le faltan las
     * presentaciones, que son lo único que existe de verdad en el
     * estante. Mandar al listado obliga a buscar de nuevo el producto
     * que se acaba de crear, entrar a editarlo y recién ahí seguir — tres
     * pasos para continuar algo que nunca se había terminado.
     *
     * Se va a la ficha, donde la primera pestaña de abajo es
     * «Presentaciones de compra».
     */
    protected function getRedirectUrl(): string
    {
        $producto = $this->getRecord();

        return $producto instanceof Item
            ? $this->getResource()::getUrl('edit', ['record' => $producto])
            : $this->getResource()::getUrl('index');
    }

    /**
     * El aviso dice qué falta, no que todo salió bien.
     *
     * «Producto creado» a secas se lee como final de trámite, y el
     * producto todavía no sirve para nada.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Producto creado — falta la presentación')
            ->body(
                'Agregale abajo cómo viene envasado —la caja de 100, el blíster de 12— con su '
                .'código de barras. Sin eso no se puede comprar ni escanear, y el precio se '
                .'calcula al recibir la primera compra.'
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
