<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Pages;

use App\Filament\Resources\PlantillasPresupuesto\PlantillaPresupuestoResource;
use Filament\Resources\Pages\EditRecord;

class EditPlantillaPresupuesto extends EditRecord
{
    protected static string $resource = PlantillaPresupuestoResource::class;
}
