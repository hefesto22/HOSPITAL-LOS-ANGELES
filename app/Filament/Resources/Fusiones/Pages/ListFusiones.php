<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fusiones\Pages;

use App\Filament\Resources\Fusiones\FusionResource;
use Filament\Resources\Pages\ListRecords;

class ListFusiones extends ListRecords
{
    protected static string $resource = FusionResource::class;

    /**
     * Sin acción de alta: las fusiones se proponen desde la ficha del
     * paciente, donde quien propone está mirando los datos de los dos.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
