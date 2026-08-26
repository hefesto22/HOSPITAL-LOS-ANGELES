<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem\Pages;

use App\Filament\Resources\CategoriasItem\CategoriaItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategoriasItem extends ListRecords
{
    protected static string $resource = CategoriaItemResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva categoría'),
        ];
    }
}
