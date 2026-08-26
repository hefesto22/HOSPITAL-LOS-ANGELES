<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem\Pages;

use App\Filament\Resources\CategoriasItem\CategoriaItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategoriaItem extends CreateRecord
{
    protected static string $resource = CategoriaItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
