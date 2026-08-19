<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Pages;

use App\Filament\Resources\Convenios\ConvenioResource;
use Filament\Resources\Pages\EditRecord;

class EditConvenio extends EditRecord
{
    protected static string $resource = ConvenioResource::class;

    /**
     * Sin acción de borrar: el convenio se termina poniéndole fecha de
     * fin de vigencia. Ver el docblock del Resource.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
