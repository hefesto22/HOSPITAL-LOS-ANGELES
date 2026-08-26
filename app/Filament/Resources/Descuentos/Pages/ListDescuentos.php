<?php

declare(strict_types=1);

namespace App\Filament\Resources\Descuentos\Pages;

use App\Filament\Resources\Descuentos\Actions\CrearDescuentoAction;
use App\Filament\Resources\Descuentos\DescuentoResource;
use App\Models\Descuento;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListDescuentos extends ListRecords
{
    protected static string $resource = DescuentoResource::class;

    public function getSubheading(): string
    {
        return 'Los descuentos que el hospital da con nombre propio. Se crean acá y se marcan '
            .'después en cada ítem del catálogo, en la pestaña «ISV y descuentos». '
            .'Los del Artículo 30 siguen aplicándose solos aunque no se marque nada: esta lista '
            .'suma, nunca resta — si un descuento de acá diera menos que la ley, gana la ley.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * Se pide `create` y no `update` a propósito: cambiar un
             * porcentaje es insertar una fila, no editar la vigente. La
             * policy niega `update` para todo el mundo justamente por
             * eso.
             *
             * Auditoría llega a esta pantalla —lee los porcentajes porque
             * son parte de la explicación de cada precio— pero no los
             * carga.
             */
            CrearDescuentoAction::make()
                ->visible(fn (): bool => Gate::allows('create', Descuento::class)),
        ];
    }
}
