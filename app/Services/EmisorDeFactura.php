<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\FacturaException;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Models\Abono;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\RangoCai;
use App\Models\User;
use App\Support\TextoCanonico;
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
    public function __construct(
        private readonly DesglosadorDePaquete $desglosador,
        private readonly ReceptorDeAbono $caja,
    ) {}

    public function emitir(
        Cuenta $cuenta,
        ClienteDeFactura $cliente,
        ?int $quien = null,
        ?string $nota = null,
        ?bool $desglosar = null,
        ?string $poliza = null,
    ): Factura {
        return DB::transaction(function () use ($cuenta, $cliente, $quien, $nota, $desglosar, $poliza): Factura {
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

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 LOS RENGLONES DEL PAPEL SE ARMAN ANTES DEL CORRELATIVO
             * ─────────────────────────────────────────────────────────
             *
             * Desglosar un paquete puede fallar —uno que mezcle
             * regímenes de ISV— y acá eso no cuesta nada: todavía no se
             * serializó a las cajas de la sede ni se consumió un número
             * del SAR. Un centímetro más abajo, el mismo error dejaría
             * un hueco en la secuencia que el SAR audita.
             *
             * Sin nada dicho manda la preferencia del pagador.
             *
             * ⚠️ `->` y no `?->`: adentro de un `??` el nullsafe sobra
             * —el `??` ya tolera el nulo en la cadena— y PHPStan lo
             * rechaza con `nullsafe.neverNull`. El `?? false` es el que
             * cubre la cuenta sin convenio: si nadie declaró que quiere
             * el papel largo, sale el corto.
             */
            $desglosar ??= (bool) ($bloqueada->convenio->desglosa_paquetes ?? false);

            $renglones = $this->renglonesDelPapel($cargos, $desglosar);

            $cliente = $this->conElRtnDelPagador($bloqueada, $cliente);

            /*
             * El paciente se lee ACÁ, antes del lock del correlativo: son
             * lecturas, y no tienen por qué hacer esperar a las cajas.
             */
            $paciente = $this->elPacienteDe($bloqueada);

            $cliente = $this->conElCodigoDelPaciente($cliente, $paciente['expediente']);

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

                /*
                 * El paciente va en su propio renglón, aparte del
                 * cliente: con seguro el cliente es la aseguradora y sin
                 * seguro suele ser «CONSUMIDOR FINAL», pero el paciente
                 * está siempre, con su identidad si la tiene registrada
                 * (Mauricio, 11-oct-2026). Lo pone el motor y no la
                 * pantalla: quién fue atendido no se teclea en la caja.
                 *
                 * La póliza va con él porque es suya: el «Código de
                 * Cliente» del papel ahora es el expediente.
                 */
                'paciente_nombre'         => $paciente['nombre'],
                'paciente_documento'      => $paciente['documento'],
                'paciente_documento_tipo' => $paciente['tipo'],
                'paciente_poliza'         => $this->polizaDe($bloqueada, $poliza),

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

                /*
                 * Los renglones que COBRAN. El título de una cirugía
                 * desglosada no cuenta: quien lee «19 renglones» está
                 * contando cosas que se le hicieron al paciente.
                 */
                'lineas'               => $renglones['cobrables'],
                'paquetes_desglosados' => $renglones['hubo'],

                'nota'        => TextoCanonico::parrafoEnMayusculas($nota),
                'comentarios' => $this->comentariosDe($bloqueada, $totales['total'], $nota),
            ], $cliente->paraGuardar()));

            $orden = 1;

            foreach ($renglones['lineas'] as $renglon) {
                $factura->detalle()->create(['orden' => $orden++] + $renglon);
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
     * está saldada y no hay que cobrar dos veces.
     *
     * 🔴 SALVO LOS QUE SE DEVOLVIERON (Mauricio, 11-oct-2026). «Facturé
     * y la anulé, y dice que ya abonó»: emitir con «Cobrar ahora» deja
     * un recibo, y anular la factura lo dejaba vivo aunque la plata se
     * le hubiera devuelto. Los recibos de `$recibosDevueltos` se anulan
     * EN LA MISMA TRANSACCIÓN que la factura: si uno no se puede —el
     * turno ya cerró—, la factura tampoco se anula, y nadie queda con
     * la mitad hecha. Solo cuentan los de ESTA cuenta.
     *
     * @param list<int> $recibosDevueltos
     */
    public function anular(Factura $factura, string $motivo, ?int $quien = null, array $recibosDevueltos = []): Factura
    {
        $motivo = TextoCanonico::parrafoEnMayusculas($motivo) ?? '';

        if (mb_strlen($motivo) < 10) {
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

        return DB::transaction(function () use ($factura, $motivo, $quien, $recibosDevueltos): Factura {
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
                'motivo_anulacion' => $motivo,
            ]);

            $this->devolverLaCuentaAComoEstaba($bloqueada);

            $this->anularLosRecibosDevueltos($bloqueada, $recibosDevueltos, trim($motivo), $quien);

            return $bloqueada->refresh();
        });
    }

    /**
     * Los recibos de la cuenta cuya plata se le devolvió al anular.
     *
     * Por `ReceptorDeAbono::anular()`, la misma puerta que usa la caja:
     * exige el turno abierto, porque cerrado el turno ese efectivo ya se
     * contó y se entregó, y eso es otro hecho.
     *
     * @param list<int> $ids
     */
    private function anularLosRecibosDevueltos(Factura $factura, array $ids, string $motivo, ?int $quien): void
    {
        if ($ids === []) {
            return;
        }

        $recibos = Abono::query()
            ->where('cuenta_id', $factura->cuenta_id)
            ->where('estado', EstadoAbono::Aplicado->value)
            ->whereIn('id', $ids)
            ->with('turno')
            ->orderBy('id')
            ->get();

        foreach ($recibos as $recibo) {
            $this->caja->anular(
                $recibo,
                mb_substr('Se devolvió al anular la factura '.$factura->numero.': '.$motivo, 0, 200),
                $quien,
            );
        }
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
     * Lo que sale en el recuadro «Comentarios».
     *
     * Con seguro, primero el reparto (Mauricio, 14-oct-2026): «en
     * comentarios debería salir copago de X lempiras, reembolso de X
     * lempiras y el nombre del seguro».
     *
     *   COPAGO: L 30.02 · REEMBOLSO: L 200.00 · PAN-AMERICAN LIFE INSURANCE GROUP
     *
     * y debajo la nota que se escribió al emitir, si hay. Sin seguro,
     * solo la nota, como siempre.
     *
     * Sale de la cuenta bloqueada, con la autorización ya anotada: es el
     * mismo reparto que se acaba de cobrar. El reembolso se recorta al
     * total del papel y el copago es lo que queda, para que las dos cifras
     * sumen exacto lo que dice «Total a pagar».
     */
    private function comentariosDe(Cuenta $cuenta, Decimal $total, ?string $nota): ?string
    {
        $nota = TextoCanonico::parrafoEnMayusculas($nota);

        if (! $cuenta->convenio->tipo->pagaUnTercero()) {
            return $nota;
        }

        $total = Decimal::de($total->redondeado(2));
        $reembolso = Decimal::de($cuenta->total_aseguradora);

        if ($reembolso->mayorQue($total)) {
            $reembolso = $total;
        }

        $reparto = sprintf(
            'COPAGO: %s · REEMBOLSO: %s · %s',
            self::enLempiras($total->restar($reembolso)),
            self::enLempiras($reembolso),
            mb_strtoupper($cuenta->convenio->nombre),
        );

        return $nota === null ? $reparto : $reparto."\n".$nota;
    }

    /**
     * «L 1,250.00», como se lee en el resto del papel.
     */
    private static function enLempiras(Decimal $monto): string
    {
        return 'L '.number_format((float) $monto->redondeado(2), 2);
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
     * La cantidad tal como se vendió: envases si se cobró por envase, y
     * la de dispensación si no.
     *
     * @return numeric-string
     */
    private function cuantoSeVendio(Cargo $cargo): string
    {
        $comoSeCobro = $cargo->comoSeCobro();

        return $comoSeCobro === null
            ? $cargo->cantidad
            : $comoSeCobro['envases']->redondeado(4);
    }

    /**
     * El precio de lo que se vendió.
     *
     * Se DERIVA del bruto entre los envases y no se guarda por segunda
     * vez: dos copias del mismo número son dos números que alguna vez van
     * a diferir, y en una factura fiscal eso es una discrepancia que hay
     * que explicar.
     *
     * ⚠️ `bruto` y no `total`: el precio unitario del papel es el de
     * lista, ANTES del descuento. El descuento tiene su propia columna y
     * meterlo acá lo restaría dos veces.
     *
     * @return numeric-string
     */
    private function aCuantoSeVendio(Cargo $cargo): string
    {
        $comoSeCobro = $cargo->comoSeCobro();

        if ($comoSeCobro === null || $comoSeCobro['envases']->esCero()) {
            return $cargo->precio_unitario;
        }

        return Decimal::de($cargo->bruto)->entre($comoSeCobro['envases'])->redondeado(4);
    }

    /**
     * Los renglones que van al papel, ya decidido si el paquete se abre.
     *
     * ─────────────────────────────────────────────────────────────────
     * LO QUE ESTE MÉTODO **NO** HACE
     * ─────────────────────────────────────────────────────────────────
     *
     * No toca los totales. Los de la factura salen de `sumar($cargos)`,
     * que no sabe ni le importa si el papel salió corto o largo — y esa
     * es toda la garantía: **la misma cuenta da el mismo total con o sin
     * desglose**. Si algún día alguien hace que la cabecera sume las
     * líneas, esa garantía se pierde y con ella la razón de que esto sea
     * seguro.
     *
     * ⚠️ Un desglose imposible NO traba la factura: `desglosar()`
     * devuelve `null` —cirugía sin presupuesto, o sin nada entregado
     * todavía— y el cargo sale como el renglón de siempre. Con la
     * familia esperando el papel, la presentación no puede ser un
     * motivo para no emitir.
     *
     * @param ColeccionDeModelos<int, Cargo> $cargos
     *
     * @return array{lineas: list<array<string, mixed>>, cobrables: int, hubo: bool}
     */
    private function renglonesDelPapel(ColeccionDeModelos $cargos, bool $desglosar): array
    {
        $lineas = [];
        $cobrables = 0;
        $hubo = false;

        foreach ($cargos as $cargo) {
            $desglose = $desglosar ? $this->desglosador->desglosar($cargo) : null;

            if ($desglose === null) {
                $lineas[] = $this->renglonDelCargo($cargo);
                $cobrables++;

                continue;
            }

            $hubo = true;

            $lineas[] = $this->encabezadoDelPaquete($cargo);

            foreach ($desglose as $renglon) {
                /*
                 * Las tres líneas apuntan al MISMO cargo, que es el que
                 * pasa a `facturado` y el que anular devuelve a
                 * `pendiente`. Los consumos del paquete no se facturan:
                 * ya están adentro de este renglón.
                 */
                $lineas[] = $renglon + ['cargo_id' => $cargo->id, 'encabezado' => false];
                $cobrables++;
            }
        }

        return ['lineas' => $lineas, 'cobrables' => $cobrables, 'hubo' => $hubo];
    }

    /**
     * El título de la cirugía: la nombra y no cobra nada.
     *
     * Desglosada, la factura pierde la palabra «APENDICECTOMIA» y quedan
     * diecinueve renglones sueltos de los que ni el paciente ni el
     * ajustador saben de qué procedimiento son. Este renglón la devuelve,
     * con su número de presupuesto al lado para poder cruzarla con el
     * papel que la familia firmó.
     *
     * @return array<string, mixed>
     */
    private function encabezadoDelPaquete(Cargo $cargo): array
    {
        $numero = $cargo->presupuesto?->numero;

        return [
            'encabezado' => true,
            'cargo_id'   => $cargo->id,
            'codigo'     => $cargo->item?->codigo,

            'descripcion' => mb_substr(
                $numero === null ? $cargo->texto : $cargo->texto.' · '.$numero,
                0,
                200,
            ),

            /*
             * ⚠️ Uno y no cero: `factura_lineas_cantidad_no_cero` rechaza
             * el cero. El papel igual no lo imprime — la bandera
             * `encabezado` hace que la fila salga como título, sin sus
             * columnas numéricas.
             */
            'cantidad'        => '1.0000',
            'precio_unitario' => '0.0000',

            'bruto'               => '0.00',
            'descuento_legal'     => '0.00',
            'descuento_comercial' => '0.00',

            /*
             * El régimen del cargo y no uno fijo: con `exento` en un
             * paquete gravado, `factura_lineas_gravado_sin_exento`
             * rechazaría la fila. Con todo en cero, cualquier régimen
             * pasa los CHECK.
             */
            'regimen_isv' => $cargo->regimen_isv->value,
            'tasa_isv'    => $cargo->regimen_isv->tasaComoTexto(),

            'exento'  => '0.00',
            'gravado' => '0.00',
            'isv'     => '0.00',
            'total'   => '0.00',
        ];
    }

    /**
     * El renglón de siempre: un cargo, una línea.
     *
     * @return array<string, mixed>
     */
    private function renglonDelCargo(Cargo $cargo): array
    {
        return [
            'encabezado' => false,
            'cargo_id'   => $cargo->id,

            /* La primera columna del papel. */
            'codigo' => $cargo->item?->codigo,

            /*
             * El texto congelado del cargo, no el nombre actual del
             * ítem: el papel dice lo que decía ese día.
             */
            'descripcion' => $cargo->texto,

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL PAPEL DICE LO QUE SE VENDIÓ, NO LO QUE SALIÓ DEL
             * ESTANTE
             * ─────────────────────────────────────────────────────────
             *
             * Un frasco de 60 ml salía impreso como «60 × L 61.11»: los
             * números correctos leídos mal. Nadie entregó sesenta de
             * nada, y L 61.11 no es un precio de este hospital —es el
             * frasco dividido entre sus mililitros—. El paciente recibe
             * una factura donde no reconoce ni la cantidad ni el precio.
             *
             * El kardex sigue en 60 ML: eso vive en el cargo y no se
             * toca. Acá se imprime «1 × L 3,666.67», que es lo que se le
             * cobró.
             */
            'cantidad'        => $this->cuantoSeVendio($cargo),
            'precio_unitario' => $this->aCuantoSeVendio($cargo),

            'bruto'               => $cargo->bruto,
            'descuento_legal'     => $cargo->descuento_legal,
            'descuento_comercial' => $cargo->descuento_comercial,

            'regimen_isv' => $cargo->regimen_isv->value,
            'tasa_isv'    => $cargo->regimen_isv->tasaComoTexto(),

            'exento'  => $cargo->base_exenta,
            'gravado' => $cargo->base_gravada,
            'isv'     => $cargo->isv,
            'total'   => $cargo->total,
        ];
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
     * ─────────────────────────────────────────────────────────────────
     * 🔴 CON SEGURO, LA FACTURA SALE CON EL RTN DEL SEGURO. SIEMPRE.
     * ─────────────────────────────────────────────────────────────────
     *
     * Regla del hospital (10-oct-2026). La aseguradora —o el Militar, o
     * el IHSS— es quien paga y quien reembolsa, y su RTN es el que va en
     * el papel. Antes, sin RTN cargado en el convenio, la pantalla
     * proponía la identidad del paciente, y la factura salía a nombre
     * del seguro con el documento de otra persona.
     *
     * Se decide ACÁ y no en la pantalla: el número sale del convenio y
     * lo que haya llegado en el formulario no cuenta. Si el convenio no
     * lo tiene, no se emite — se carga una vez en Seguros y convenios y
     * no vuelve a faltar. El nombre, la dirección y la póliza sí quedan
     * como vinieron.
     */
    private function conElRtnDelPagador(Cuenta $cuenta, ClienteDeFactura $cliente): ClienteDeFactura
    {
        $convenio = $cuenta->convenio;

        /*
         * Pague quien pague: con el reembolso el paciente paga todo y la
         * factura sale igual a nombre de la aseguradora (11-oct-2026).
         */
        if (! $convenio->tipo->facturaANombreDelSeguro()) {
            return $cliente;
        }

        $rtn = trim((string) $convenio->rtn);

        if ($rtn === '') {
            throw FacturaException::faltaElRtnDelPagador($convenio->nombre);
        }

        return new ClienteDeFactura(
            nombre: $cliente->nombre,
            documento: $rtn,
            tipoDocumento: TipoIdentificador::Rtn,
            direccion: $cliente->direccion,
            telefono: $cliente->telefono,
            codigo: $cliente->codigo,
            ordenExenta: $cliente->ordenExenta,
            constanciaExonerado: $cliente->constanciaExonerado,
            registroSag: $cliente->registroSag,
        );
    }

    /**
     * El paciente como está HOY en el expediente, para congelarlo en el
     * papel: nombre, identidad y número de expediente.
     *
     * Se lee al emitir y no se vuelve a leer: si mañana se le corrige un
     * apellido o la identidad, la factura de hoy sigue diciendo lo que
     * dijo el día que se entregó. Por eso está en el trigger de
     * inmutabilidad, igual que el cliente.
     *
     * @return array{nombre: string|null, documento: string|null, tipo: string|null, expediente: string|null}
     */
    private function elPacienteDe(Cuenta $cuenta): array
    {
        $encuentro = $cuenta->encuentro;

        $persona = Persona::query()->find($encuentro->persona_id);

        /*
         * `withTrashed`: un expediente archivado sigue siendo el número
         * por el que se busca a esa persona en el hospital.
         */
        $expediente = Expediente::query()->withTrashed()->find($encuentro->expediente_id);

        $nombre = $persona instanceof Persona ? trim($persona->nombreCompleto()) : '';
        $identidad = $persona instanceof Persona ? $this->identidadDe($persona) : null;

        return [
            'nombre'     => $nombre === '' ? null : mb_substr($nombre, 0, 200),
            'documento'  => $identidad?->valor,
            'tipo'       => $identidad?->tipo->value,
            'expediente' => $expediente instanceof Expediente ? $expediente->numero : null,
        ];
    }

    /**
     * El documento que identifica a la PERSONA: la identidad y, si es
     * extranjera, el pasaporte o el carné de residencia. El RTN no va
     * acá —ese identifica al contribuyente y es del cliente— ni el
     * carné del IHSS, que identifica al afiliado.
     */
    private function identidadDe(Persona $persona): ?PersonaIdentificador
    {
        $preferidos = [
            TipoIdentificador::Dni,
            TipoIdentificador::Pasaporte,
            TipoIdentificador::CarnetResidencia,
        ];

        $tiene = PersonaIdentificador::query()
            ->where('persona_id', $persona->id)
            ->sinConflicto()
            ->whereIn('tipo', array_map(static fn (TipoIdentificador $tipo): string => $tipo->value, $preferidos))
            ->get();

        foreach ($preferidos as $tipo) {
            $identificador = $tiene->first(
                static fn (PersonaIdentificador $uno): bool => $uno->tipo === $tipo,
            );

            if ($identificador instanceof PersonaIdentificador) {
                return $identificador;
            }
        }

        return null;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL CÓDIGO DE CLIENTE ES EL EXPEDIENTE (Mauricio, 11-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «En código de cliente, el código que tiene el paciente en el
     * sistema.» Lo pone el motor, como el RTN del pagador: es un número
     * que el sistema ya sabe y que nadie debería teclear en la caja.
     *
     * Sin expediente —no debería pasar, pero los datos viejos existen—
     * queda lo que haya llegado.
     */
    private function conElCodigoDelPaciente(ClienteDeFactura $cliente, ?string $expediente): ClienteDeFactura
    {
        if ($expediente === null || trim($expediente) === '') {
            return $cliente;
        }

        return new ClienteDeFactura(
            nombre: $cliente->nombre,
            documento: $cliente->documento,
            tipoDocumento: $cliente->tipoDocumento,
            direccion: $cliente->direccion,
            telefono: $cliente->telefono,
            codigo: $expediente,
            ordenExenta: $cliente->ordenExenta,
            constanciaExonerado: $cliente->constanciaExonerado,
            registroSag: $cliente->registroSag,
        );
    }

    /**
     * La póliza que se imprime junto al paciente: la que se escribió al
     * facturar, o la de la cuenta si no llegó ninguna.
     */
    private function polizaDe(Cuenta $cuenta, ?string $escrita): ?string
    {
        $poliza = TextoCanonico::mayusculas($escrita ?? $cuenta->numero_poliza);

        return $poliza === null ? null : mb_substr($poliza, 0, 40);
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
