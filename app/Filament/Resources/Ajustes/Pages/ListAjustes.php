<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Pages;

use App\Filament\Resources\Ajustes\AjusteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAjustes extends ListRecords
{
    protected static string $resource = AjusteResource::class;

    public function getSubheading(): string
    {
        return 'Mermas, bajas por vencimiento, correcciones y las diferencias que asienta cada '
            .'conteo físico. Un ajuste asentado no se edita: se corrige con otro.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Registrar un ajuste'),
        ];
    }
}
