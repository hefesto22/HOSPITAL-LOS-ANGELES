<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\RangosCai\RangoCaiResource;
use App\Models\RangoCai;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que comparten los cuadros del escritorio: cómo se escribe la plata
 * y el aviso del CAI.
 */
trait NumerosDelEscritorio
{
    protected static function lempiras(Decimal|string|null $monto): string
    {
        return Monto::de($monto ?? '0')->formateado();
    }

    /**
     * Cuántas facturas quedan en el CAI con el que se va a emitir.
     *
     * Es el MISMO rango que toma `EmisorDeFactura`: el primero activo de
     * la sede para facturas. Quedarse sin CAI en un hospital es no poder
     * darle el alta administrativa a nadie (§8.6.4), así que avisa con el
     * doble umbral de `sihla.facturacion`: porcentaje consumido y días
     * para la fecha límite.
     */
    protected static function statDelCai(?int $sedeId): ?Stat
    {
        if ($sedeId === null || ! Gate::allows('viewAny', RangoCai::class)) {
            return null;
        }

        $rango = RangoCai::query()
            ->where('sede_id', $sedeId)
            ->where('tipo', TipoDocumentoDeVenta::Factura->value)
            ->where('activo', true)
            ->orderBy('id')
            ->first();

        $titulo = 'Facturas que quedan';

        if (! $rango instanceof RangoCai) {
            return Stat::make($titulo, 'Sin CAI')
                ->description('No se puede facturar: falta cargar el rango del SAR')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(RangoCaiResource::getUrl());
        }

        $hoy = now()->startOfDay();
        $dias = (int) $hoy->diffInDays($rango->fecha_limite_emision->copy()->startOfDay(), false);
        $vence = 'El CAI vence el '.$rango->fecha_limite_emision->format('d/m/Y');

        if ($rango->seAgoto() || $rango->vencioAl($hoy)) {
            return Stat::make($titulo, $rango->seAgoto() ? 'Ninguna' : 'CAI vencido')
                ->description($rango->seAgoto() ? 'Se usó todo el rango' : $vence)
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(RangoCaiResource::getUrl());
        }

        $porcentaje = (float) config('sihla.facturacion.alerta_cai_porcentaje_rango', 0.80);
        $diasDeAviso = (int) config('sihla.facturacion.alerta_cai_dias_restantes', 30);

        $avisa = $rango->fraccionConsumida() >= $porcentaje || $dias <= $diasDeAviso;

        return Stat::make($titulo, number_format($rango->disponibles()))
            ->description($dias <= $diasDeAviso ? "{$vence} — en {$dias} días" : $vence)
            ->descriptionIcon($avisa ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($avisa ? 'warning' : 'success');
    }
}
