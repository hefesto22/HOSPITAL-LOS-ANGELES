<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai\Pages;

use App\Filament\Resources\RangosCai\RangoCaiResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRangosCai extends ListRecords
{
    protected static string $resource = RangoCaiResource::class;

    public function getSubheading(): string
    {
        return 'Sin un rango activo y vigente, la caja no puede emitir. Se cargan del papel que entrega el SAR.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Cargar una resolución'),
        ];
    }
}
