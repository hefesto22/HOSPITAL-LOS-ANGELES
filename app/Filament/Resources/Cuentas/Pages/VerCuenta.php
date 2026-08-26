<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Pages;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Models\Cuenta;
use Filament\Resources\Pages\ViewRecord;

/**
 * ⚠️ Las acciones de esta página van SUELTAS en la cabecera, nunca
 * dentro de un `ActionGroup` (§9.A1): en páginas de cabecera el
 * ActionGroup no recibe `$record`, y las acciones quedan invisibles sin
 * que nada dé error.
 */
class VerCuenta extends ViewRecord
{
    protected static string $resource = CuentaResource::class;

    public function getSubheading(): ?string
    {
        $cuenta = $this->getRecord();

        if (! $cuenta instanceof Cuenta) {
            return null;
        }

        if ($cuenta->estaViva()) {
            return 'Cuenta viva: sigue recibiendo cargos. La factura es otra cosa y se emite una '
                .'sola vez, al final.';
        }

        return 'Cuenta cerrada. Sus cargos quedan como estaban: no se editan ni se borran.';
    }
}
