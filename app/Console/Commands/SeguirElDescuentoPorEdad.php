<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Descuento;
use App\Models\DescuentoLegal;
use App\Services\ReajustadorDePrecios;
use Illuminate\Console\Command;

/**
 * Aplica a los precios de farmacia el descuento por edad que empieza hoy.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ EXISTE (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un porcentaje que rige desde hoy se aplica en cuanto se guarda
 * (`AjustarPreciosAlDescuentoPorEdad`). Uno cargado con fecha de más
 * adelante —«la cuarta edad pasa al 45 % el 1 de diciembre»— no se puede
 * aplicar al guardarlo: ese día todavía no llegó. Esto corre todas las
 * noches a las 00:10 y, si algún descuento por edad empieza o termina
 * ese día, recalcula.
 *
 * Sin nada que empiece o termine hoy no hace nada; `--siempre` lo corre
 * igual, por ejemplo después de restaurar una base. Correrlo de más no
 * hace daño: lo que ya cuadra no se toca.
 */
class SeguirElDescuentoPorEdad extends Command
{
    protected $signature = 'precios:seguir-el-descuento {--siempre : Recalcular aunque hoy no cambie ningún descuento}';

    protected $description = 'Recalcula los precios de farmacia cuando hoy empieza o termina un descuento por edad.';

    public function handle(ReajustadorDePrecios $reajustador): int
    {
        $hoy = now()->startOfDay();
        $ayer = $hoy->copy()->subDay()->toDateString();

        $cambiaHoy = DescuentoLegal::query()
            ->where(fn ($sub) => $sub->whereDate('vigencia_desde', $hoy->toDateString())->orWhereDate('vigencia_hasta', $ayer))
            ->exists()
            || Descuento::query()
                ->automaticos()
                ->where(fn ($sub) => $sub->whereDate('vigencia_desde', $hoy->toDateString())->orWhereDate('vigencia_hasta', $ayer))
                ->exists();

        if (! $cambiaHoy && ! (bool) $this->option('siempre')) {
            $this->line('Hoy no empieza ni termina ningún descuento por edad.');

            return self::SUCCESS;
        }

        $cambiaron = $reajustador->alDescuentoPorEdad($hoy);

        $this->info("{$cambiaron} producto(s) cambiaron de precio.");

        return self::SUCCESS;
    }
}
