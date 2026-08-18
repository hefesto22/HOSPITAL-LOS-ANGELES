<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    /**
     * Sin acción de borrar: el ítem se retira poniéndole fecha de fin de
     * vigencia. Borrarlo dejaría cargos apuntando a un ítem inexistente y
     * una factura que ya no se puede reimprimir.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
