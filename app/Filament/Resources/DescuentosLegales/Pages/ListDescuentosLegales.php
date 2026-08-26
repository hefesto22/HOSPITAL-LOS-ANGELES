<?php

declare(strict_types=1);

namespace App\Filament\Resources\DescuentosLegales\Pages;

use App\Filament\Resources\DescuentosLegales\Actions\CargarDescuentoAction;
use App\Filament\Resources\DescuentosLegales\DescuentoLegalResource;
use App\Models\DescuentoLegal;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListDescuentosLegales extends ListRecords
{
    protected static string $resource = DescuentoLegalResource::class;

    public function getSubheading(): string
    {
        return 'Son obligación legal, no política comercial. De estos números sale el precio de '
            .'lista de todo el catálogo: costo × (1 + margen) ÷ (1 − descuento máximo). Cada fila '
            .'lleva su fundamento porque lo que hay que mostrar ante un reclamo no es el porcentaje '
            .'de hoy, sino el que regía el día del servicio.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * Se pide `create` y no `update` a propósito: cargar un
             * porcentaje nuevo es insertar una fila, no editar la
             * vigente. La policy niega `update` para todo el mundo
             * justamente por eso.
             *
             * Auditoría llega a esta pantalla —lee los porcentajes porque
             * son la mitad de la explicación de cada precio— pero no los
             * carga.
             */
            CargarDescuentoAction::make()
                ->visible(fn (): bool => Gate::allows('create', DescuentoLegal::class)),
        ];
    }
}
