<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Pages;

use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Resources\Cuentas\CuentaResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCuentas extends ListRecords
{
    protected static string $resource = CuentaResource::class;

    public function getSubheading(): string
    {
        return 'Todas las cuentas, abiertas y cerradas. Para atender —abrir una cuenta o '
            .'agregarle cosas— usá la pantalla de cuentas abiertas.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('irACuentasAbiertas')
                ->label('Ir a cuentas abiertas')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->url(fn (): string => CuentasAbiertas::getUrl()),
        ];
    }
}
