<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servicios\Pages;

use App\Filament\Resources\Servicios\ServicioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServicio extends CreateRecord
{
    protected static string $resource = ServicioResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
