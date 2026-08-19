<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Pages;

use App\Filament\Resources\Proveedores\ProveedorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProveedores extends ListRecords
{
    protected static string $resource = ProveedorResource::class;

    public function getSubheading(): string
    {
        return 'A quién se le compra. Un proveedor con el que se dejó de trabajar se '
            .'desactiva, no se borra: sus compras siguen apuntando a él, y una entrada cuyo '
            .'origen desapareció es un kardex que no se puede explicar.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo proveedor'),
        ];
    }
}
