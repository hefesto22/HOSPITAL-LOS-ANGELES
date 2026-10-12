<?php

declare(strict_types=1);

namespace App\Filament\Resources\TurnosDeCaja\Pages;

use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Filament\Resources\TurnosDeCaja\TurnoDeCajaResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListTurnosDeCaja extends ListRecords
{
    protected static string $resource = TurnoDeCajaResource::class;

    /**
     * «Turnos de caja» y no el «Turnos De Caja» que arma `Str::headline`
     * (§10.5).
     */
    public function getTitle(): string
    {
        return 'Turnos de caja';
    }

    public function getSubheading(): string
    {
        return 'Cada turno con su arqueo: cuánto tenía que haber, cuánto se contó y si cuadró. Los turnos se abren y se cierran desde «Caja».';
    }

    /**
     * Las dos preguntas de dirección, como pestañas y no como filtros
     * escondidos: ¿quién tiene la gaveta abierta ahora? y ¿qué turnos no
     * cuadraron?
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos'),

            'abiertos' => Tab::make('Abiertos')
                ->icon(Heroicon::OutlinedLockOpen)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoTurnoDeCaja::Abierto->value)),

            'descuadrados' => Tab::make('No cuadraron')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('diferencia')
                    ->where('diferencia', '<>', 0)),
        ];
    }
}
