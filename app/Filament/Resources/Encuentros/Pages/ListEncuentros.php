<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros\Pages;

use App\Domain\Enums\EstadoEncuentro;
use App\Filament\Resources\Encuentros\EncuentroResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListEncuentros extends ListRecords
{
    protected static string $resource = EncuentroResource::class;

    public function getSubheading(): string
    {
        return 'Cada atención: quién, desde cuándo y en qué paso del egreso va.';
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LAS ETAPAS DE UNA ATENCIÓN, ARRIBA Y CON SU NÚMERO (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     *   · EN CURSO — abiertas o en atención: la gente que está adentro.
     *   · POR EGRESAR — con alta médica o ya liquidadas, que todavía no
     *     salieron. Es la cola que más capacidad come (§9.K8): la cama
     *     está ocupada por alguien que ya no la necesita.
     *   · CERRADAS y TODAS.
     *
     * Solo las dos primeras llevan número, y solo cuando hay alguna.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'en_curso' => Tab::make('En curso')
                ->icon(Heroicon::OutlinedUserGroup)
                ->badge(fn (): ?int => self::cuantos([EstadoEncuentro::Abierto, EstadoEncuentro::EnAtencion]))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('estado', [
                    EstadoEncuentro::Abierto->value,
                    EstadoEncuentro::EnAtencion->value,
                ])),

            'por_egresar' => Tab::make('Por egresar')
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->badge(fn (): ?int => self::cuantos([EstadoEncuentro::AltaMedica, EstadoEncuentro::AltaAdministrativa]))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('estado', [
                    EstadoEncuentro::AltaMedica->value,
                    EstadoEncuentro::AltaAdministrativa->value,
                ])),

            'cerradas' => Tab::make('Cerradas')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoEncuentro::Cerrado->value)),

            'todas' => Tab::make('Todas'),
        ];
    }

    /**
     * @param list<EstadoEncuentro> $estados
     */
    private static function cuantos(array $estados): ?int
    {
        $cuantos = EncuentroResource::getEloquentQuery()
            ->whereIn('estado', array_map(static fn (EstadoEncuentro $estado): string => $estado->value, $estados))
            ->count();

        return $cuantos > 0 ? $cuantos : null;
    }
}
