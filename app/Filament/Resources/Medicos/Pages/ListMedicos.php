<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Pages;

use App\Filament\Resources\Medicos\MedicoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMedicos extends ListRecords
{
    protected static string $resource = MedicoResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo médico'),
        ];
    }
}
