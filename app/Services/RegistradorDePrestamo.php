<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\Prestamo;
use App\Models\Proveedor;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pedir prestado, y después saldar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PRÉSTAMO Y SU ENTRADA AL KARDEX SON UNA SOLA COSA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Van en la misma transacción, y no es prolijidad: si se guardara el
 * documento sin mover la existencia, el cobro siguiente lo rechazaría el
 * sistema por falta de saldo con la caja de tabletas ahí en el estante; y
 * si se moviera la existencia sin guardar el documento, quedaría una
 * entrada de inventario sin dueño — que es exactamente el agujero que
 * este módulo vino a tapar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRÉSTAMO NO MUEVE EL COSTO PROMEDIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se registra el movimiento SIN costo, igual que un ajuste positivo. No
 * es un olvido: lo prestado todavía no se compró, y meterle un costo
 * inventado —cero, o el monto pactado— movería el promedio móvil de todo
 * el producto y con él el precio de lista de la Ruta A.
 *
 * Cero sería lo peor: veinte tabletas «gratis» bajan el promedio, suben
 * el margen aparente, y el hospital termina vendiendo por debajo del
 * costo real sin que nada avise.
 *
 * ⚠️ Pendiente conocido: cuando el préstamo se salda PAGANDO, esa plata
 * es costo de verdad y hoy no entra al promedio. Se cierra cuando el
 * módulo de compras aprenda a recibir una factura contra un préstamo.
 */
final class RegistradorDePrestamo
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
    ) {}

    /**
     * @throws PrestamoException
     */
    public function registrar(
        Item $item,
        Almacen $almacen,
        Decimal $cantidad,
        QuienPresta $quienPresta,
        string $nombreDeQuienPresta,
        FormaDeSaldo $forma,
        ?Lote $lote = null,
        ?ItemPresentacion $presentacion = null,
        ?Decimal $montoAcordado = null,
        ?Proveedor $proveedor = null,
        ?string $telefono = null,
        ?Cuenta $cuenta = null,
        ?string $motivo = null,
        ?CarbonInterface $ocurridoEn = null,
    ): Prestamo {
        $nombre = trim($nombreDeQuienPresta);

        if (! $cantidad->mayorQue('0')) {
            throw PrestamoException::sinCantidad();
        }

        if (mb_strlen($nombre) < 3) {
            throw PrestamoException::faltaQuienPresto();
        }

        if ($forma === FormaDeSaldo::Pagar && ! $montoAcordado instanceof Decimal) {
            throw PrestamoException::faltaElMonto();
        }

        if ($forma === FormaDeSaldo::DevolverProducto && $montoAcordado instanceof Decimal) {
            throw PrestamoException::elMontoSobra();
        }

        $cuando = $ocurridoEn ?? now();

        return DB::transaction(function () use (
            $item,
            $almacen,
            $cantidad,
            $quienPresta,
            $nombre,
            $forma,
            $lote,
            $presentacion,
            $montoAcordado,
            $proveedor,
            $telefono,
            $cuenta,
            $motivo,
            $cuando,
        ): Prestamo {
            $prestamo = Prestamo::query()->create([
                'sede_id'              => $almacen->sede_id,
                'item_id'              => $item->id,
                'item_presentacion_id' => $presentacion?->id,
                'almacen_id'           => $almacen->id,
                'lote_id'              => $lote?->id,
                'cantidad'             => $cantidad->redondeado(4),
                'cantidad_saldada'     => '0.0000',
                'presta_tipo'          => $quienPresta,
                'proveedor_id'         => $proveedor?->id,
                'presta_nombre'        => mb_substr($nombre, 0, 160),
                'presta_telefono'      => $telefono,
                'forma_de_saldo'       => $forma,
                'monto_acordado'       => $montoAcordado?->redondeado(4),
                'estado'               => EstadoPrestamo::Pendiente,
                'cuenta_id'            => $cuenta?->id,
                'motivo'               => $motivo,
                'ocurrido_en'          => $cuando,
                'registrado_en'        => now(),

                /*
                 * §7.5-4: la fecha de negocio la deriva PHP en la zona del
                 * hospital, nunca PostgreSQL. Es la columna por la que
                 * filtran los reportes.
                 */
                'fecha_operacion' => $cuando->copy()
                    ->setTimezone((string) config('app.timezone', 'America/Tegucigalpa'))
                    ->toDateString(),
            ]);

            $this->movimientos->registrar(
                item: $item,
                lote: $lote,
                almacen: $almacen,
                tipo: TipoMovimiento::EntradaPorPrestamo,
                cantidad: $cantidad,
                motivo: "Préstamo de {$nombre}",
                referencia: "Préstamo #{$prestamo->id}",
                ocurridoEn: $cuando,
            );

            return $prestamo;
        });
    }

    /**
     * Se le devolvió producto a quien prestó.
     *
     * Acepta devoluciones PARCIALES porque así llegan: entraron 100 de las
     * 200 que se debían y se devolvieron 60. Obligar a devolver todo de
     * una vez haría que nadie registre nada hasta el final, y el final no
     * llega.
     *
     * @throws PrestamoException
     */
    public function devolver(
        Prestamo $prestamo,
        Decimal $cantidad,
        ?string $referencia = null,
        ?CarbonInterface $ocurridoEn = null,
    ): Prestamo {
        $this->exigirAbierto($prestamo);

        if ($prestamo->forma_de_saldo !== FormaDeSaldo::DevolverProducto) {
            throw PrestamoException::noSeDevuelveEnEspecie();
        }

        if (! $cantidad->mayorQue('0')) {
            throw PrestamoException::sinCantidad();
        }

        $pendiente = $prestamo->saldoPendiente();

        if ($cantidad->mayorQue($pendiente)) {
            throw PrestamoException::seDevuelveDeMas(
                $cantidad->redondeado(4),
                $pendiente->redondeado(4),
            );
        }

        $cuando = $ocurridoEn ?? now();

        return DB::transaction(function () use ($prestamo, $cantidad, $referencia, $cuando): Prestamo {
            /*
             * La salida va ANTES de tocar el documento: si no hay
             * existencia para devolver, `RegistradorDeMovimiento` levanta
             * la excepción y la transacción se cae sin dejar un préstamo
             * marcado como saldado sobre una devolución que no ocurrió.
             */
            $this->movimientos->registrar(
                item: $prestamo->item,
                lote: $prestamo->lote,
                almacen: $prestamo->almacen,
                tipo: TipoMovimiento::SalidaPorDevolucionDePrestamo,
                cantidad: $cantidad,
                motivo: "Devolución a {$prestamo->presta_nombre}",
                referencia: "Préstamo #{$prestamo->id}",
                ocurridoEn: $cuando,
            );

            $saldada = Decimal::de($prestamo->cantidad_saldada)->sumar($cantidad);

            return $this->cerrarSiCorresponde($prestamo, $saldada, $referencia, $cuando);
        });
    }

    /**
     * Se le pagó a quien prestó.
     *
     * No mueve inventario: lo prestado entró y se queda. Y no admite
     * pagos parciales a propósito — el monto se pactó completo al
     * registrar, y llevar mitades de plata acá sería inventar un módulo
     * de cuentas por pagar adentro del de inventario.
     *
     * @throws PrestamoException
     */
    public function marcarPagado(
        Prestamo $prestamo,
        ?string $referencia = null,
        ?CarbonInterface $ocurridoEn = null,
    ): Prestamo {
        $this->exigirAbierto($prestamo);

        if ($prestamo->forma_de_saldo !== FormaDeSaldo::Pagar) {
            throw PrestamoException::noSePagaEnEfectivo();
        }

        return $this->cerrarSiCorresponde(
            $prestamo,
            Decimal::de($prestamo->cantidad),
            $referencia,
            $ocurridoEn ?? now(),
        );
    }

    /**
     * @throws PrestamoException
     */
    private function exigirAbierto(Prestamo $prestamo): void
    {
        if (! $prestamo->estado->sigueAbierto()) {
            throw PrestamoException::yaEstaCerrado($prestamo->estado->etiqueta());
        }
    }

    /**
     * Deja el documento en el estado que le corresponde al saldo nuevo.
     *
     * ⚠️ `saldado_en` se pone SOLO cuando se cierra, y el CHECK
     * `prestamos_cierre_fechado` lo exige: un préstamo parcial con fecha
     * de cierre se lee como saldado en cualquier reporte que agrupe por
     * esa columna.
     */
    private function cerrarSiCorresponde(
        Prestamo $prestamo,
        Decimal $saldada,
        ?string $referencia,
        CarbonInterface $cuando,
    ): Prestamo {
        $cerrado = ! $saldada->menorQue($prestamo->cantidad);

        $prestamo->forceFill([
            'cantidad_saldada'     => $saldada->redondeado(4),
            'estado'               => $cerrado ? EstadoPrestamo::Saldado : EstadoPrestamo::Parcial,
            'saldado_en'           => $cerrado ? $cuando : null,
            'saldado_por'          => $cerrado ? UsuarioAutenticado::id() : null,
            'referencia_del_saldo' => $referencia,
        ])->save();

        return $prestamo;
    }
}
