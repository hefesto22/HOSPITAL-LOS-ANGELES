<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Pages;

use App\Filament\Resources\Ajustes\AjusteResource;
use Filament\Resources\Pages\ViewRecord;

class VerAjuste extends ViewRecord
{
    protected static string $resource = AjusteResource::class;

    public function getSubheading(): string
    {
        return 'Este documento es inmutable: el kardex ya se movió. Si algo está mal, se '
            .'registra un ajuste de corrección — no se edita este.';
    }
}
