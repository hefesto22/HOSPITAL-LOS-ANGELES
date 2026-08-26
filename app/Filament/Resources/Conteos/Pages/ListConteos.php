<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Pages;

use App\Filament\Resources\Conteos\ConteoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConteos extends ListRecords
{
    protected static string $resource = ConteoResource::class;

    public function getSubheading(): string
    {
        return 'Contar el estante y cuadrarlo con el sistema. Abrir un conteo no mueve nada; '
            .'lo que mueve el kardex es cerrarlo, y eso lo hace otra persona.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Abrir un conteo'),
        ];
    }
}
