<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Pages;

use App\Filament\Resources\Almacenes\AlmacenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlmacenes extends ListRecords
{
    protected static string $resource = AlmacenResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo almacén'),
        ];
    }
}
