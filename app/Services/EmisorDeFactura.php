<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\RangoCai;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Facades\DB;

/**
 * Emite la factura de una cuenta: el acto que la cierra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UN CORRELATIVO FISCAL NO SE REPITE NI SE SALTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es la única regla que no admite excepción en todo el sistema. De ahí
 * salen tres decisiones que parecen exageradas y no lo son:
 *
 *  1. El número se toma con `lockForUpdate` sobre el rango, y **lo más
 *     tarde posible**: todo lo que se puede validar antes, se valida
 *     antes. Un lock de correlativo tomado temprano —o dentro de una
 *     transacción que genera PDF o llama a una impresora— serializa
 *     TODA la caja del hospital (§9.J6).
 *  2. Anular no libera el número. El SAR audita la secuencia: un hueco
 *     es una factura que alguien escondió.
 *  3. Nada se emite con el CAI vencido o el rango agotado. No es un
 *     aviso: el documento no valdría.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ MÁS PASA AL EMITIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los cargos pasan a `facturado` y la cuenta a `cerrada`. Facturar ES
 * cerrar: lo que llegue después es un cargo tardío, que el sistema
 * SIEMPRE acepta —jamás se rechaza un hecho clínico (§8.6.3)— y que se
 * resuelve con una factura complementaria.
 *
 * ⚠️ Regla del hospital, decidida por dirección: **primero se salda,
 * después se factura**. Con seguro de por medio esto se va a tener que
 * revisar —la aseguradora paga a sesenta días y su parte quedaría
 * bloqueando la emisión— y cuando pase, el cambio va acá, en un solo
 * lugar.
 */
final class EmisorDeFactura
{
    public function emitir(
        Cuenta $cuenta,
        ClienteDeFactura $cliente,
        ?int $quien = null,
        ?string $nota = null,
    ): Factura {
        return DB::transaction(function () use ($cuenta, $cliente, $quien, $nota): Factura {
            /** @var Cuenta $bloqueada */
            $bloqueada = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estado->estaViva()) {
                throw FacturaException::laCuentaNoEstaViva($bloqueada->numero, $bloqueada->estado->etiqueta());
            }

            $cargos = $this->cargosAFacturar($bloqueada);

            if ($cargos->isEmpty()) {
                throw FacturaException::noHayNadaQueFacturar($bloqueada->numero);
            }

            /*
             * El saldo se mide con los totales YA materializados de la
             * cuenta, que es lo que la pantalla viene mostrando. Si
             * alguien cargó algo entre que se abrió el modal y se apretó
             * Emitir, la cuenta bloqueada arriba ya trae el número nuevo.
             */
            $saldo = $bloqueada->saldoPendiente();

            if ($saldo->mayorQue('0')) {
                throw FacturaException::laCuentaTieneSaldo($bloqueada->numero, $saldo->redondeado(2));
            }

            $totales = $this->sumar($cargos);

            $this->exigirRtnSiCorresponde($cliente, $totales['total']);

            /*
             * 🔴 EL LOCK DEL CORRELATIVO, AL FINAL.
             *
             * Todo lo de arriba puede fallar y no cuesta nada; a partir
             * de acá se está serializando a todas las cajas de la sede.
             */
            $rango = $this->rangoBloqueado($bloqueada, TipoDocumentoDeVenta::Factura);

            $correlativo = $rango->siguiente;
            $numero = $rango->numeroDe($correlativo);

            $ahora = now();

            $factura = Factura::query()->create(array_merge([
                'sede_id'     => $bloqueada->sede_id,
                'tipo'        => TipoDocumentoDeVenta::Factura->value,
                'estado'      => EstadoFactura::Emitida->value,
                'numero'      => $numero,
                'correlativo' => $correlativo,

                'rango_cai_id'         => $rango->id,
                'cai'                  => $rango->cai,
                'fecha_limite_emision' => $rango->fecha_limite_emision->toDateString(),

                'cuenta_id'    => $bloqueada->id,
                'encuentro_id' => $bloqueada->encuentro_id,
                'persona_id'   => $bloqueada->encuentro->persona_id ?? null,
                'convenio_id'  => $bloqueada->convenio_id,

                'emitida_en' => $ahora,

                /*
                 * ⚠️ La fecha de operación la pone PHP, nunca Postgres:
                 * el servidor puede estar en UTC y la factura de las once
                 * de la noche caería en el día siguiente — con el cierre
                 * fiscal del mes corrido un día entero.
                 */
                'fecha_operacion' => $ahora->toDateString(),

                'bruto'               => $totales['bruto']->redondeado(2),
                'descuento_legal'     => $totales['descuento_legal']->redondeado(2),
                'descuento_comercial' => $totales['descuento_comercial']->redondeado(2),
                'exento'              => $totales['exento']->redondeado(2),
                'gravado'             => $totales['gravado']->redondeado(2),
                'isv'                 => $totales['isv']->redondeado(2),
                'total'               => $totales['total']->redondeado(2),

                'lineas' => $cargos->count(),
                'nota'   => $nota === null || trim($nota) === '' ? null : trim($nota),
            ], $cliente->paraGuardar()));

            $orden = 1;

            foreach ($cargos as $cargo) {
                $factura->detalle()->create([
                    'orden'    => $orden++,
                    'cargo_id' => $cargo->id,

                    /*
                     * El texto congelado del cargo, no el nombre actual
                     * del ítem: el papel dice lo que decía ese día.
                     */
                    'descripcion'     => $cargo->texto,
                    'cantidad'        => $cargo->cantidad,
                    'precio_unitario' => $cargo->precio_unitario,

                    'bruto'               => $cargo->bruto,
                    'descuento_legal'     => $cargo->descuento_legal,
                    'descuento_comercial' => $cargo->descuento_comercial,

                    'regimen_isv' => $cargo->regimen_isv->value,
                    'tasa_isv'    => $cargo->regimen_isv->tasaComoTexto(),

                    'exento'  => $cargo->base_exenta,
                    'gravado' => $cargo->base_gravada,
                    'isv'     => $cargo->isv,
                    'total'   => $cargo->total,
                ]);
            }

            $rango->update(['siguiente' => $correlativo + 1]);

            /*
             * Los cargos pasan a `facturado`. El trigger de `cargos` solo
             * permite `pendiente → facturado`, así que esto no puede
             * tocar por accidente uno anulado ni uno trasladado.
             */
            $bloqueada->cargos()
                ->where('estado', EstadoCargo::Pendiente->value)
                ->where('politica_cargo', PoliticaCargo::Cobrable->value)
                ->update(['estado' => EstadoCargo::Facturado->value]);

            $bloqueada->update([
                'estado'      => EstadoCuenta::Cerrada->value,
                'cerrada_en'  => $ahora,
                'cerrada_por' => $quien,
            ]);

            return $factura;
        });
    }

    /**
     * Anula una factura emitida.
     *
     * ⚠️ Anular NO es lo mismo que devolverle plata al cliente. Se usa
     * para el papel que se arruinó o que salió con el cliente
     * equivocado, y solo mientras no haya salido del hospital. Deshacer
     * una factura ya entregada es una NOTA DE CRÉDITO, que es otro
     * documento y todavía no existe.
     */
    public function anular(Factura $factura, string $motivo, ?int $quien = null): Factura
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw FacturaException::faltaElMotivo();
        }

        return DB::transaction(function () use ($factura, $motivo, $quien): Factura {
            /** @var Factura $bloqueada */
            $bloqueada = Factura::query()->whereKey($factura->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoFactura::Anulada) {
                throw FacturaException::laFacturaYaEstaAnulada($bloqueada->numero);
            }

            $bloqueada->update([
                'estado'           => EstadoFactura::Anulada->value,
                'anulada_en'       => now(),
                'anulada_por'      => $quien,
                'motivo_anulacion' => trim($motivo),
            ]);

            return $bloqueada->refresh();
        });
    }

    /**
     * Lo que se imprime: cobrable y todavía sin facturar.
     *
     * Lo `IncluidoEnTarifa` NO va —ya está adentro del renglón del
     * paquete (ADR-0009)— y lo `GastoDelServicio` tampoco: eso se imputa
     * al centro de costo, no al paciente.
     *
     * @return ColeccionDeModelos<int, Cargo>
     */
    private function cargosAFacturar(Cuenta $cuenta): ColeccionDeModelos
    {
        /** @var ColeccionDeModelos<int, Cargo> $cargos */
        $cargos = $cuenta->cargos()
            ->where('estado', EstadoCargo::Pendiente->value)
            ->where('politica_cargo', PoliticaCargo::Cobrable->value)
            ->orderBy('id')
            ->get();

        return $cargos;
    }

    /**
     * @param ColeccionDeModelos<int, Cargo> $cargos
     *
     * @return array<string, Decimal>
     */
    private function sumar(ColeccionDeModelos $cargos): array
    {
        $totales = [
            'bruto'               => Decimal::cero(),
            'descuento_legal'     => Decimal::cero(),
            'descuento_comercial' => Decimal::cero(),
            'exento'              => Decimal::cero(),
            'gravado'             => Decimal::cero(),
            'isv'                 => Decimal::cero(),
            'total'               => Decimal::cero(),
        ];

        foreach ($cargos as $cargo) {
            $totales['bruto'] = $totales['bruto']->sumar($cargo->bruto);
            $totales['descuento_legal'] = $totales['descuento_legal']->sumar($cargo->descuento_legal);
            $totales['descuento_comercial'] = $totales['descuento_comercial']->sumar($cargo->descuento_comercial);
            $totales['exento'] = $totales['exento']->sumar($cargo->base_exenta);
            $totales['gravado'] = $totales['gravado']->sumar($cargo->base_gravada);
            $totales['isv'] = $totales['isv']->sumar($cargo->isv);
            $totales['total'] = $totales['total']->sumar($cargo->total);
        }

        return $totales;
    }

    /**
     * 🔴 Arriba del umbral, «CONSUMIDOR FINAL» no es una opción.
     */
    private function exigirRtnSiCorresponde(ClienteDeFactura $cliente, Decimal $total): void
    {
        if ($cliente->tieneRtn()) {
            return;
        }

        $configurado = config('sihla.facturacion.umbral_rtn_obligatorio');
        $umbral = Decimal::de(is_string($configurado) ? $configurado : '10000.00');

        if ($total->mayorQue($umbral)) {
            throw FacturaException::faltaElRtn($umbral->redondeado(2));
        }
    }

    /**
     * El rango activo de la sede, bloqueado y verificado.
     */
    private function rangoBloqueado(Cuenta $cuenta, TipoDocumentoDeVenta $tipo): RangoCai
    {
        $rango = RangoCai::query()
            ->where('sede_id', $cuenta->sede_id)
            ->where('tipo', $tipo->value)
            ->where('activo', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $rango instanceof RangoCai) {
            throw FacturaException::noHayCaiVigente($tipo->etiqueta());
        }

        if ($rango->vencioAl(now())) {
            throw FacturaException::elCaiVencio($rango->cai, $rango->fecha_limite_emision->format('d/m/Y'));
        }

        if ($rango->seAgoto()) {
            throw FacturaException::elRangoSeAgoto($rango->cai, (string) $rango->hasta);
        }

        return $rango;
    }
}
