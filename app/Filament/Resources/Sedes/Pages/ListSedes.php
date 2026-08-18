<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes\Pages;

use App\Filament\Resources\Sedes\SedeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSedes extends ListRecords
{
    protected static string $resource = SedeResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva sede'),
        ];
    }
}
