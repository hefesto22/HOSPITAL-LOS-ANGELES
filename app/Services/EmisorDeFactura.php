<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\RangoCai;
use App\Models\User;
use Carbon\CarbonInterface;
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
             * ─────────────────────────────────────────────────────────
             * 🔴 SE MIDE LO DEL PACIENTE, NO EL TOTAL DE LA CUENTA
             * ─────────────────────────────────────────────────────────
             *
             * Una cuenta de L 10,000 con un seguro que cubre el 70 % le
             * pide al paciente L 3,000; los otros L 7,000 llegan a
             * treinta días CONTRA LA FACTURA, que es justamente la que
             * se está por emitir. Exigir el total dejaba esa cuenta
             * trabada para siempre: había que cobrarle al paciente la
             * parte del seguro para poder facturarle al seguro.
             *
             * Para el paciente de contado `total_paciente` es el total,
             * así que la regla es la misma de antes y nada cambia.
             *
             * El saldo se mide con los totales YA materializados de la
             * cuenta, que es lo que la pantalla viene mostrando. Si
             * alguien cargó algo entre que se abrió el modal y se apretó
             * Emitir, la cuenta bloqueada arriba ya trae el número nuevo.
             */
            $saldo = $bloqueada->saldoPendienteDelPaciente();

            if ($saldo->mayorQue('0')) {
                throw FacturaException::alPacienteLeFalta($bloqueada->numero, $saldo->redondeado(2));
            }

            $totales = $this->sumar($cargos);

            $this->exigirDocumentoSiCorresponde($cliente, $totales['total']);

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
                'exonerado'           => $totales['exonerado']->redondeado(2),
                'exento'              => $totales['exento']->redondeado(2),
                'gravado'             => $totales['gravado']->redondeado(2),
                'gravado_15'          => $totales['gravado_15']->redondeado(2),
                'gravado_18'          => $totales['gravado_18']->redondeado(2),
                'isv'                 => $totales['isv']->redondeado(2),
                'isv_15'              => $totales['isv_15']->redondeado(2),
                'isv_18'              => $totales['isv_18']->redondeado(2),
                'total'               => $totales['total']->redondeado(2),

                /*
                 * Términos y vencimiento salen del convenio: contado
                 * vence el mismo día, y un convenio con crédito a
                 * treinta vence a treinta. Es desde ahí que se cuenta la
                 * mora, así que se congela en el papel.
                 */
                'terminos'   => $this->terminosDe($bloqueada->convenio),
                'vence_el'   => $this->venceEl($bloqueada->convenio, $ahora)->toDateString(),
                'facturador' => $this->nombreDelFacturador($quien),

                'lineas'      => $cargos->count(),
                'nota'        => $nota === null || trim($nota) === '' ? null : trim($nota),
                'comentarios' => $nota === null || trim($nota) === '' ? null : trim($nota),
            ], $cliente->paraGuardar()));

            $orden = 1;

            foreach ($cargos as $cargo) {
                $factura->detalle()->create([
                    'orden'    => $orden++,
                    'cargo_id' => $cargo->id,

                    /* La primera columna del papel. */
                    'codigo' => $cargo->item?->codigo,

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

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 `forceFill`, NO `update`
             * ─────────────────────────────────────────────────────────
             *
             * `cerrada_en` y `cerrada_por` NO están en el `$fillable` de
             * `Cuenta`, y eso es a propósito: los escribe el motor, no
             * un formulario. Con `update()` Laravel los descarta EN
             * SILENCIO —sin excepción y sin log— y la cuenta quedaba en
             * `cerrada` sin fecha de cierre.
             *
             * Lo atajó el CHECK `cuentas_cierre_completo` de la base, que
             * es exactamente para lo que está: defensa en profundidad
             * contra lo que el framework deja pasar callado.
             */
            $bloqueada->forceFill([
                'estado'      => EstadoCuenta::Cerrada->value,
                'cerrada_en'  => $ahora,
                'cerrada_por' => $quien,
            ])->save();

            return $factura;
        });
    }

    /**
     * Anula una factura emitida y devuelve la cuenta a como estaba.
     *
     * ⚠️ Anular NO es lo mismo que devolverle plata al cliente. Se usa
     * para el papel que se arruinó o que salió con el cliente
     * equivocado, y solo mientras no haya salido del hospital. Deshacer
     * una factura ya entregada es una NOTA DE CRÉDITO, que es otro
     * documento y todavía no existe.
     *
     * ⚠️ Y solo mientras el mes no se haya declarado: hasta el 9 del mes
     * siguiente. Ver `Factura::yaSeDeclaro()`.
     *
     * ─────────────────────────────────────────────────────────────────
     * LO QUE SE DESHACE Y LO QUE NO
     * ─────────────────────────────────────────────────────────────────
     *
     * SE DESHACE lo que hizo `emitir()` del lado de la cuenta: los
     * cargos vuelven a `pendiente` y la cuenta vuelve a `abierta`. Sin
     * esto, anular dejaba la cuenta muerta —cerrada y con todo
     * facturado— y volver a cobrarle a esa paciente obligaba a abrir una
     * cuenta nueva y recargarle a mano lo que ya tenía.
     *
     * NO SE DESHACE el número fiscal: el correlativo queda consumido y
     * la próxima factura sale con el siguiente. El SAR audita la
     * secuencia y un hueco es una factura que alguien escondió.
     *
     * NO SE DESHACEN los abonos. La plata entró de verdad y sigue
     * abonada a la cuenta, así que al volver a facturar la cuenta ya
     * está saldada y no hay que cobrar dos veces. Devolver plata es
     * anular el abono: otro hecho, con el turno de caja abierto.
     */
    public function anular(Factura $factura, string $motivo, ?int $quien = null): Factura
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw FacturaException::faltaElMotivo();
        }

        /*
         * ⚠️ Se verifica ACÁ y no se deja llegar al CHECK de la base.
         * Los dos rechazan, pero uno dice qué hacer y el otro sale como
         * un error de PostgreSQL en la cara de quien está cobrando.
         */
        if ($quien === null) {
            throw FacturaException::sinQuienAnula();
        }

        return DB::transaction(function () use ($factura, $motivo, $quien): Factura {
            /** @var Factura $bloqueada */
            $bloqueada = Factura::query()->whereKey($factura->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoFactura::Anulada) {
                throw FacturaException::laFacturaYaEstaAnulada($bloqueada->numero);
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL PERÍODO DECLARADO NO SE TOCA
             * ─────────────────────────────────────────────────────────
             *
             * El hospital declara el mes anterior el día 10. Hasta el 9
             * se puede anular una factura de ese mes; después ese número
             * ya viajó en la declaración, y moverlo deja lo emitido y lo
             * declarado diciendo cosas distintas. Eso se arregla con una
             * rectificativa ante el SAR, no con un botón en la caja.
             *
             * ⚠️ Se verifica ACÁ y no solo en la pantalla. El botón se
             * esconde pasado el plazo, pero una pestaña abierta desde
             * ayer todavía lo tiene dibujado.
             */
            if ($bloqueada->yaSeDeclaro()) {
                throw FacturaException::elPeriodoYaSeDeclaro(
                    $bloqueada->numero,
                    $bloqueada->periodoFiscal(),
                    $bloqueada->limiteParaAnular()->format('d/m/Y'),
                );
            }

            $bloqueada->update([
                'estado'           => EstadoFactura::Anulada->value,
                'anulada_en'       => now(),
                'anulada_por'      => $quien,
                'motivo_anulacion' => trim($motivo),
            ]);

            $this->devolverLaCuentaAComoEstaba($bloqueada);

            return $bloqueada->refresh();
        });
    }

    /**
     * Deja la cuenta como estaba antes de que se emitiera esta factura.
     *
     * Dos pasos, en este orden:
     *
     *  1. Los cargos que ESTA factura se llevó vuelven a `pendiente`.
     *     Se identifican por el detalle de la factura y no por «todos
     *     los facturados de la cuenta»: si algún día se factura de a
     *     partes, anular una no puede devolver los renglones de otra.
     *
     *  2. La cuenta vuelve a `abierta`, pero solo si no le queda otra
     *     factura viva. Anular la primera de dos no puede reabrir una
     *     cuenta que la segunda todavía está sosteniendo.
     *
     * ⚠️ `cuentas_una_abierta_por_encuentro` deja UNA sola cuenta
     * abierta por encuentro. Si mientras tanto se abrió otra —el caso
     * del cambio de pagador— la reapertura se saltea en vez de reventar
     * con un error de índice único: la factura igual queda anulada, que
     * es lo que se pidió, y la cuenta se resuelve a mano.
     */
    private function devolverLaCuentaAComoEstaba(Factura $factura): void
    {
        $cuenta = Cuenta::query()->whereKey($factura->cuenta_id)->lockForUpdate()->first();

        if (! $cuenta instanceof Cuenta) {
            return;
        }

        /*
         * ⚠️ `cargos` está particionada y su PK es `(id,
         * fecha_operacion)`, así que `factura_lineas.cargo_id` es un id
         * suelto sin FK. Por eso además del id se exige `cuenta_id`: es
         * lo que impide que un id repetido de otra partición se cuele.
         */
        $ids = $factura->detalle()
            ->pluck('cargo_id')
            ->filter()
            ->values()
            ->all();

        if ($ids !== []) {
            Cargo::query()
                ->whereIn('id', $ids)
                ->where('cuenta_id', $cuenta->getKey())
                ->where('estado', EstadoCargo::Facturado->value)
                ->update(['estado' => EstadoCargo::Pendiente->value]);
        }

        if ($cuenta->estado !== EstadoCuenta::Cerrada) {
            return;
        }

        $otraViva = Factura::query()
            ->where('cuenta_id', $cuenta->getKey())
            ->whereKeyNot($factura->getKey())
            ->where('estado', EstadoFactura::Emitida->value)
            ->exists();

        if ($otraViva) {
            return;
        }

        $yaHayOtraAbierta = Cuenta::query()
            ->where('encuentro_id', $cuenta->encuentro_id)
            ->whereKeyNot($cuenta->getKey())
            ->where('estado', EstadoCuenta::Abierta->value)
            ->exists();

        if ($yaHayOtraAbierta) {
            return;
        }

        /*
         * `forceFill` por lo mismo que en `emitir()`: `cerrada_en` y
         * `cerrada_por` no son fillable, y `update()` los descartaría en
         * silencio dejando la cuenta abierta con fecha de cierre. El
         * CHECK `cuentas_cierre_completo` mira la otra dirección, así
         * que ese silencio no lo atajaría nadie.
         */
        $cuenta->forceFill([
            'estado'      => EstadoCuenta::Abierta->value,
            'cerrada_en'  => null,
            'cerrada_por' => null,
        ])->save();
    }

    /**
     * «Contado» o el nombre del pagador, tal como sale impreso.
     */
    private function terminosDe(?Convenio $convenio): string
    {
        if (! $convenio instanceof Convenio) {
            return 'Contado';
        }

        $dias = $convenio->dias_credito;

        if (is_int($dias) && $dias > 0) {
            return $convenio->nombre.' · crédito '.$dias.' días';
        }

        return $convenio->tipo === TipoConvenio::Contado ? 'Contado' : $convenio->nombre;
    }

    /**
     * Cuándo vence. Sin crédito pactado, el mismo día.
     */
    private function venceEl(?Convenio $convenio, CarbonInterface $emision): CarbonInterface
    {
        $dias = $convenio?->dias_credito;

        return is_int($dias) && $dias > 0 ? $emision->copy()->addDays($dias) : $emision->copy();
    }

    /**
     * Quién emitió, congelado con nombre y apellido.
     *
     * `created_by` guarda el id, pero un usuario se puede renombrar o
     * desactivar y el papel impreso no cambia. En la factura va el
     * nombre que tenía ese día.
     */
    private function nombreDelFacturador(?int $quien): ?string
    {
        if ($quien === null) {
            return null;
        }

        $usuario = User::query()->find($quien);

        return $usuario instanceof User ? $usuario->name : null;
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
            ->with('item:id,codigo')
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
            'exonerado'           => Decimal::cero(),
            'exento'              => Decimal::cero(),
            'gravado'             => Decimal::cero(),
            'gravado_15'          => Decimal::cero(),
            'gravado_18'          => Decimal::cero(),
            'isv'                 => Decimal::cero(),
            'isv_15'              => Decimal::cero(),
            'isv_18'              => Decimal::cero(),
            'total'               => Decimal::cero(),
        ];

        foreach ($cargos as $cargo) {
            $totales['bruto'] = $totales['bruto']->sumar($cargo->bruto);
            $totales['descuento_legal'] = $totales['descuento_legal']->sumar($cargo->descuento_legal);
            $totales['descuento_comercial'] = $totales['descuento_comercial']->sumar($cargo->descuento_comercial);
            $totales['total'] = $totales['total']->sumar($cargo->total);

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL DESGLOSE SE ARMA POR RÉGIMEN, RENGLÓN POR RENGLÓN
             * ─────────────────────────────────────────────────────────
             *
             * El formulario del SAR tiene seis casillas separadas
             * —exonerado, exento, gravado 15, gravado 18, ISV 15, ISV
             * 18— y las pide separadas. Sumar todo en una sola columna
             * «gravado» hace imposible desglosarlo después: el impuesto
             * termina declarado en la casilla equivocada, que es un
             * hallazgo con multa.
             *
             * ⚠️ `exonerado` es OTRA cosa que `exento`: exento es el
             * producto (la salud lo es); exonerado es el CLIENTE, que
             * presentó su constancia. El cargo ya trae su régimen
             * congelado y ahí se decide.
             */
            if ($cargo->regimen_isv === RegimenIsv::Exonerado) {
                $totales['exonerado'] = $totales['exonerado']->sumar($cargo->base_exenta);
            } else {
                $totales['exento'] = $totales['exento']->sumar($cargo->base_exenta);
            }

            $totales['gravado'] = $totales['gravado']->sumar($cargo->base_gravada);
            $totales['isv'] = $totales['isv']->sumar($cargo->isv);

            if ($cargo->regimen_isv === RegimenIsv::Gravado18) {
                $totales['gravado_18'] = $totales['gravado_18']->sumar($cargo->base_gravada);
                $totales['isv_18'] = $totales['isv_18']->sumar($cargo->isv);
            } else {
                $totales['gravado_15'] = $totales['gravado_15']->sumar($cargo->base_gravada);
                $totales['isv_15'] = $totales['isv_15']->sumar($cargo->isv);
            }
        }

        return $totales;
    }

    /**
     * 🔴 Arriba del umbral, «CONSUMIDOR FINAL» no es una opción.
     *
     * ⚠️ Se exige que haya DOCUMENTO, no específicamente RTN: mucha
     * gente nunca sacó uno, y dejar sin factura a un paciente por eso es
     * peor que la duda de forma. Si el contador confirma que el SAR
     * exige RTN y solo RTN, la línea que cambia es esta: pedir
     * `$cliente->tipoDocumento === TipoIdentificador::Rtn`.
     */
    private function exigirDocumentoSiCorresponde(ClienteDeFactura $cliente, Decimal $total): void
    {
        if ($cliente->tieneDocumento()) {
            return;
        }

        $configurado = config('sihla.facturacion.umbral_rtn_obligatorio');
        $umbral = Decimal::de(is_string($configurado) ? $configurado : '10000.00');

        if ($total->mayorQue($umbral)) {
            throw FacturaException::faltaElDocumento($umbral->redondeado(2));
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
