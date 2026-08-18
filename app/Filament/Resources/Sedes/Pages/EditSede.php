<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes\Pages;

use App\Filament\Resources\Sedes\SedeResource;
use Filament\Resources\Pages\EditRecord;

class EditSede extends EditRecord
{
    protected static string $resource = SedeResource::class;

    /**
     * Sin DeleteAction a propósito: una sede se cierra con vigencia, no se
     * borra. Ver SedeResource::canDelete().
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
