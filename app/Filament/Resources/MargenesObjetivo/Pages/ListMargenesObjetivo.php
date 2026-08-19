<?php

declare(strict_types=1);

namespace App\Filament\Resources\MargenesObjetivo\Pages;

use App\Filament\Resources\MargenesObjetivo\Actions\FijarMargenAction;
use App\Filament\Resources\MargenesObjetivo\MargenObjetivoResource;
use App\Models\MargenObjetivo;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

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
            /*
             * Auditoría llega a esta pantalla —lee el margen porque es la
             * mitad de la explicación de cada precio— pero no lo fija. Se
             * pide `create` y no `update` a propósito: fijar un margen
             * nuevo es insertar una fila, no editar la vigente. La policy
             * niega `update` para todo el mundo justamente por eso.
             */
            FijarMargenAction::make()
                ->visible(fn (): bool => Gate::allows('create', MargenObjetivo::class)),
        ];
    }
}
