<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\Caja;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Widgets\Concerns\NumerosDelEscritorio;
use App\Models\Cuenta;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Support\ContextoSede;
use App\Support\EscritorioDelRol;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * La caja en el escritorio (Mauricio, 21-oct-2026): el turno que opera
 * quien mira, lo que hay en la gaveta, lo cobrado, lo que falta cobrarle
 * a los pacientes y cuántas facturas quedan en el CAI.
 *
 * Los mismos números que la pantalla «Caja», leídos de los mismos
 * métodos del turno (`efectivoEsperado()`, `porFormaDePago()`): si un
 * día cambia cómo se cuenta la gaveta, cambia en los dos lados.
 *
 * Sin refresco automático: se lee al entrar. Un escritorio que consulta
 * cada cinco segundos —lo que trae Filament por defecto— es una consulta
 * por cajera abierta todo el día contra la tabla de recibos (§13.2).
 */
class CajaDelEscritorio extends StatsOverviewWidget
{
    use NumerosDelEscritorio;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Caja';

    public static function canView(): bool
    {
        return EscritorioDelRol::muestra(EscritorioDelRol::CAJA) && Caja::canAccess();
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $sedeId = ContextoSede::actualId();

        $stats = $this->delTurno($sedeId);
        $stats[] = $this->porCobrar();

        $cai = self::statDelCai($sedeId);

        if ($cai instanceof Stat) {
            $stats[] = $cai;
        }

        return $stats;
    }

    /**
     * @return list<Stat>
     */
    private function delTurno(?int $sedeId): array
    {
        $usuario = Auth::user();
        $abridor = app(AbridorDeTurnoDeCaja::class);

        $turno = $usuario instanceof User ? $abridor->elQueOpera($usuario, $sedeId) : null;

        if (! $turno instanceof TurnoDeCaja) {
            $gaveta = $sedeId === null ? null : $abridor->abiertoEn($sedeId);

            if ($gaveta instanceof TurnoDeCaja) {
                $quien = $gaveta->usuario()->value('name');

                return [
                    Stat::make('La gaveta', mb_strtoupper($gaveta->etiquetaCorta()))
                        ->description('Está en el turno de '.(is_string($quien) ? $quien : 'otra persona').': ahí no cobrás vos')
                        ->descriptionIcon('heroicon-m-lock-closed')
                        ->color('warning')
                        ->url(Caja::getUrl()),
                ];
            }

            return [
                Stat::make('Tu turno', 'Sin abrir')
                    ->description('Abrilo en Caja para cobrar y facturar')
                    ->descriptionIcon('heroicon-m-arrow-right')
                    ->color('gray')
                    ->url(Caja::getUrl()),
            ];
        }

        $porForma = $turno->porFormaDePago();
        $partes = [];

        foreach (FormaDePago::cases() as $forma) {
            $monto = $porForma[$forma->value] ?? Decimal::cero();

            if (! $monto->esCero()) {
                $partes[] = $forma->etiqueta().' '.self::lempiras($monto);
            }
        }

        return [
            Stat::make('Tu turno', mb_strtoupper($turno->etiquetaCorta()))
                ->description('Abierto a las '.$turno->abierto_en->format('H:i').' · '.$turno->abierto_en->diffForHumans())
                ->descriptionIcon('heroicon-m-lock-open')
                ->color('success')
                ->url(Caja::getUrl()),

            Stat::make('En la gaveta', self::lempiras($turno->efectivoEsperado()))
                ->description('Fondo '.self::lempiras($turno->fondo_inicial).' más el efectivo recibido')
                ->descriptionIcon('heroicon-m-banknotes'),

            Stat::make('Cobrado en el turno', self::lempiras($turno->totalRecibido()))
                ->description($partes === [] ? 'Todavía no se cobró nada' : implode(' · ', $partes))
                ->descriptionIcon('heroicon-m-receipt-percent'),
        ];
    }

    /**
     * Lo que los pacientes deben en las cuentas abiertas: su parte menos
     * lo que ya abonaron. Una consulta, con los abonos sumados aparte:
     * cuentas por cobrar de veinte pacientes no son veinte consultas.
     */
    private function porCobrar(): Stat
    {
        $abonos = DB::table('abonos')
            ->select('cuenta_id')
            ->selectRaw('sum(total) as abonado')
            ->where('estado', EstadoAbono::Aplicado->value)
            ->groupBy('cuenta_id');

        $fila = Cuenta::query()
            ->whereIn('cuentas.estado', EstadoCuenta::valoresVivos())
            ->leftJoinSub($abonos, 'a', 'a.cuenta_id', '=', 'cuentas.id')
            ->selectRaw('count(*) filter (where cuentas.total_paciente - coalesce(a.abonado, 0) > 0) as con_saldo')
            ->selectRaw('coalesce(sum(greatest(cuentas.total_paciente - coalesce(a.abonado, 0), 0)), 0) as pendiente')
            ->toBase()
            ->first();

        $conSaldo = data_get($fila, 'con_saldo');
        $pendiente = data_get($fila, 'pendiente');

        $cuantas = is_numeric($conSaldo) ? (int) $conSaldo : 0;

        return Stat::make('Por cobrar a pacientes', self::lempiras(is_numeric($pendiente) ? (string) $pendiente : '0'))
            ->description($cuantas === 1 ? '1 cuenta abierta con saldo' : "{$cuantas} cuentas abiertas con saldo")
            ->descriptionIcon('heroicon-m-user-group')
            ->url(CuentasAbiertas::getUrl());
    }
}
