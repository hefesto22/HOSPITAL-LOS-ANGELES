<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Filament\Pages\Caja;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Resources\Cuentas\CuentaResource;
use App\Filament\Resources\Facturas\FacturaResource;
use App\Filament\Widgets\Concerns\NumerosDelEscritorio;
use App\Models\Abono;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Support\ContextoSede;
use App\Support\EscritorioDelRol;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * El día del hospital de un vistazo, para dirección y auditoría
 * (Mauricio, 21-oct-2026).
 *
 * Facturado y cobrado hoy, lo que está cargado y sin facturar, en qué
 * turno está la gaveta y las cuentas que se anularon en el día.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ LAS ANULADAS VAN ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Anular una cuenta con renglones quita lo cargado y lo devuelve al
 * estante. Es la corrección de un error, y también es exactamente cómo
 * se esconde un cobro en efectivo sin recibo. No se le pone una traba a
 * la cajera —el error a las 3 am tiene que poder corregirse— pero
 * dirección lo VE todos los días, con cuánto y quién.
 *
 * Fechas por `fecha_operacion` y no por `created_at` (§7.5-4).
 */
class ResumenDelDia extends StatsOverviewWidget
{
    use NumerosDelEscritorio;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Hoy en el hospital';

    public static function canView(): bool
    {
        return EscritorioDelRol::muestra(EscritorioDelRol::RESUMEN_DEL_DIA)
            && Gate::allows('viewAny', Factura::class)
            && Gate::allows('viewAny', Cuenta::class);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $sedeId = ContextoSede::actualId();
        $hoy = now()->toDateString();

        $stats = [$this->facturado($hoy)];

        if (Gate::allows('viewAny', Abono::class)) {
            $stats[] = $this->cobrado($hoy);
        }

        $stats[] = $this->abiertas();

        if ($sedeId !== null && Gate::allows('viewAny', TurnoDeCaja::class)) {
            $stats[] = $this->gaveta($sedeId);
        }

        $stats[] = $this->anuladas();

        /*
         * El CAI ya sale en el cuadro de caja; acá solo para quien no lo
         * ve —auditoría—, para no repetirlo.
         */
        if (! EscritorioDelRol::muestra(EscritorioDelRol::CAJA)) {
            $cai = self::statDelCai($sedeId);

            if ($cai instanceof Stat) {
                $stats[] = $cai;
            }
        }

        return $stats;
    }

    private function facturado(string $hoy): Stat
    {
        $fila = Factura::query()
            ->where('fecha_operacion', $hoy)
            ->where('tipo', TipoDocumentoDeVenta::Factura->value)
            ->selectRaw('coalesce(sum(total) filter (where estado = ?), 0) as total', [EstadoFactura::Emitida->value])
            ->selectRaw('count(*) filter (where estado = ?) as emitidas', [EstadoFactura::Emitida->value])
            ->selectRaw('count(*) filter (where estado = ?) as anuladas', [EstadoFactura::Anulada->value])
            ->toBase()
            ->first();

        $emitidas = $this->entero(data_get($fila, 'emitidas'));
        $anuladas = $this->entero(data_get($fila, 'anuladas'));

        $detalle = $emitidas === 1 ? '1 factura' : "{$emitidas} facturas";

        if ($anuladas > 0) {
            $detalle .= $anuladas === 1 ? ' · 1 anulada' : " · {$anuladas} anuladas";
        }

        return Stat::make('Facturado hoy', self::lempiras($this->dinero(data_get($fila, 'total'))))
            ->description($detalle)
            ->descriptionIcon('heroicon-m-document-text')
            ->color('success')
            ->url(FacturaResource::getUrl());
    }

    private function cobrado(string $hoy): Stat
    {
        $fila = Abono::query()
            ->where('fecha_operacion', $hoy)
            ->where('estado', EstadoAbono::Aplicado->value)
            ->selectRaw('coalesce(sum(total), 0) as total, count(*) as recibos')
            ->toBase()
            ->first();

        $recibos = $this->entero(data_get($fila, 'recibos'));

        return Stat::make('Cobrado hoy', self::lempiras($this->dinero(data_get($fila, 'total'))))
            ->description($recibos === 1 ? '1 recibo' : "{$recibos} recibos")
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('success');
    }

    private function abiertas(): Stat
    {
        $fila = Cuenta::query()
            ->whereIn('estado', EstadoCuenta::valoresVivos())
            ->selectRaw('count(*) as cuantas, coalesce(sum(total), 0) as total')
            ->toBase()
            ->first();

        $cuantas = $this->entero(data_get($fila, 'cuantas'));

        return Stat::make('Cuentas abiertas', (string) $cuantas)
            ->description(self::lempiras($this->dinero(data_get($fila, 'total'))).' cargados sin facturar')
            ->descriptionIcon('heroicon-m-rectangle-stack')
            ->url(CuentasAbiertas::getUrl());
    }

    private function gaveta(int $sedeId): Stat
    {
        $turno = app(AbridorDeTurnoDeCaja::class)->abiertoEn($sedeId);

        if (! $turno instanceof TurnoDeCaja) {
            return Stat::make('La gaveta', 'Cerrada')
                ->description('No hay turno de caja abierto')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('gray')
                ->url(Caja::getUrl());
        }

        $quien = $turno->usuario()->value('name');

        return Stat::make('La gaveta', mb_strtoupper($turno->etiquetaCorta()))
            ->description(
                (is_string($quien) ? $quien.' · ' : '')
                .'desde las '.$turno->abierto_en->format('H:i')
                .' · '.self::lempiras($turno->efectivoEsperado()).' en efectivo'
            )
            ->descriptionIcon('heroicon-m-lock-open')
            ->color('success')
            ->url(Caja::getUrl());
    }

    private function anuladas(): Stat
    {
        $desde = now()->startOfDay();

        $fila = Cuenta::query()
            ->where('estado', EstadoCuenta::Anulada->value)
            ->where('anulada_en', '>=', $desde)
            ->selectRaw('count(*) as cuantas, coalesce(sum(total_al_anular), 0) as total')
            ->toBase()
            ->first();

        $cuantas = $this->entero(data_get($fila, 'cuantas'));

        if ($cuantas === 0) {
            return Stat::make('Cuentas anuladas hoy', '0')
                ->description('Ninguna')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('gray');
        }

        /** @var list<string> $quienes */
        $quienes = User::query()
            ->whereIn('id', Cuenta::query()
                ->select('anulada_por')
                ->where('estado', EstadoCuenta::Anulada->value)
                ->where('anulada_en', '>=', $desde)
                ->whereNotNull('anulada_por'))
            ->orderBy('name')
            ->limit(3)
            ->pluck('name')
            ->filter(fn (mixed $nombre): bool => is_string($nombre))
            ->values()
            ->all();

        $cuanto = self::lempiras($this->dinero(data_get($fila, 'total')));

        return Stat::make('Cuentas anuladas hoy', (string) $cuantas)
            ->description($quienes === [] ? $cuanto.' anulados' : $cuanto.' · '.implode(', ', $quienes))
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color('warning')
            ->url(CuentaResource::getUrl());
    }

    private function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    private function dinero(mixed $valor): string
    {
        return is_numeric($valor) ? (string) $valor : '0';
    }
}
