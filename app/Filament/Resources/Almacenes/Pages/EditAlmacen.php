<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Pages;

use App\Filament\Resources\Almacenes\AlmacenResource;
use Filament\Resources\Pages\EditRecord;

class EditAlmacen extends EditRecord
{
    protected static string $resource = AlmacenResource::class;

    /**
     * Sin DeleteAction: el kardex es append-only y borrar el almacén
     * dejaría movimientos huérfanos.
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
