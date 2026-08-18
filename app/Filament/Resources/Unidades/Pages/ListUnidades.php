<?php

declare(strict_types=1);

namespace App\Filament\Resources\Unidades\Pages;

use App\Filament\Resources\Unidades\UnidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUnidades extends ListRecords
{
    protected static string $resource = UnidadResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva unidad')
                ->modalHeading('Nueva unidad de medida')
                ->modalWidth('lg'),
        ];
    }
}
