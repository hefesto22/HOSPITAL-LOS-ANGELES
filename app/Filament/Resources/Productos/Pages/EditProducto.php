<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Services\SincronizadorDePrincipiosActivos;

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
