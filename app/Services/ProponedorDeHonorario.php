<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\ValueObjects\HonorarioPropuesto;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioResuelto;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Tarifario;
use Carbon\CarbonInterface;

/**
 * Con qué precio se ofrece un honorario al cargarlo, y si se puede cambiar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA (Mauricio, 6-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Lo que cobran al Militar y a PALIG ya está fijado, así que en esos no
 * se puede modificar; en particular sí se puede cambiar y poner el monto
 * a gusto.»
 *
 * SEGURO (el convenio paga un tercero):
 *   1. El precio del médico hecho para ESE seguro → propuesto.
 *   2. La fila pactada del seguro, o su porcentaje sobre una lista real
 *      → FIJO sin médico; PROPUESTO con médico. «Deja que se pueda editar
 *      el monto cuando se agregue qué médico, ya que con unos son
 *      especiales» (Mauricio, 6-nov-2026). El turno de enfermería del
 *      Militar sigue sin tocarse; el honorario de un doctor viene con lo
 *      pactado para su especialidad y se cambia si ese doctor es especial.
 *   3. Nada pactado → libre: se escribe a mano (decisión de Mauricio: no
 *      se traba el cobro). Propone la lista si es real.
 *
 *   ⚠️ El precio GENERAL del médico no entra en un seguro: es lo que le
 *   cobra al particular, y en la cuenta del seguro manda lo pactado.
 *
 * PARTICULAR (contado y reembolso — el que paga es el paciente):
 *   1. Lo que cobra ese médico (su lista para este convenio, o la general)
 *      → propuesto.
 *   2. La lista del hospital, si es un precio real → propuesto.
 *   3. Nada → vacío y obligatorio.
 *
 * 🔴 El L 10 de relleno («PENDIENTE PRECIO DE LISTA») no se propone
 * NUNCA. Los honorarios ya no aparecen en la base de la lista, así que
 * nadie lo va a corregir ahí — y propuesto en el campo se cobraría si
 * quien carga no lo cambia.
 */
final class ProponedorDeHonorario
{
    public function __construct(
        private readonly ResolutorDeHonorario $honorarios,
        private readonly ResolutorDePrecio $precios,
    ) {}

    public function para(Item $item, Cuenta $cuenta, ?Medico $medico, CarbonInterface $momento): HonorarioPropuesto
    {
        $pagador = $cuenta->convenio;

        return $pagador->tipo->pagaUnTercero()
            ? $this->delSeguro($item, $cuenta, $pagador, $medico, $momento)
            : $this->delParticular($item, $cuenta, $pagador, $medico, $momento);
    }

    private function delSeguro(
        Item $item,
        Cuenta $cuenta,
        Convenio $seguro,
        ?Medico $medico,
        CarbonInterface $momento,
    ): HonorarioPropuesto {
        if ($medico instanceof Medico) {
            $suyo = $this->honorarios->soloDelPagador(
                (int) $medico->getKey(),
                (int) $item->getKey(),
                (int) $seguro->getKey(),
                $momento,
            );

            if ($suyo instanceof Monto) {
                return HonorarioPropuesto::propuesto(
                    $suyo,
                    delMedico: true,
                    explicacion: "Lo que {$medico->nombre} tiene pactado con {$seguro->nombre}. Cambialo si este cobro es distinto.",
                );
            }
        }

        $resuelto = $this->resolver($item, $cuenta, $seguro, $momento);

        if ($resuelto instanceof PrecioResuelto && $this->esPactado($resuelto)) {
            return $medico instanceof Medico
                ? HonorarioPropuesto::propuesto(
                    $resuelto->precio,
                    delMedico: false,
                    explicacion: "Pactado con {$seguro->nombre} para todos los médicos de «{$item->nombre}». "
                        .'Cambialo solo si este médico cobra distinto.',
                )
                : HonorarioPropuesto::pactado(
                    $resuelto->precio,
                    delMedico: false,
                    explicacion: "Precio pactado con {$seguro->nombre}: no se cambia.",
                );
        }

        $lista = $this->listaReal($resuelto);

        return $lista instanceof Monto
            ? HonorarioPropuesto::propuesto(
                $lista,
                delMedico: false,
                explicacion: "{$seguro->nombre} no tiene este honorario pactado. Va el de lista: cambialo si corresponde.",
            )
            : HonorarioPropuesto::sinPrecio(
                "{$seguro->nombre} no tiene este honorario pactado: escribí cuánto se cobra.",
            );
    }

    private function delParticular(
        Item $item,
        Cuenta $cuenta,
        Convenio $pagador,
        ?Medico $medico,
        CarbonInterface $momento,
    ): HonorarioPropuesto {
        if ($medico instanceof Medico) {
            $suyo = $this->honorarios->para($medico, $item, $pagador, $momento);

            if ($suyo instanceof Monto) {
                return HonorarioPropuesto::propuesto(
                    $suyo,
                    delMedico: true,
                    explicacion: "Es lo que cobra {$medico->nombre}. Cambialo solo para este cobro.",
                );
            }
        }

        $lista = $this->listaReal($this->resolver($item, $cuenta, $pagador, $momento));

        return $lista instanceof Monto
            ? HonorarioPropuesto::propuesto(
                $lista,
                delMedico: false,
                explicacion: 'Propuesto del tarifario. Cambialo si este médico cobra otra cosa.',
            )
            : HonorarioPropuesto::sinPrecio('Escribí cuánto cobra el médico por este honorario.');
    }

    private function resolver(Item $item, Cuenta $cuenta, Convenio $pagador, CarbonInterface $momento): ?PrecioResuelto
    {
        try {
            return $this->precios->para(
                item: $item,
                convenio: $pagador,
                fechaServicio: $momento,
                sede: $cuenta->sede,
            );
        } catch (PrecioNoDefinidoException) {
            return null;
        }
    }

    /**
     * ¿Salió de algo que se pactó con el seguro?
     *
     * La fila propia del seguro, o el porcentaje pactado aplicado a una
     * lista REAL: el porcentaje sobre los L 10 de relleno no es un precio
     * que nadie haya acordado.
     */
    private function esPactado(PrecioResuelto $resuelto): bool
    {
        return match ($resuelto->origen) {
            OrigenDelPrecio::PrecioNegociado   => true,
            OrigenDelPrecio::PorcentajePactado => ! $this->esRelleno($resuelto->fila),
            default                            => false,
        };
    }

    /**
     * El precio de lista, solo si alguien lo fijó de verdad.
     */
    private function listaReal(?PrecioResuelto $resuelto): ?Monto
    {
        if (! $resuelto instanceof PrecioResuelto
            || $resuelto->origen !== OrigenDelPrecio::PrecioDeLista
            || $this->esRelleno($resuelto->fila)) {
            return null;
        }

        return $resuelto->precio;
    }

    private function esRelleno(?Tarifario $fila): bool
    {
        return $fila instanceof Tarifario && PreciosDeLaFicha::esPendiente($fila);
    }
}
