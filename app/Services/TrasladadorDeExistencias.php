<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\TrasladoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Support\AlmacenesDelUsuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mover mercadería de un estante a otro sin que deje de ser la misma.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PROBLEMA RESUELVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Llegan 10 ampollas de fentanilo a BODEGA. Se bajan 1 al CARRITO ROJO 1
 * y 1 al CARRITO ROJO 2. Físicamente el hospital sigue teniendo 10; lo
 * que cambió es DÓNDE están. Sin traslado, la única forma de reflejarlo
 * sería una baja en bodega y una entrada en el carrito, y eso miente dos
 * veces: dice que se perdieron 2 y que aparecieron 2 de la nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS MOVIMIENTOS, UNA TRANSACCIÓN, Y EL SEGUNDO NO PUEDE FALTAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una salida sin su entrada es mercadería evaporada; una entrada sin su
 * salida es mercadería duplicada. Las dos van juntas o no va ninguna.
 *
 * La salida va PRIMERO a propósito: si no alcanza, el `UPDATE`
 * condicional de `RegistradorDeMovimiento` no afecta ninguna fila y todo
 * termina antes de haber tocado el destino.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL COSTO VIAJA CON LA MERCADERÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El promedio ponderado es POR almacén. Si la ampolla entrara al carrito
 * sin costo, el carrito la valuaría en cero y el inventario del hospital
 * perdería su valor cada vez que algo se mueve de estante — un traslado
 * no es una donación.
 *
 * Por eso el destino ABSORBE al costo vigente en el ORIGEN: es lo que
 * esa ampolla costó, y sigue costando lo mismo después de bajar dos
 * pisos. En el origen el promedio no cambia —una salida no lo mueve—,
 * pero `cantidad_base` sí tiene que seguir a la existencia real o la
 * próxima compra pondera contra un número que ya no está en el estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE MUEVE UN LOTE, NO «UN MEDICAMENTO»
 * ─────────────────────────────────────────────────────────────────────
 *
 * La existencia vive a nivel (ítem, lote, almacén) y el traslado también.
 * Pedirle al servicio que reparta una cantidad entre lotes lo obligaría a
 * decidir cuál baja, y esa decisión —FEFO— ya la toma
 * `ConsultorDeExistencias`. Acá se mueve la fila que se eligió, con su
 * vencimiento a la vista de quien la eligió.
 */
final class TrasladadorDeExistencias
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
    ) {}

    /**
     * @param Decimal $cantidad siempre POSITIVA: el sentido lo dan origen y destino
     *
     * @return array{salida: MovimientoKardex, entrada: MovimientoKardex}
     *
     * @throws TrasladoException
     */
    public function trasladar(
        Item $item,
        ?Lote $lote,
        Almacen $origen,
        Almacen $destino,
        Decimal $cantidad,
        ?string $motivo = null,
        ?CarbonInterface $ocurridoEn = null,
    ): array {
        $this->verificar($origen, $destino, $cantidad);

        $cuando = $ocurridoEn ?? now();
        $referencia = $this->referencia($origen, $destino, $cuando);

        /** @var array{salida: MovimientoKardex, entrada: MovimientoKardex} $asentado */
        $asentado = DB::transaction(function () use (
            $item,
            $lote,
            $origen,
            $destino,
            $cantidad,
            $motivo,
            $cuando,
            $referencia,
        ): array {
            /*
             * El costo se lee bloqueando la fila del origen ANTES de
             * sacar nada: si otro movimiento del mismo ítem estuviera
             * corriendo, el que llega segundo espera y lee el promedio ya
             * actualizado, no el de antes.
             */
            $costo = $this->costos->vigenteBloqueado($item, $origen);

            $salida = $this->movimientos->registrar(
                item: $item,
                lote: $lote,
                almacen: $origen,
                tipo: TipoMovimiento::SalidaPorTraslado,
                cantidad: $cantidad,
                motivo: $this->motivoDeSalida($destino, $motivo),
                referencia: $referencia,
                ocurridoEn: $cuando,
                costoUnitario: $costo,
            );

            /*
             * El promedio del origen no se mueve con una salida, pero la
             * cantidad contra la que se pondera la próxima entrada sí.
             */
            $this->costos->sincronizarCantidadBase($item, $origen);

            $promedioDestino = $this->costos->absorber($item, $destino, $cantidad, $costo);

            $entrada = $this->movimientos->registrar(
                item: $item,
                lote: $lote,
                almacen: $destino,
                tipo: TipoMovimiento::EntradaPorTraslado,
                cantidad: $cantidad,
                motivo: $this->motivoDeEntrada($origen, $motivo),
                referencia: $referencia,
                ocurridoEn: $cuando,
                costoUnitario: $costo,
                costoPromedioDespues: $promedioDestino,
            );

            return ['salida' => $salida, 'entrada' => $entrada];
        });

        return $asentado;
    }

    /**
     * Lo que hay que saber antes de tocar el kardex.
     *
     * @throws TrasladoException
     */
    private function verificar(Almacen $origen, Almacen $destino, Decimal $cantidad): void
    {
        if ($cantidad->esCero() || $cantidad->esNegativo()) {
            throw TrasladoException::laCantidadDebeSerPositiva();
        }

        if ($origen->id === $destino->id) {
            throw TrasladoException::elMismoAlmacen($origen->nombre);
        }

        /*
         * Entre sedes no, y no es una limitación técnica: el costo
         * promedio y el kardex son de cada sede, así que una ampolla que
         * cruza de sede sale del inventario de una y entra al de la otra
         * con su propio documento y su propia recepción. Un traslado
         * directo mezclaría dos contabilidades que la dirección lee por
         * separado.
         */
        if ($origen->sede_id !== $destino->sede_id) {
            throw TrasladoException::noSeTrasladaEntreSedes($origen->nombre, $destino->nombre);
        }

        if (! $destino->estaVigente()) {
            throw TrasladoException::elDestinoEstaCerrado(
                $destino->nombre,
                $destino->vigencia_hasta?->format('d/m/Y') ?? 'antes de hoy',
            );
        }

        /*
         * Los dos lados, no solo el origen. Quien no puede tocar el
         * carrito tampoco puede llenarlo: si no, el permiso se esquiva
         * empujando mercadería adentro desde el estante que sí se puede
         * tocar.
         */
        AlmacenesDelUsuario::exigirAcceso($origen);
        AlmacenesDelUsuario::exigirAcceso($destino);
    }

    /**
     * El mismo texto en las dos líneas ata el traslado sin una tabla.
     *
     * Las dos filas del kardex comparten `referencia`, así que buscar por
     * ella devuelve el par completo — de dónde salió y a dónde entró—
     * aunque estén a miles de movimientos de distancia.
     */
    private function referencia(Almacen $origen, Almacen $destino, CarbonInterface $cuando): string
    {
        return mb_substr(
            'TRAS-'.$cuando->format('ymd-His').'-'.$origen->codigo.'>'.$destino->codigo,
            0,
            255,
        );
    }

    /**
     * En la línea de salida, a dónde se fue. En la de entrada, de dónde
     * vino. Es lo que hace que el kardex se lea sin abrir otra pantalla:
     * la flecha está en el propio renglón.
     */
    private function motivoDeSalida(Almacen $destino, ?string $motivo): string
    {
        return $this->conNota('Traslado a '.$destino->nombre, $motivo);
    }

    private function motivoDeEntrada(Almacen $origen, ?string $motivo): string
    {
        return $this->conNota('Traslado desde '.$origen->nombre, $motivo);
    }

    /**
     * ⚠️ `motivo` es `varchar(255)`. Un texto largo escrito por quien
     * traslada no puede tumbar el movimiento: se corta acá, no en la base.
     */
    private function conNota(string $base, ?string $motivo): string
    {
        $nota = trim($motivo ?? '');

        return mb_substr($nota === '' ? $base : $base.' — '.$nota, 0, 255);
    }
}
