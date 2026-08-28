<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos\Pages;

use App\Filament\Resources\Abonos\AbonoResource;
use Filament\Resources\Pages\ListRecords;

class ListAbonos extends ListRecords
{
    protected static string $resource = AbonoResource::class;

    public function getSubheading(): string
    {
        return 'Todo lo que entró a cuenta. Los abonos se reciben desde la cuenta del paciente, con el turno de caja abierto.';
    }
}
