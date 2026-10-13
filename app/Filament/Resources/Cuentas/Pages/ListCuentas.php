<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Pages;

use App\Domain\Enums\EstadoCuenta;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Resources\Cuentas\CuentaResource;
use App\Filament\Resources\Cuentas\Tables\CuentasTable;
use App\Models\Cuenta;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListCuentas extends ListRecords
{
    protected static string $resource = CuentaResource::class;

    public function getSubheading(): string
    {
        return 'Todas las cuentas del hospital, por etapa. Para atender —abrir una cuenta o '
            .'agregarle cosas— usá Cuentas abiertas.';
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

    /**
     * ─────────────────────────────────────────────────────────────────
     * LAS ETAPAS DE UNA CUENTA, ARRIBA Y CON SU NÚMERO (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     *   · EN CURSO — abiertas y en egreso: lo que todavía no se facturó.
     *   · POR COBRAR — las que deben: el total es más que lo abonado.
     *     Incluye facturadas, porque lo del seguro se cobra después.
     *   · FACTURADAS, ANULADAS y TODAS.
     *
     * Reemplazan al panel de filtros de antes (interruptor, estado y
     * saldo), que ocupaba media pantalla y pedía «Aplicar filtros».
     * Solo las dos primeras llevan número, y solo cuando hay alguna.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'en_curso' => Tab::make('En curso')
                ->icon(Heroicon::OutlinedClock)
                ->badge(fn (): ?int => self::cuantas(CuentasTable::soloEnCurso(CuentaResource::getEloquentQuery())))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => CuentasTable::soloEnCurso($query)),

            'por_cobrar' => Tab::make('Por cobrar')
                ->icon(Heroicon::OutlinedBanknotes)
                ->badge(fn (): ?int => self::cuantas(CuentasTable::soloPorCobrar(CuentaResource::getEloquentQuery())))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => CuentasTable::soloPorCobrar($query)),

            'facturadas' => Tab::make('Facturadas')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => CuentasTable::soloEnEstado($query, EstadoCuenta::Cerrada)),

            'anuladas' => Tab::make('Anuladas')
                ->icon(Heroicon::OutlinedXCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => CuentasTable::soloEnEstado($query, EstadoCuenta::Anulada)),

            'todas' => Tab::make('Todas'),
        ];
    }

    /**
     * @param Builder<Cuenta> $consulta
     */
    private static function cuantas(Builder $consulta): ?int
    {
        $cuantas = $consulta->count();

        return $cuantas > 0 ? $cuantas : null;
    }
}
