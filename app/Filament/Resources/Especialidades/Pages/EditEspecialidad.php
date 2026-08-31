<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Pages;

use App\Filament\Resources\Especialidades\EspecialidadResource;
use Filament\Resources\Pages\EditRecord;

class EditEspecialidad extends EditRecord
{
    protected static string $resource = EspecialidadResource::class;

    /**
     * Sin DeleteAction: una especialidad se cierra con vigencia.
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
