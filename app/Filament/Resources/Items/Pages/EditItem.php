<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Filament\Resources\Items\Actions\MoverDeAmbitoAction;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Resources\Pages\EditRecord;

/**
 * Los descuentos que se marcan en la pestaña «ISV y descuentos» son una
 * relación de Filament: se sincronizan solos al guardar. Esta clase no
 * tiene que sacarlos del formulario ni escribirlos.
 */
class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    /**
     * Sin acción de borrar: el ítem se retira poniéndole fecha de fin de
     * vigencia. Borrarlo dejaría cargos apuntando a un ítem inexistente y
     * una factura que ya no se puede reimprimir.
     *
     * La calculadora sí está acá, y solo en lo que se compra: un
     * honorario no tiene costo de entrada, así que el botón abriría un
     * modal que solo sabe decir que no (Ruta B del §4.1).
     *
     * Y acá vive «mover de ámbito», que antes era un ícono en cada fila
     * del listado. Es la salida de la jaula —sin ella, la jeringa que
     * alguien cargó en «Catálogo» se queda ahí para siempre— pero se usa
     * una vez cada varios meses: su lugar es la ficha del ítem, no
     * doscientas filas de una tabla que se lee todos los días.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * `true` = con botón de guardar. Llegar a esta pantalla ya
             * exige permiso para modificar el ítem, así que la
             * autorización queda resuelta por dónde se entró. Desde el
             * listado la misma acción es de solo lectura.
             */
            CalcularPrecioAction::make(puedeGuardar: true)
                ->visible(fn (Item $record): bool => CalcularPrecioAction::puedeVerse($record)),

            /*
             * Al listado después de mover, y no de vuelta a esta ficha:
             * el ítem ya no es de este recurso. `Producto` y `Item`
             * tienen alcances distintos, así que recargar esta misma URL
             * daría un 404 con cara de error del sistema.
             *
             * El redirect solo corre si el movimiento se hizo: la acción
             * hace `halt()` cuando el producto tiene inventario escrito,
             * y ahí la pantalla se queda quieta con el aviso a la vista.
             */
            MoverDeAmbitoAction::make()
                ->successRedirectUrl(fn (): string => $this->getResource()::getUrl('index')),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
