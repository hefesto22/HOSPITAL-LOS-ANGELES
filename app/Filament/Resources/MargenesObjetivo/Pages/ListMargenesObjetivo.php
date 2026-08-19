<?php

declare(strict_types=1);

namespace App\Filament\Resources\MargenesObjetivo\Pages;

use App\Filament\Resources\MargenesObjetivo\Actions\FijarMargenAction;
use App\Filament\Resources\MargenesObjetivo\MargenObjetivoResource;
use Filament\Resources\Pages\ListRecords;

class ListMargenesObjetivo extends ListRecords
{
    protected static string $resource = MargenObjetivoResource::class;

    public function getSubheading(): string
    {
        return 'El precio de lista se calcula con estos números y con el descuento máximo de ley: '
            .'costo × (1 + margen) ÷ (1 − descuento). Dividir por el descuento es lo que convierte '
            .'el margen en piso garantizado y no en objetivo que se incumple con cada paciente mayor.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            FijarMargenAction::make(),
        ];
    }
}
