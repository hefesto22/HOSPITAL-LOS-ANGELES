<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servicios\Pages;

use App\Filament\Resources\Servicios\ServicioResource;
use Filament\Resources\Pages\EditRecord;

class EditServicio extends EditRecord
{
    protected static string $resource = ServicioResource::class;

    /**
     * Sin DeleteAction: un servicio se cierra con vigencia, no se borra.
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
