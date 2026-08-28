<?php

declare(strict_types=1);

namespace App\Filament\Resources\TurnosDeCaja\Pages;

use App\Filament\Resources\TurnosDeCaja\TurnoDeCajaResource;
use Filament\Resources\Pages\ListRecords;

class ListTurnosDeCaja extends ListRecords
{
    protected static string $resource = TurnoDeCajaResource::class;

    public function getSubheading(): string
    {
        return 'Cada turno con su arqueo. Los turnos se abren y se cierran desde «Caja».';
    }
}
