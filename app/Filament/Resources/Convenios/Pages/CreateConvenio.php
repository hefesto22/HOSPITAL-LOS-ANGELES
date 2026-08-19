<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Pages;

use App\Filament\Resources\Convenios\ConvenioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConvenio extends CreateRecord
{
    protected static string $resource = ConvenioResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
