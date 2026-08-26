<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargos\Pages;

use App\Filament\Resources\Cargos\CargoResource;
use Filament\Resources\Pages\ListRecords;

class ListCargos extends ListRecords
{
    protected static string $resource = CargoResource::class;

    public function getSubheading(): string
    {
        return 'Cada línea con el precio, el descuento, el ISV y la cobertura que se le aplicaron '
            .'ese día. Nada de esto se edita: corregir es anular y volver a cargar.';
    }
}
