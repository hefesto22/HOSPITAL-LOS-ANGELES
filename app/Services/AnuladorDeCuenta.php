<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\MotivoDeAnulacionDeCuenta;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Support\TextoCanonico;
use App\Support\UsuarioAutenticado;
use Illuminate\Support\Facades\DB;

/**
 * Anular una cuenta que no debió abrirse (Mauricio, 21-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NADA SE BORRA: LA CUENTA QUEDA «ANULADA», CON QUIÉN, CUÁNDO Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Su número no se reusa y sigue en «Cuentas (consulta)» con el filtro de
 * anuladas. El encuentro que se abrió con ella se anula también, salvo
 * que tenga otra cuenta que no esté anulada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 SI YA TIENE ALGO CARGADO, SE DICE POR QUÉ NO SE FACTURA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si ya tiene algo agregado debe haber un motivo del por qué
 * se cierra esa cuenta y por qué no se factura». Con renglones vivos el
 * detalle es obligatorio, y cada renglón se quita con
 * `AnuladorDeCargo` —reversa al lado, lo que salió de farmacia vuelve al
 * mismo lote— llevando ese motivo. La cuenta queda en cero porque cada
 * cargo tiene su reversa, no porque se haya editado un total.
 *
 * `total_al_anular` guarda cuánto llevaba: después de anular el total es
 * cero, y dirección necesita ver cuánta plata se anuló en el día.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO HACE
 * ─────────────────────────────────────────────────────────────────────
 *
 *   - No devuelve plata. Con recibos vigentes se niega: el abono se anula
 *     primero, con su turno abierto y su comprobante (§8.6.2-5).
 *   - No anula lo facturado: la cuenta facturada está cerrada, y se
 *     reabre anulando la factura.
 *   - No decide QUIÉN anula: eso es `CuentaPolicy::anular()` y, para
 *     quien no tiene `Update:Cuenta`, que todo lo vivo sea de su área
 *     (`CatalogoDelRol::todoLoVivoEsDeSuArea()`), en la pantalla.
 */
final class AnuladorDeCuenta
{
    /** El largo de `cuentas.motivo_anulacion` y `cargos.motivo_anulacion`. */
    private const LARGO_DEL_MOTIVO = 200;

    public function __construct(
        private readonly AnuladorDeCargo $cargos,
    ) {}

    /**
     * @throws CuentaException
     * @throws CargoException
     */
    public function anular(Cuenta $cuenta, MotivoDeAnulacionDeCuenta $tipo, ?string $detalle = null): Cuenta
    {
        $detalle = TextoCanonico::parrafoEnMayusculas($detalle);

        if ($detalle !== null && mb_strlen($detalle) < 10) {
            $detalle = null;
        }

        if ($tipo->pideDetalle() && $detalle === null) {
            throw CuentaException::faltaElDetalleDeLaAnulacion();
        }

        $motivo = mb_substr(
            $detalle === null ? $tipo->etiqueta() : $tipo->etiqueta().': '.$detalle,
            0,
            self::LARGO_DEL_MOTIVO,
        );

        /** @var Cuenta $anulada */
        $anulada = DB::transaction(function () use ($cuenta, $tipo, $detalle, $motivo): Cuenta {
            /*
             * Se relee bajo candado: entre que la pantalla mostró la
             * cuenta y que llegó el clic, alguien pudo cargarle, abonarle
             * o facturarla. Todo lo que se decide abajo se decide con lo
             * que hay AHORA (§8.6.2-2).
             */
            $viva = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if (! $viva->estaViva()) {
                throw CuentaException::yaNoSeAnula($viva->numero, mb_strtolower($viva->estado->etiqueta()));
            }

            $abonado = $viva->abonado();

            if (! $abonado->esCero()) {
                throw CuentaException::tieneAbonos($viva->numero, Monto::de($abonado)->formateado());
            }

            $vivos = Cargo::query()
                ->where('cuenta_id', $viva->id)
                ->where('estado', EstadoCargo::Pendiente->value)
                ->orderByDesc('id')
                ->get();

            if ($vivos->isNotEmpty() && $detalle === null) {
                throw CuentaException::faltaDecirPorQueNoSeFactura($viva->numero, $vivos->count());
            }

            $llevaba = $viva->total;

            /*
             * Del último al primero, como se deshace una lista: si un
             * cargo depende de otro —el paquete y sus consumos— el que
             * llegó después se va antes.
             */
            $motivoDelRenglon = mb_substr('SE ANULÓ LA CUENTA '.$viva->numero.' — '.$motivo, 0, self::LARGO_DEL_MOTIVO);

            foreach ($vivos as $cargo) {
                $this->cargos->anular($cargo, $motivoDelRenglon);
            }

            $viva->refresh();

            $viva->forceFill([
                'estado'           => EstadoCuenta::Anulada->value,
                'anulada_en'       => now(),
                'anulada_por'      => UsuarioAutenticado::id(),
                'tipo_anulacion'   => $tipo->value,
                'motivo_anulacion' => $motivo,
                'total_al_anular'  => $llevaba,
            ])->save();

            $this->anularElEncuentro($viva, $motivo);

            return $viva;
        });

        return $anulada;
    }

    /**
     * El encuentro se abrió junto con la cuenta y sin ella no tiene
     * sentido: queda vivo y el paciente no puede volver a internarse
     * (`AbridorDeEncuentro::exigirQueNoEsteYaInternado`).
     *
     * Si tiene otra cuenta que no está anulada —un cambio de pagador—, el
     * encuentro es de esa otra y no se toca.
     */
    private function anularElEncuentro(Cuenta $cuenta, string $motivo): void
    {
        $encuentro = Encuentro::query()->whereKey($cuenta->encuentro_id)->lockForUpdate()->first();

        if (! $encuentro instanceof Encuentro) {
            return;
        }

        if (! in_array($encuentro->estado->value, EstadoEncuentro::valoresVivos(), true)) {
            return;
        }

        $tieneOtra = Cuenta::query()
            ->where('encuentro_id', $encuentro->id)
            ->whereKeyNot($cuenta->id)
            ->where('estado', '<>', EstadoCuenta::Anulada->value)
            ->exists();

        if ($tieneOtra) {
            return;
        }

        $encuentro->forceFill([
            'estado'           => EstadoEncuentro::Anulado->value,
            'anulado_en'       => now(),
            'anulado_por'      => UsuarioAutenticado::id(),
            'motivo_anulacion' => mb_substr('SE ANULÓ LA CUENTA '.$cuenta->numero.': '.$motivo, 0, self::LARGO_DEL_MOTIVO),
        ])->save();
    }
}
