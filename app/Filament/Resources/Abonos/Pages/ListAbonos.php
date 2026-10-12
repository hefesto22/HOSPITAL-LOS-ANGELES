<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos\Pages;

use App\Domain\Enums\EstadoAbono;
use App\Filament\Resources\Abonos\AbonoResource;
use App\Models\Abono;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListAbonos extends ListRecords
{
    protected static string $resource = AbonoResource::class;

    public function getSubheading(): string
    {
        return 'Todo lo que entró a cuenta. Los abonos se reciben desde la cuenta del paciente, con el turno de caja abierto.';
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LOS MONTONES, ARRIBA Y A LA VISTA (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes eran un selector de estado y un interruptor «Solo los de hoy»
     * escondidos detrás del embudo, y la lista abría mezclando anulados
     * con vigentes. Mismo criterio que en Facturas: quien entra ya sabe
     * qué montón quiere.
     *
     *   · VIGENTES — la plata que sí entró. Abre por defecto.
     *   · HOY — lo del día, vivo o anulado: lo que se revisa al cerrar.
     *   · ANULADOS — con su motivo.
     *
     * «Hoy» compara contra `fecha_operacion` (DATE) con la fecha que da
     * PHP, nunca con `now()` de PostgreSQL (§7.5-1), y sin `whereDate`:
     * la columna ya es fecha y el índice `abonos_del_dia` sirve tal cual.
     *
     * Solo «Hoy» lleva número: es chico y se mira a cada rato. Contar
     * todos los vigentes de la historia en cada pintada no le sirve a
     * nadie (§9.A15).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'vigentes' => Tab::make('Vigentes')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoAbono::Aplicado->value)),

            'hoy' => Tab::make('Hoy')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->badge(fn (): int => self::deHoy(Abono::query())->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => self::deHoy($query)),

            'anulados' => Tab::make('Anulados')
                ->icon(Heroicon::OutlinedXCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoAbono::Anulado->value)),

            'todos' => Tab::make('Todos'),
        ];
    }

    /**
     * @param Builder<Abono> $query
     *
     * @return Builder<Abono>
     */
    private static function deHoy(Builder $query): Builder
    {
        return $query->where('fecha_operacion', now()->toDateString());
    }
}
