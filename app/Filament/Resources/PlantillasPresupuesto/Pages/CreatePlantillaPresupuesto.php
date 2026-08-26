<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Pages;

use App\Filament\Resources\PlantillasPresupuesto\PlantillaPresupuestoResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlantillaPresupuesto extends CreateRecord
{
    protected static string $resource = PlantillaPresupuestoResource::class;

    /**
     * Después de crearla se va a la edición, que es donde están las
     * líneas: una plantilla sin renglones no sirve para nada, y mandar
     * al listado invita a dejarla vacía.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
