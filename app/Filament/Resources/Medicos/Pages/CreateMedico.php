<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Pages;

use App\Filament\Resources\Medicos\MedicoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMedico extends CreateRecord
{
    protected static string $resource = MedicoResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
