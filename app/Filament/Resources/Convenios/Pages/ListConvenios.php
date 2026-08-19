<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Pages;

use App\Filament\Resources\Convenios\ConvenioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConvenios extends ListRecords
{
    protected static string $resource = ConvenioResource::class;

    public function getSubheading(): string
    {
        return 'Cada pagador declara sobre qué monto se le aplica el descuento del Art. 30. '
            .'La ley no lo resuelve cuando paga un tercero, así que la decisión queda escrita '
            .'acá y no la toma el sistema por su cuenta.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo convenio'),
        ];
    }
}
