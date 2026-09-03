<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos\Pages;

use App\Filament\Resources\Prestamos\Actions\RegistrarPrestamoAction;
use App\Filament\Resources\Prestamos\PrestamoResource;
use App\Models\Prestamo;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListPrestamos extends ListRecords
{
    protected static string $resource = PrestamoResource::class;

    public function getSubheading(): string
    {
        return 'Lo que el hospital pidió prestado cuando no había existencia. El kardex dice qué hay; '
            .'esta pantalla dice a quién hay que devolvérselo. Lo que trae el médico o la familia del '
            .'paciente se registra igual, pero no cuenta como deuda.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            RegistrarPrestamoAction::make()
                ->visible(fn (): bool => Gate::allows('create', Prestamo::class)),
        ];
    }
}
