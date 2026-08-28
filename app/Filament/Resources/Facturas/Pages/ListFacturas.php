<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Pages;

use App\Filament\Resources\Facturas\FacturaResource;
use Filament\Resources\Pages\ListRecords;

class ListFacturas extends ListRecords
{
    protected static string $resource = FacturaResource::class;

    public function getSubheading(): string
    {
        return 'Se emiten desde la cuenta del paciente. Acá se consultan y, si hace falta, se anulan con motivo.';
    }
}
