<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Pages;

use App\Filament\Resources\Especialidades\EspecialidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEspecialidades extends ListRecords
{
    protected static string $resource = EspecialidadResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva especialidad'),
        ];
    }
}
