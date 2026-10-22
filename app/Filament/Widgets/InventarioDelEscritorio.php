<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Existencias;
use App\Filament\Resources\Prestamos\PrestamoResource;
use App\Filament\Resources\Recepciones\RecepcionResource;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Prestamo;
use App\Models\Recepcion;
use App\Support\AlmacenesDelUsuario;
use App\Support\EscritorioDelRol;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * El inventario en el escritorio (Mauricio, 21-oct-2026): farmacia,
 * bodega, dirección y auditoría.
 *
 * Lo que se vence es lo primero: un lote vencido en el estante es un
 * medicamento que alguien puede despachar (§9.G10). Los días salen de
 * `sihla.inventario.dias_alerta_vencimiento` —el más corto y el más
 * largo—, los mismos con que avisa el resto del sistema.
 *
 * Cada quien ve SUS estantes: farmacia la farmacia, bodega la bodega
 * (`AlmacenesDelUsuario`), igual que en «Existencias».
 */
class InventarioDelEscritorio extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Inventario';

    public static function canView(): bool
    {
        return EscritorioDelRol::muestra(EscritorioDelRol::INVENTARIO) && Existencias::canAccess();
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = $this->vencimientos();

        if (Gate::allows('viewAny', Prestamo::class)) {
            $deben = Prestamo::query()->queSeDeben()->count();

            $stats[] = Stat::make('Préstamos sin saldar', (string) $deben)
                ->description($deben === 0 ? 'No se le debe nada a nadie' : 'Lo que nos prestaron y falta devolver o pagar')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color($deben === 0 ? 'gray' : 'warning')
                ->url(PrestamoResource::getUrl());
        }

        if (Gate::allows('viewAny', Recepcion::class)) {
            $consulta = Recepcion::query()
                ->whereNull('revisada_en')
                ->whereIn('almacen_id', Almacen::query()->select('id'));

            AlmacenesDelUsuario::filtrar($consulta);

            $porRevisar = $consulta->count();

            $stats[] = Stat::make('Recepciones por revisar', (string) $porRevisar)
                ->description($porRevisar === 0 ? 'Todas revisadas' : 'Las revisa otra persona que no las recibió')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color($porRevisar === 0 ? 'gray' : 'warning')
                ->url(RecepcionResource::getUrl());
        }

        return $stats;
    }

    /**
     * Una sola consulta para los tres números: vencidos, por vencer
     * pronto y por vencer más adelante, contando cada lote en cada
     * estante donde todavía queda algo.
     *
     * @return list<Stat>
     */
    private function vencimientos(): array
    {
        [$pronto, $despues] = $this->dias();

        $hoy = now()->toDateString();
        $hastaPronto = now()->addDays($pronto)->toDateString();
        $hastaDespues = now()->addDays($despues)->toDateString();

        $consulta = Existencia::query()
            ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
            ->where('existencias.cantidad', '>', 0)
            ->whereNull('lotes.deleted_at')
            ->whereNotNull('lotes.fecha_vencimiento')
            ->where('lotes.fecha_vencimiento', '<=', $hastaDespues)
            ->whereIn('existencias.almacen_id', Almacen::query()->select('id'));

        AlmacenesDelUsuario::filtrar($consulta, 'existencias.almacen_id');

        $fila = $consulta
            ->selectRaw('count(*) filter (where lotes.fecha_vencimiento < ?) as vencidos', [$hoy])
            ->selectRaw(
                'count(*) filter (where lotes.fecha_vencimiento >= ? and lotes.fecha_vencimiento <= ?) as pronto',
                [$hoy, $hastaPronto],
            )
            ->selectRaw('count(*) filter (where lotes.fecha_vencimiento > ?) as despues', [$hastaPronto])
            ->toBase()
            ->first();

        $vencidos = $this->entero(data_get($fila, 'vencidos'));
        $enPronto = $this->entero(data_get($fila, 'pronto'));
        $enDespues = $this->entero(data_get($fila, 'despues'));

        return [
            Stat::make('Vencidos en el estante', (string) $vencidos)
                ->description($vencidos === 0 ? 'Ninguno' : 'Sacalos del estante: no se despachan')
                ->descriptionIcon($vencidos === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($vencidos === 0 ? 'success' : 'danger')
                ->url(Existencias::getUrl()),

            Stat::make("Vencen en {$pronto} días", (string) $enPronto)
                ->description($enPronto === 1 ? 'Lote con existencia' : 'Lotes con existencia')
                ->descriptionIcon('heroicon-m-clock')
                ->color($enPronto === 0 ? 'gray' : 'warning')
                ->url(Existencias::getUrl()),

            Stat::make("Vencen en {$despues} días", (string) ($enPronto + $enDespues))
                ->description('Para planificar qué sale primero')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray')
                ->url(Existencias::getUrl()),
        ];
    }

    /**
     * El aviso más corto y el más largo de la configuración.
     *
     * @return array{0: int, 1: int}
     */
    private function dias(): array
    {
        $configurados = config('sihla.inventario.dias_alerta_vencimiento', [90, 60, 30]);

        $dias = is_array($configurados)
            ? array_values(array_filter(array_map(
                static fn (mixed $d): int => is_numeric($d) ? (int) $d : 0,
                $configurados,
            ), static fn (int $d): bool => $d > 0))
            : [];

        if ($dias === []) {
            return [30, 90];
        }

        return [min($dias), max($dias)];
    }

    private function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }
}
