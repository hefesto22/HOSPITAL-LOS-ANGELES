<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros\Pages;

use App\Filament\Resources\Encuentros\EncuentroResource;
use Filament\Resources\Pages\ListRecords;

class ListEncuentros extends ListRecords
{
    protected static string $resource = EncuentroResource::class;

    public function getSubheading(): string
    {
        return 'Cada atención, con sus tres tiempos de egreso: cuándo lo dio de alta el médico, '
            .'cuándo quedó liquidada la cuenta y cuándo se liberó la cama.';
    }
}
