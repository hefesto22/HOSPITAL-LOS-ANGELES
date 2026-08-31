<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Pages;

use App\Filament\Resources\Medicos\MedicoResource;
use Filament\Resources\Pages\EditRecord;

class EditMedico extends EditRecord
{
    protected static string $resource = MedicoResource::class;

    /**
     * Sin DeleteAction: un médico se cierra con vigencia. Borrarlo
     * dejaría sin dueño los cargos que hay que liquidarle.
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
