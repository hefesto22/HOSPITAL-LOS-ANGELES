<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Pages;

use App\Filament\Resources\Almacenes\AlmacenResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlmacen extends CreateRecord
{
    protected static string $resource = AlmacenResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
