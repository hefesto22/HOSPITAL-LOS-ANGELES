<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Pages;

use App\Filament\Resources\PlantillasPresupuesto\PlantillaPresupuestoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlantillasPresupuesto extends ListRecords
{
    protected static string $resource = PlantillaPresupuestoResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva plantilla'),
        ];
    }
}
