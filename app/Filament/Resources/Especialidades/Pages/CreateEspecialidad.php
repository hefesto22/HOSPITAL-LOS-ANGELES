<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Pages;

use App\Filament\Resources\Especialidades\EspecialidadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEspecialidad extends CreateRecord
{
    protected static string $resource = EspecialidadResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
