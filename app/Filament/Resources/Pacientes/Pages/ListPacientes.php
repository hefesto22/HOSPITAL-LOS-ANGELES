<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Filament\Resources\Pacientes\PacienteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * El buscador de admisión.
 *
 * La tabla arranca vacía a propósito: se busca primero y recién después
 * se registra. El porqué está en PacientesTable.
 */
class ListPacientes extends ListRecords
{
    protected static string $resource = PacienteResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar paciente nuevo')
                ->icon('heroicon-o-user-plus'),
        ];
    }
}
