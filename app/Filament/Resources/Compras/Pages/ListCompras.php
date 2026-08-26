<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompras extends ListRecords
{
    protected static string $resource = CompraResource::class;

    public function getSubheading(): string
    {
        return 'El papel: en qué se gastó y cuánto ISV se puede descontar. Esta pantalla NO mueve '
            .'inventario — lo que entra al estante se registra en Recepciones.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Registrar compra'),
        ];
    }
}
