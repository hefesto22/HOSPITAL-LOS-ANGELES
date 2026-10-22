<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Support\EscritorioDelRol;
use App\Support\UsuarioAutenticado;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que cargó hoy quien mira (Mauricio, 21-oct-2026): laboratorio,
 * consulta externa, imágenes y farmacia.
 *
 * Cuenta renglones y pacientes, no lempiras: §1.4 dice que laboratorio
 * no ve costos, y lo que le sirve a quien carga es saber si se le pasó
 * alguno, no cuánto facturó el hospital.
 *
 * Solo lo que sigue vivo —pendiente o ya facturado—: lo que se cargó y
 * se quitó no se hizo.
 */
class LoQueCargueHoy extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Lo tuyo de hoy';

    public static function canView(): bool
    {
        return EscritorioDelRol::muestra(EscritorioDelRol::LO_QUE_CARGUE) && Gate::allows('create', Cargo::class);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $yo = UsuarioAutenticado::id();

        if ($yo === null) {
            return [];
        }

        $fila = Cargo::query()
            ->where('created_by', $yo)
            ->where('fecha_operacion', now()->toDateString())
            ->whereIn('estado', [EstadoCargo::Pendiente->value, EstadoCargo::Facturado->value])
            ->selectRaw('count(*) as renglones, count(distinct cuenta_id) as cuentas')
            ->toBase()
            ->first();

        $renglones = data_get($fila, 'renglones');
        $pacientes = data_get($fila, 'cuentas');

        $renglones = is_numeric($renglones) ? (int) $renglones : 0;
        $pacientes = is_numeric($pacientes) ? (int) $pacientes : 0;

        $abiertas = Cuenta::query()
            ->where('created_by', $yo)
            ->whereIn('estado', EstadoCuenta::valoresVivos())
            ->count();

        return [
            Stat::make('Hoy cargaste', $renglones === 1 ? '1 renglón' : "{$renglones} renglones")
                ->description(match ($pacientes) {
                    0       => 'Todavía nada en el día',
                    1       => 'A 1 paciente',
                    default => "A {$pacientes} pacientes",
                })
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color($renglones > 0 ? 'success' : 'gray'),

            Stat::make('Cuentas que abriste', (string) $abiertas)
                ->description($abiertas === 0
                    ? 'Ninguna sigue abierta'
                    : 'Siguen abiertas: caja las cobra y las factura')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->url(CuentasAbiertas::getUrl()),
        ];
    }
}
