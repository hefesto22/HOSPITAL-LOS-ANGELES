<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Resources\Pages\EditRecord;

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
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CalcularPrecioAction::make()
                ->visible(fn (Item $record): bool => $record->tipo->precioDerivadoDelCosto()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
