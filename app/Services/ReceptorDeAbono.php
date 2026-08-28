<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\CajaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Abono;
use App\Models\Cuenta;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Recibe plata a cuenta y la deja escrita.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PASA ACÁ ADENTRO
 * ─────────────────────────────────────────────────────────────────────
 *
 *  1. Tiene que haber un turno ABIERTO de quien recibe. Sin gaveta que
 *     alguien cuadre al final, un abono es una fila que nadie verifica.
 *  2. El recibo y sus medios se escriben en UNA transacción. A mitad de
 *     camino quedaría un recibo de L 5,000 sin decir con qué se pagó, y
 *     el arqueo de esa noche no cuadraría por una razón invisible.
 *  3. La suma de los medios TIENE que dar el total. No puede ser un
 *     CHECK —son dos tablas— así que se verifica acá.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA FECHA DE OPERACIÓN ES LA DEL TURNO, NO LA DE HOY
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un turno que abrió a las 8 de la noche y cierra a las 2 de la mañana
 * es UN arqueo. Si los recibos de después de medianoche cambiaran de
 * día, el corte del turno nunca cuadraría con el reporte del día — y la
 * cajera tendría razón en desconfiar del sistema.
 *
 * ⚠️ Deuda declarada: no hay clave de idempotencia como en `cargos`. Un
 * doble clic muy rápido podría dejar dos recibos; la pantalla deshabilita
 * el botón mientras guarda, y el duplicado se ve en la lista del turno y
 * se anula. Cuando exista la impresión automática del recibo, esto
 * necesita su clave.
 */
final class ReceptorDeAbono
{
    public function __construct(
        private readonly AsignadorDeCorrelativo $correlativos,
        private readonly AbridorDeTurnoDeCaja $turnos,
    ) {}

    /**
     * @param list<MedioDePago> $medios
     */
    public function recibir(
        Cuenta $cuenta,
        array $medios,
        User $quienRecibe,
        ?string $entregadoPor = null,
        ?string $nota = null,
    ): Abono {
        if ($medios === []) {
            throw CajaException::sinFormasDePago();
        }

        if (! $cuenta->estado->estaViva()) {
            throw CajaException::laCuentaNoRecibeAbonos($cuenta->numero, $cuenta->estado->etiqueta());
        }

        $turno = $this->turnos->abiertoDe($quienRecibe);

        if (! $turno instanceof TurnoDeCaja) {
            throw CajaException::sinTurnoAbierto();
        }

        $total = Decimal::cero();

        foreach ($medios as $medio) {
            $total = $total->sumar($medio->monto);
        }

        if ($total->esCero() || $total->esNegativo()) {
            throw CajaException::montoInvalido();
        }

        /*
         * La sede se resuelve acá y no adentro con `$cuenta->sede`: esa
         * relación es nullable para el analizador y el asignador de
         * correlativos exige una sede de verdad.
         */
        $sede = Sede::query()->findOrFail($cuenta->sede_id);

        return DB::transaction(function () use ($cuenta, $sede, $medios, $quienRecibe, $entregadoPor, $nota, $turno, $total): Abono {
            /** @var TurnoDeCaja $bloqueado */
            $bloqueado = TurnoDeCaja::query()->whereKey($turno->id)->lockForUpdate()->firstOrFail();

            /*
             * Se relee CON CANDADO: entre la lectura de arriba y este
             * punto, la cajera pudo cerrar su turno en otra pestaña. Un
             * abono que cae en un turno cerrado no aparece en ningún
             * arqueo — es exactamente la plata que después no está.
             */
            if (! $bloqueado->estaAbierto()) {
                throw CajaException::elTurnoYaEstaCerrado($bloqueado->numero);
            }

            $abono = Abono::query()->create([
                'sede_id' => $cuenta->sede_id,
                'numero'  => $this->correlativos->siguiente($sede, TipoCorrelativo::Abono),

                'cuenta_id' => $cuenta->id,
                'turno_id'  => $bloqueado->id,
                'estado'    => EstadoAbono::Aplicado->value,
                'total'     => $total->redondeado(2),

                'recibido_en'     => now(),
                'fecha_operacion' => $bloqueado->fecha_operacion->toDateString(),
                'recibido_por'    => $quienRecibe->id,

                'entregado_por' => $entregadoPor === null || trim($entregadoPor) === '' ? null : trim($entregadoPor),
                'nota'          => $nota === null || trim($nota) === '' ? null : trim($nota),
            ]);

            $suma = Decimal::cero();

            foreach ($medios as $medio) {
                $abono->medios()->create($medio->paraGuardar());
                $suma = $suma->sumar($medio->monto);
            }

            /*
             * Cinturón y tirantes: la suma ya se calculó arriba, pero
             * esto verifica lo que QUEDÓ ESCRITO. Si algún día alguien
             * agrega un medio por otro camino, el recibo no se guarda
             * diciendo un número que sus partes no sostienen.
             */
            if (! $suma->igualA($total)) {
                throw CajaException::losMediosNoCuadran($total->redondeado(2), $suma->redondeado(2));
            }

            return $abono;
        });
    }

    /**
     * Deshace un recibo mal hecho.
     *
     * 🔴 Solo con el turno abierto. Cerrado el turno, el efectivo ya se
     * contó y se entregó: eso es una devolución, y es otro hecho.
     */
    public function anular(Abono $abono, string $motivo, ?int $quien = null): Abono
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw CajaException::faltaElMotivo();
        }

        return DB::transaction(function () use ($abono, $motivo, $quien): Abono {
            /** @var Abono $bloqueado */
            $bloqueado = Abono::query()->whereKey($abono->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado === EstadoAbono::Anulado) {
                throw CajaException::elAbonoYaEstaAnulado($bloqueado->numero);
            }

            $turno = $bloqueado->turno;

            if (! $turno instanceof TurnoDeCaja || ! $turno->estaAbierto()) {
                throw CajaException::noSeAnulaConElTurnoCerrado($bloqueado->numero);
            }

            $bloqueado->update([
                'estado'           => EstadoAbono::Anulado->value,
                'anulado_en'       => now(),
                'anulado_por'      => $quien,
                'motivo_anulacion' => trim($motivo),
            ]);

            return $bloqueado->refresh();
        });
    }
}
