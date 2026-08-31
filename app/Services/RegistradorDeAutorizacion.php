<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Anota lo que el seguro autorizó para una cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES UN `update()` SUELTO EN LA PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cambiar la autorización mueve plata: reparte de otra forma un total
 * que ya existe, y de ese reparto depende cuánto hay que cobrarle al
 * paciente antes de facturar. Tres cosas tienen que pasar juntas o
 * ninguna —se anota, se recalculan los totales, y queda quién lo hizo—,
 * y eso es una transacción, no tres líneas en un `action()`.
 *
 * 🔴 LOS CARGOS NO SE TOCAN. Cada uno guarda el reparto del día que
 * ocurrió y el trigger `cargos_append_only` lo impide cambiar. Lo que se
 * corrige acá no es ningún asiento: el total es el mismo, cambia de qué
 * lado cae. Ver `Cuenta::repartir()`.
 */
final class RegistradorDeAutorizacion
{
    /**
     * @param  Decimal|null  $fraccion  0.30 = el seguro cubre el 30 %
     * @param  Monto|null  $monto  Lo que el seguro aprobó, en lempiras
     */
    public function registrar(
        Cuenta $cuenta,
        ?Decimal $fraccion,
        ?Monto $monto,
        ?User $quien = null,
    ): Cuenta {
        if ($fraccion instanceof Decimal && $monto instanceof Monto) {
            throw CuentaException::laAutorizacionEsUnaSolaForma();
        }

        if ($fraccion instanceof Decimal && ($fraccion->esNegativo() || $fraccion->mayorQue('1'))) {
            throw CuentaException::laCoberturaVaEntreCeroYUno();
        }

        if ($monto instanceof Monto && $monto->cantidad()->esNegativo()) {
            throw CuentaException::elMontoAutorizadoNoEsNegativo();
        }

        return DB::transaction(function () use ($cuenta, $fraccion, $monto, $quien): Cuenta {
            /** @var Cuenta $bloqueada */
            $bloqueada = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            /*
             * ⚠️ Solo mientras la cuenta esté viva. Después de facturar,
             * el reparto ya salió impreso en un papel con número del SAR:
             * cambiarlo dejaría la factura diciendo una cosa y el sistema
             * otra. Ahí se corrige con nota de crédito, no con esto.
             */
            if (! $bloqueada->estaViva()) {
                throw CuentaException::noAdmiteCambios($bloqueada->numero, $bloqueada->estado->etiqueta());
            }

            $bloqueada->forceFill([
                'cobertura_autorizada' => $fraccion instanceof Decimal ? $fraccion->redondeado(4) : null,
                'monto_autorizado'     => $monto instanceof Monto ? $monto->valor() : null,
                'autorizacion_en'      => ($fraccion instanceof Decimal || $monto instanceof Monto) ? now() : null,
                'autorizacion_por'     => ($fraccion instanceof Decimal || $monto instanceof Monto)
                    ? $quien?->getKey()
                    : null,
            ]);

            /*
             * Y los totales, en la MISMA transacción. Sin esto la cuenta
             * queda diciendo el reparto viejo hasta el próximo cargo, y
             * quien va a cobrar en la ventanilla lee ese número.
             */
            $bloqueada->forceFill($bloqueada->recalcular());
            $bloqueada->save();

            return $bloqueada;
        });
    }

    /**
     * Deja la cuenta sin autorización propia: vuelve a mandar la
     * cobertura general del convenio.
     */
    public function quitar(Cuenta $cuenta): Cuenta
    {
        return $this->registrar($cuenta, null, null);
    }
}
