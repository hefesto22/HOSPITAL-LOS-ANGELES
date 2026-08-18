<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes\Pages;

use App\Filament\Resources\Sedes\SedeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSede extends CreateRecord
{
    protected static string $resource = SedeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
