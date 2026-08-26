<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrincipiosActivos\Pages;

use App\Filament\Resources\PrincipiosActivos\PrincipioActivoResource;
use App\Models\PrincipioActivo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrincipiosActivos extends ListRecords
{
    protected static string $resource = PrincipioActivoResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo principio activo')
                ->modalHeading('Nuevo principio activo')
                ->modalWidth('xl')
                /*
                 * El código lo propone el formulario, que es el que se
                 * usa por las dos puertas. Esto queda como red: si
                 * alguien borra el campo antes de guardar, igual sale con
                 * su correlativo en vez de reventar contra el NOT NULL.
                 */
                ->mutateDataUsing(function (array $data): array {
                    if (blank($data['codigo'] ?? null)) {
                        $data['codigo'] = PrincipioActivo::siguienteCodigo();
                    }

                    return $data;
                }),
        ];
    }
}
