<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\RangosCai\RangoCaiResource;
use App\Models\RangoCai;
use App\Models\User;
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
     *
     * Lo ve quien ve los rangos (dirección, auditoría) y quien factura
     * (caja, 22-oct-2026). La cajera no tiene «Rangos de CAI» en el menú,
     * pero sí tiene que saber por qué no le deja facturar y cuándo
     * avisar. Se pregunta el PERMISO `Create:Factura` y no la policy:
     * la policy pide además el turno abierto, y el aviso sirve justo
     * antes de abrirlo. Sin acceso a los rangos el cuadro no lleva
     * enlace: a ese clic lo esperaba un 403.
     */
    protected static function statDelCai(?int $sedeId): ?Stat
    {
        $usuario = auth()->user();

        if ($sedeId === null || ! $usuario instanceof User) {
            return null;
        }

        $veLosRangos = Gate::allows('viewAny', RangoCai::class);

        if (! $veLosRangos && ! $usuario->can('Create:Factura')) {
            return null;
        }

        $enlace = $veLosRangos ? RangoCaiResource::getUrl() : null;

        $rango = RangoCai::query()
            ->where('sede_id', $sedeId)
            ->where('tipo', TipoDocumentoDeVenta::Factura->value)
            ->where('activo', true)
            ->orderBy('id')
            ->first();

        $titulo = 'Facturas que quedan';

        if (! $rango instanceof RangoCai) {
            return Stat::make($titulo, 'Sin CAI')
                ->description($veLosRangos
                    ? 'No se puede facturar: falta cargar el rango del SAR'
                    : 'No se puede facturar: avisá a dirección que cargue el rango del SAR')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url($enlace);
        }

        $hoy = now()->startOfDay();
        $dias = (int) $hoy->diffInDays($rango->fecha_limite_emision->copy()->startOfDay(), false);
        $vence = 'El CAI vence el '.$rango->fecha_limite_emision->format('d/m/Y');

        if ($rango->seAgoto() || $rango->vencioAl($hoy)) {
            return Stat::make($titulo, $rango->seAgoto() ? 'Ninguna' : 'CAI vencido')
                ->description($rango->seAgoto() ? 'Se usó todo el rango' : $vence)
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url($enlace);
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
