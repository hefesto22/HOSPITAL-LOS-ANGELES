<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * El resumen de un período: lo facturado, cómo lo pagaron los pacientes,
 * lo que quedó a crédito de cada seguro (Mauricio, 6-nov-2026: «cuánto se
 * facturó ese mes, cuánto se recibió en transferencias, tarjetas y
 * efectivo, y cuánto se dio a cada seguro») y lo que queda después de
 * medicamentos y honorarios de médicos (11-nov-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN SOLO RELOJ: EL DE LA FACTURA (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo sale de las facturas EMITIDAS en el período, por su fecha de
 * operación. Mauricio: «solo de lo facturado ese mes, no incluye cuentas
 * abiertas».
 *
 *   · Lo COBRADO son los recibos vivos de las cuentas de esas facturas,
 *     se hayan recibido cuando se hayan recibido: el anticipo de octubre
 *     de una cuenta que se facturó en noviembre es plata de noviembre.
 *     Un abono a una cuenta que todavía no se factura no está.
 *     Antes (6-nov) iba por la fecha del recibo y metía las cuentas
 *     abiertas; la caja del día sigue en los cierres de turno.
 *   · El RESULTADO —lo que queda después de medicamentos y honorarios—
 *     sale de las mismas facturas (ver `resultado()`).
 *
 * ⚠️ Una cuenta con dos facturas en meses distintos (la complementaria
 * de un cargo tardío) pone sus recibos y su costo en los dos meses. Es
 * raro y queda dicho; amarrarlo a una sola obligaría a repartir cada
 * recibo entre facturas que el recibo no nombra.
 *
 * Las facturas anuladas se cuentan aparte y no suman: una factura anulada
 * no vendió nada. Los recibos anulados no existen para este reporte —al
 * anular una factura, los recibos devueltos se anulan con ella—.
 *
 * Todo el dinero sale como cadena con dos decimales: se suma en
 * PostgreSQL (NUMERIC) y se redondea con `Decimal`, nunca con float.
 */
final class ResumenDeFacturacion
{
    /**
     * El papel cubre como máximo un año, como el de honorarios: con las
     * facturas una por una, más que eso ya no es un reporte sino un volcado.
     */
    public const DIAS_MAXIMOS = 366;

    /**
     * La sede de quien mira. La pantalla y el papel filtran con esta misma
     * (§9.L5): si no, el papel podría decir más que la pantalla. Sin sede
     * asignada —soporte, dirección que cruza sedes—, todas.
     */
    public static function sedeDe(mixed $usuario): ?int
    {
        if (! $usuario instanceof User) {
            return null;
        }

        $sede = $usuario->getAttribute('sede_id');

        return is_numeric($sede) ? (int) $sede : null;
    }

    /**
     * @return array{
     *     facturado: array{cuantas: int, total: string, exento: string, gravado: string, isv: string, descuentos: string, seguros: string, pacientes: string},
     *     anuladas: array{cuantas: int, total: string},
     *     cobrado: array{total: string, recibos: int, pendiente: string, formas: list<array{forma: string, etiqueta: string, monto: string, recibos: int, bancos: list<array{banco: string, monto: string}>}>},
     *     seguros: list<array{convenio_id: int, nombre: string, facturas: int, facturado: string, a_credito: string, copago: string, plazo: string}>,
     *     resultado: array{ingresos: string, isv: string, medicamentos: array{renglones: int, vendido: string, costo: string, sin_costo: int, vendido_sin_costo: string}, honorarios: array{renglones: int, total: string}, despues_de_medicamentos: string, despues_de_honorarios: string},
     *     dinero: array{entro: string, por_cobrar_seguros: string, por_cobrar_pacientes: string, queda_de_lo_que_entro: string, queda_por_cobrar: string}
     * }
     */
    public function delPeriodo(string $desde, string $hasta, ?int $sedeId = null): array
    {
        $facturado = $this->facturado($desde, $hasta, $sedeId);
        $cobrado = $this->cobrado($desde, $hasta, $sedeId);

        /*
         * Lo que de lo facturado a los pacientes todavía no entró. Con la
         * regla de que una cuenta se factura pagada (11-oct), debería ser
         * cero; si no lo es, el reporte lo dice en vez de esconderlo.
         */
        $cobrado['pendiente'] = Decimal::de($facturado['pacientes'])->restar($cobrado['total'])->redondeado(2);

        $resultado = $this->resultado($desde, $hasta, $sedeId, $facturado['total'], $facturado['isv']);

        return [
            'facturado' => $facturado,
            'anuladas'  => $this->anuladas($desde, $hasta, $sedeId),
            'cobrado'   => $cobrado,
            'seguros'   => $this->seguros($desde, $hasta, $sedeId),
            'resultado' => $resultado,
            'dinero'    => self::dineroQueEntro(
                seguros: $facturado['seguros'],
                isv: $facturado['isv'],
                entro: $cobrado['total'],
                pendiente: $cobrado['pendiente'],
                medicamentos: $resultado['medicamentos']['costo'],
                honorarios: $resultado['honorarios']['total'],
            ),
        ];
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LO QUE ENTRÓ DE VERDAD (Mauricio, 11-nov-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «Se recibieron 50,000 entre efectivo y transferencia y otros 50,000
     * por seguros: eso fue lo facturado. Pero tiene que haber separación
     * del dinero que sí entró a cuentas, para saber el valor real de lo
     * que entró ese mes.»
     *
     * Lo facturado se parte en tres, y suman al centavo:
     *
     *   entró                 lo que pagaron los pacientes: efectivo,
     *                         tarjeta y transferencia
     *   por cobrar a seguros  lo que quedó a crédito: todavía no entra
     *   por cobrar a pacientes lo que a los pacientes les tocó y no
     *                         pagaron (debería ser cero; negativo si se
     *                         les cobró de más)
     *
     * Y lo que queda después de medicamentos y honorarios se parte igual:
     *
     *   de lo que entró    = entró − ISV − medicamentos − honorarios
     *   de lo que falta    = por cobrar a seguros + por cobrar a pacientes
     *
     * Las dos suman «Después de honorarios» exacto. La primera puede ser
     * negativa: quiere decir que lo que entró no alcanzó para lo que costó
     * el mes y el hospital está poniendo plata mientras los seguros pagan.
     * Los costos se restan enteros de lo que entró a propósito: así se ve
     * si lo que ya entró alcanza para lo que costó el mes. (Si a los
     * médicos se les paga cuando paga el seguro, es aquí donde cambia.)
     *
     * Los pagos que hagan los seguros todavía no se registran en el
     * sistema: hasta entonces, lo de los seguros es siempre «por cobrar».
     *
     * @return array{entro: string, por_cobrar_seguros: string, por_cobrar_pacientes: string, queda_de_lo_que_entro: string, queda_por_cobrar: string}
     */
    private static function dineroQueEntro(
        string $seguros,
        string $isv,
        string $entro,
        string $pendiente,
        string $medicamentos,
        string $honorarios,
    ): array {
        return [
            'entro'                 => self::dinero($entro),
            'por_cobrar_seguros'    => self::dinero($seguros),
            'por_cobrar_pacientes'  => self::dinero($pendiente),
            'queda_de_lo_que_entro' => Decimal::de(self::dinero($entro))
                ->restar(self::dinero($isv))
                ->restar(self::dinero($medicamentos))
                ->restar(self::dinero($honorarios))
                ->redondeado(2),
            'queda_por_cobrar' => Decimal::de(self::dinero($seguros))->sumar(self::dinero($pendiente))->redondeado(2),
        ];
    }

    // ── Lo facturado ──────────────────────────────────────────────────

    /**
     * @return array{cuantas: int, total: string, exento: string, gravado: string, isv: string, descuentos: string, seguros: string, pacientes: string}
     */
    private function facturado(string $desde, string $hasta, ?int $sedeId): array
    {
        $fila = (array) ($this->facturas($desde, $hasta, $sedeId)
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->selectRaw('count(*) as cuantas')
            ->selectRaw('coalesce(sum(facturas.total), 0) as total')
            ->selectRaw('coalesce(sum(facturas.exento), 0) as exento')
            ->selectRaw('coalesce(sum(facturas.gravado), 0) as gravado')
            ->selectRaw('coalesce(sum(facturas.isv), 0) as isv')
            ->selectRaw('coalesce(sum(facturas.descuento_legal + facturas.descuento_comercial), 0) as descuentos')
            ->selectRaw('coalesce(sum(facturas.a_cargo_del_seguro), 0) as seguros')
            ->first() ?? []);

        $total = self::dinero($fila['total'] ?? 0);
        $seguros = self::dinero($fila['seguros'] ?? 0);

        return [
            'cuantas'    => (int) ($fila['cuantas'] ?? 0),
            'total'      => $total,
            'exento'     => self::dinero($fila['exento'] ?? 0),
            'gravado'    => self::dinero($fila['gravado'] ?? 0),
            'isv'        => self::dinero($fila['isv'] ?? 0),
            'descuentos' => self::dinero($fila['descuentos'] ?? 0),
            'seguros'    => $seguros,
            'pacientes'  => Decimal::de($total)->restar($seguros)->redondeado(2),
        ];
    }

    /**
     * @return array{cuantas: int, total: string}
     */
    private function anuladas(string $desde, string $hasta, ?int $sedeId): array
    {
        $fila = (array) ($this->facturas($desde, $hasta, $sedeId)
            ->where('facturas.estado', EstadoFactura::Anulada->value)
            ->selectRaw('count(*) as cuantas')
            ->selectRaw('coalesce(sum(facturas.total), 0) as total')
            ->first() ?? []);

        return [
            'cuantas' => (int) ($fila['cuantas'] ?? 0),
            'total'   => self::dinero($fila['total'] ?? 0),
        ];
    }

    // ── Lo cobrado de esas facturas ───────────────────────────────────

    /**
     * Por forma de pago, en el orden del enum, las tres siempre —una en
     * cero también dice algo—. El banco solo existe en la transferencia
     * (CHECK de `abono_medios`).
     *
     * Son los recibos de las cuentas facturadas en el período, no la caja
     * del período (ver la cabecera de la clase).
     *
     * @return array{total: string, recibos: int, formas: list<array{forma: string, etiqueta: string, monto: string, recibos: int, bancos: list<array{banco: string, monto: string}>}>}
     */
    private function cobrado(string $desde, string $hasta, ?int $sedeId): array
    {
        $porBanco = $this->medios($desde, $hasta, $sedeId)
            ->groupBy('abono_medios.forma', 'abono_medios.banco')
            ->orderBy('abono_medios.banco')
            ->select('abono_medios.forma', 'abono_medios.banco')
            ->selectRaw('sum(abono_medios.monto) as monto')
            ->get()
            ->map(fn (object $fila): array => (array) $fila);

        $recibosPorForma = $this->medios($desde, $hasta, $sedeId)
            ->groupBy('abono_medios.forma')
            ->select('abono_medios.forma')
            ->selectRaw('count(distinct abonos.id) as recibos')
            ->pluck('recibos', 'abono_medios.forma')
            ->all();

        $formas = [];
        $total = Decimal::cero();

        foreach (FormaDePago::cases() as $forma) {
            $suyas = $porBanco->filter(fn (array $fila): bool => ($fila['forma'] ?? null) === $forma->value);

            $monto = Decimal::cero();
            $bancos = [];

            foreach ($suyas as $fila) {
                $suyo = self::dinero($fila['monto'] ?? 0);
                $monto = $monto->sumar($suyo);

                $banco = $fila['banco'] ?? null;

                if (is_string($banco) && trim($banco) !== '') {
                    $bancos[] = ['banco' => $banco, 'monto' => $suyo];
                }
            }

            $total = $total->sumar($monto);

            $formas[] = [
                'forma'    => $forma->value,
                'etiqueta' => $forma->etiqueta(),
                'monto'    => $monto->redondeado(2),
                'recibos'  => (int) ($recibosPorForma[$forma->value] ?? 0),
                'bancos'   => $bancos,
            ];
        }

        $recibos = (int) $this->medios($desde, $hasta, $sedeId)
            ->distinct()
            ->count('abonos.id');

        return [
            'total'   => $total->redondeado(2),
            'recibos' => $recibos,
            'formas'  => $formas,
        ];
    }

    // ── Lo que quedó a crédito de cada seguro ─────────────────────────

    /**
     * Una fila por pagador que paga un tercero, con lo que se le facturó a
     * crédito y lo que puso el paciente de copago. El que más debe, arriba.
     *
     * @return list<array{convenio_id: int, nombre: string, facturas: int, facturado: string, a_credito: string, copago: string, plazo: string}>
     */
    private function seguros(string $desde, string $hasta, ?int $sedeId): array
    {
        $quienesNoPaganUnTercero = array_values(array_map(
            fn (TipoConvenio $tipo): string => $tipo->value,
            array_filter(TipoConvenio::cases(), fn (TipoConvenio $tipo): bool => ! $tipo->pagaUnTercero()),
        ));

        $filas = $this->facturas($desde, $hasta, $sedeId)
            ->join('convenios', 'convenios.id', '=', 'facturas.convenio_id')
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->whereNotIn('convenios.tipo', $quienesNoPaganUnTercero)
            ->groupBy('convenios.id', 'convenios.nombre', 'convenios.dias_credito')
            ->orderByRaw('sum(facturas.a_cargo_del_seguro) desc')
            ->orderBy('convenios.nombre')
            ->select('convenios.id', 'convenios.nombre', 'convenios.dias_credito')
            ->selectRaw('count(*) as facturas')
            ->selectRaw('sum(facturas.total) as facturado')
            ->selectRaw('sum(facturas.a_cargo_del_seguro) as a_credito')
            ->get();

        $seguros = [];

        foreach ($filas as $fila) {
            $fila = (array) $fila;

            $facturado = self::dinero($fila['facturado'] ?? 0);
            $aCredito = self::dinero($fila['a_credito'] ?? 0);
            $dias = $fila['dias_credito'] ?? null;

            $seguros[] = [
                'convenio_id' => (int) ($fila['id'] ?? 0),
                'nombre'      => (string) ($fila['nombre'] ?? ''),
                'facturas'    => (int) ($fila['facturas'] ?? 0),
                'facturado'   => $facturado,
                'a_credito'   => $aCredito,
                'copago'      => Decimal::de($facturado)->restar($aCredito)->redondeado(2),
                'plazo'       => is_numeric($dias) && (int) $dias > 0 ? ((int) $dias).' días' : 'Sin plazo fijo',
            ];
        }

        return $seguros;
    }

    // ── Lo que queda después de medicamentos y honorarios ─────────────

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL RESULTADO DEL MES (Mauricio, 11-nov-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «Ingresos, ingresos después de costos de medicamentos, y después de
     * honorarios de médicos, todo bien detallado, solo de lo facturado.»
     *
     *   Ingresos             lo facturado SIN el ISV: el ISV se cobra a
     *                        nombre del SAR y no es del hospital.
     *   − Medicamentos       el COSTO de los medicamentos y materiales de
     *                        las cuentas facturadas —lo que mueve
     *                        inventario—, el que quedó congelado en cada
     *                        cargo (§8.7-10). Entra también lo consumido
     *                        dentro de un paquete, que no se cobra aparte
     *                        pero sí costó.
     *   − Honorarios         los de los MÉDICOS, completos: hoy todo el
     *                        honorario va al médico. El porcentaje que se
     *                        quede el hospital será configuración (pendiente).
     *                        Los del personal por turno NO se restan
     *                        todavía: no se sabe si son del hospital o del
     *                        turno (Mauricio, 11-nov-2026).
     *
     * ⚠️ Desde el 11-oct bodega recibe sin costo, así que hay cargos sin
     * costo congelado. No se inventan: se cuentan aparte —cuántos y cuánto
     * se vendió de ellos— para que quien lea sepa que el resultado de
     * verdad es MENOR que el que sale.
     *
     * Los honorarios usan la misma regla que «Honorarios por médico»
     * (`ReporteDeHonorarios::soloDeMedicos()` + `soloSinMedico()`): un
     * test cruza que los dos reportes digan lo mismo.
     *
     * @param string $facturado lo facturado del período, con ISV
     * @param string $isv el ISV de esas facturas
     *
     * @return array{ingresos: string, isv: string, medicamentos: array{renglones: int, vendido: string, costo: string, sin_costo: int, vendido_sin_costo: string}, honorarios: array{renglones: int, total: string}, despues_de_medicamentos: string, despues_de_honorarios: string}
     */
    private function resultado(string $desde, string $hasta, ?int $sedeId, string $facturado, string $isv): array
    {
        $ingresos = Decimal::de($facturado)->restar($isv);
        $medicamentos = $this->medicamentos($desde, $hasta, $sedeId);
        $honorarios = $this->honorariosDeMedicos($desde, $hasta, $sedeId);

        $despuesDeMedicamentos = $ingresos->restar($medicamentos['costo']);
        $despuesDeHonorarios = $despuesDeMedicamentos->restar($honorarios['total']);

        return [
            'ingresos'                => $ingresos->redondeado(2),
            'isv'                     => self::dinero($isv),
            'medicamentos'            => $medicamentos,
            'honorarios'              => $honorarios,
            'despues_de_medicamentos' => $despuesDeMedicamentos->redondeado(2),
            'despues_de_honorarios'   => $despuesDeHonorarios->redondeado(2),
        ];
    }

    /**
     * Los medicamentos y materiales de las cuentas facturadas: lo vendido
     * (lo que entró en la factura) y lo que costó (todo lo que salió del
     * estante para esa cuenta, cobrado aparte o dentro de un paquete).
     *
     * Vivos: facturados, o pendientes que nunca se cobran solos (incluido
     * en la tarifa, gasto del servicio). Lo anulado y su reversa se
     * cancelan y no entran; un pendiente COBRABLE es un cargo tardío que
     * va en la factura que sigue, no en esta.
     *
     * @return array{renglones: int, vendido: string, costo: string, sin_costo: int, vendido_sin_costo: string}
     */
    private function medicamentos(string $desde, string $hasta, ?int $sedeId): array
    {
        $deFarmacia = array_values(array_map(
            fn (TipoItem $tipo): string => $tipo->value,
            array_filter(TipoItem::cases(), fn (TipoItem $tipo): bool => $tipo->mueveInventario()),
        ));

        $fila = (array) (DB::table('cargos')
            ->join('items', 'items.id', '=', 'cargos.item_id')
            ->whereIn('cargos.cuenta_id', $this->cuentasFacturadas($desde, $hasta, $sedeId))
            ->whereIn('items.tipo', $deFarmacia)
            ->where(function (Builder $vivos): void {
                $vivos->where('cargos.estado', EstadoCargo::Facturado->value)
                    ->orWhere(function (Builder $deUnPaquete): void {
                        $deUnPaquete->where('cargos.estado', EstadoCargo::Pendiente->value)
                            ->where('cargos.politica_cargo', '<>', PoliticaCargo::Cobrable->value);
                    });
            })
            ->selectRaw('count(*) as renglones')
            ->selectRaw("coalesce(sum(cargos.total) filter (where cargos.estado = 'facturado'), 0) as vendido")
            ->selectRaw('coalesce(sum(cargos.costo_total), 0) as costo')
            ->selectRaw('count(*) filter (where cargos.costo_total is null) as sin_costo')
            ->selectRaw("coalesce(sum(cargos.total) filter (where cargos.costo_total is null and cargos.estado = 'facturado'), 0) as vendido_sin_costo")
            ->first() ?? []);

        return [
            'renglones'         => (int) ($fila['renglones'] ?? 0),
            'vendido'           => self::dinero($fila['vendido'] ?? 0),
            'costo'             => self::dinero($fila['costo'] ?? 0),
            'sin_costo'         => (int) ($fila['sin_costo'] ?? 0),
            'vendido_sin_costo' => self::dinero($fila['vendido_sin_costo'] ?? 0),
        ];
    }

    /**
     * Los honorarios de médico facturados en el período, por las líneas de
     * la factura —como «Honorarios por médico»—: los que tienen médico y
     * los de médico que se cargaron sin médico. Los del personal no.
     *
     * @return array{renglones: int, total: string}
     */
    private function honorariosDeMedicos(string $desde, string $hasta, ?int $sedeId): array
    {
        $fila = (array) (DB::table('cargos')
            ->join('factura_lineas', 'factura_lineas.cargo_id', '=', 'cargos.id')
            ->join('facturas', function (JoinClause $union): void {
                $union->on('facturas.id', '=', 'factura_lineas.factura_id')
                    ->on('facturas.cuenta_id', '=', 'cargos.cuenta_id');
            })
            ->join('items', 'items.id', '=', 'cargos.item_id')
            ->where('facturas.tipo', TipoDocumentoDeVenta::Factura->value)
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->where('factura_lineas.encabezado', false)
            ->whereBetween('facturas.fecha_operacion', [$desde, $hasta])
            ->when($sedeId !== null, fn (Builder $consulta): Builder => $consulta->where('facturas.sede_id', $sedeId))
            ->where('items.cobra_el_honorario', QuienCobraElHonorario::Medico->value)
            ->where(function (Builder $deMedico): void {
                $deMedico->whereNotNull('cargos.medico_id')
                    ->orWhere('items.tipo', TipoItem::Honorario->value);
            })
            ->selectRaw('count(*) as renglones')
            ->selectRaw('coalesce(sum(cargos.total), 0) as total')
            ->first() ?? []);

        return [
            'renglones' => (int) ($fila['renglones'] ?? 0),
            'total'     => self::dinero($fila['total'] ?? 0),
        ];
    }

    /**
     * A dónde se fueron los ingresos: medicamentos, honorarios de médicos
     * y lo que queda, cada parte con su porción de los ingresos. Es la
     * barra de la pantalla y del papel, armada en un solo lugar.
     *
     * Vacío si no hay ingresos o si lo que queda es negativo: una barra no
     * puede dibujar más del todo, y ahí la cascada ya lo dice en rojo.
     *
     * @param array{ingresos: string, medicamentos: array{costo: string}, honorarios: array{total: string}, despues_de_honorarios: string} $resultado
     *
     * @return list<array{clave: string, nombre: string, monto: string, parte: string}>
     */
    public static function repartoDeLosIngresos(array $resultado): array
    {
        $ingresos = Decimal::de($resultado['ingresos']);

        if (! $ingresos->mayorQue('0') || Decimal::de($resultado['despues_de_honorarios'])->esNegativo()) {
            return [];
        }

        $partes = [
            ['clave' => 'medicamentos', 'nombre' => 'Medicamentos y materiales', 'monto' => $resultado['medicamentos']['costo']],
            ['clave' => 'honorarios', 'nombre' => 'Honorarios de médicos', 'monto' => $resultado['honorarios']['total']],
            ['clave' => 'queda', 'nombre' => 'Queda', 'monto' => $resultado['despues_de_honorarios']],
        ];

        $reparto = [];

        foreach ($partes as $parte) {
            $reparto[] = $parte + [
                'parte' => Decimal::de($parte['monto'])->entre($ingresos)->por('100')->redondeado(1),
            ];
        }

        return $reparto;
    }

    /**
     * Lo facturado partido en lo que entró y lo que falta cobrar: la
     * barra de «Lo que entró y lo que falta», en pantalla y en papel.
     *
     * Vacío sin facturado, o si a los pacientes se les cobró de más: ahí
     * lo que entró es más que su parte y una barra no lo puede dibujar.
     *
     * @param array{facturado: array{total: string}, dinero: array{entro: string, por_cobrar_seguros: string, por_cobrar_pacientes: string}} $resumen
     *
     * @return list<array{clave: string, nombre: string, monto: string, parte: string}>
     */
    public static function repartoDeLoFacturado(array $resumen): array
    {
        $total = Decimal::de($resumen['facturado']['total']);
        $dinero = $resumen['dinero'];

        if (! $total->mayorQue('0') || Decimal::de($dinero['por_cobrar_pacientes'])->esNegativo()) {
            return [];
        }

        $partes = [
            ['clave' => 'entro', 'nombre' => 'Entró', 'monto' => $dinero['entro']],
            ['clave' => 'seguros', 'nombre' => 'Por cobrar a los seguros', 'monto' => $dinero['por_cobrar_seguros']],
            ['clave' => 'pacientes', 'nombre' => 'Por cobrar a los pacientes', 'monto' => $dinero['por_cobrar_pacientes']],
        ];

        $reparto = [];

        foreach ($partes as $parte) {
            $reparto[] = $parte + [
                'parte' => Decimal::de($parte['monto'])->entre($total)->por('100')->redondeado(1),
            ];
        }

        return $reparto;
    }

    // ── Las facturas, una por una, para el papel ──────────────────────

    /**
     * Las facturas del período en el orden en que se emitieron, las
     * anuladas incluidas —marcadas—: el papel tiene que poder cuadrarse
     * contra el talonario, y un número que falta es lo primero que se
     * pregunta.
     *
     * Por la fecha de operación, como todo el reporte (§7.5-4).
     *
     * @return list<array{numero: string, fecha: string, cliente: string, paciente: string|null, pagador: string|null, total: string, seguro: string, paciente_paga: string, anulada: bool}>
     */
    public function facturasDelPeriodo(string $desde, string $hasta, ?int $sedeId = null): array
    {
        $filas = $this->facturas($desde, $hasta, $sedeId)
            ->leftJoin('convenios', 'convenios.id', '=', 'facturas.convenio_id')
            ->orderBy('facturas.fecha_operacion')
            ->orderBy('facturas.numero')
            ->select([
                'facturas.numero',
                'facturas.fecha_operacion',
                'facturas.cliente_nombre',
                'facturas.paciente_nombre',
                'facturas.total',
                'facturas.a_cargo_del_seguro',
                'facturas.estado',
                'convenios.nombre as pagador',
            ])
            ->get();

        $facturas = [];

        foreach ($filas as $fila) {
            $fila = (array) $fila;

            $total = self::dinero($fila['total'] ?? 0);
            $seguro = self::dinero($fila['a_cargo_del_seguro'] ?? 0);
            $fecha = is_string($fila['fecha_operacion'] ?? null) ? $fila['fecha_operacion'] : '';
            $paciente = $fila['paciente_nombre'] ?? null;
            $pagador = $fila['pagador'] ?? null;

            $facturas[] = [
                'numero'        => is_string($fila['numero'] ?? null) ? $fila['numero'] : '',
                'fecha'         => $fecha === '' ? '' : CarbonImmutable::parse($fecha)->format('d/m/Y'),
                'cliente'       => is_string($fila['cliente_nombre'] ?? null) ? $fila['cliente_nombre'] : '',
                'paciente'      => is_string($paciente) && $paciente !== '' ? $paciente : null,
                'pagador'       => is_string($pagador) && $pagador !== '' ? $pagador : null,
                'total'         => $total,
                'seguro'        => $seguro,
                'paciente_paga' => Decimal::de($total)->restar($seguro)->redondeado(2),
                'anulada'       => ($fila['estado'] ?? null) === EstadoFactura::Anulada->value,
            ];
        }

        return $facturas;
    }

    // ── Las consultas base ────────────────────────────────────────────

    /**
     * Las facturas (no notas) del período, de la sede si se pide una.
     */
    private function facturas(string $desde, string $hasta, ?int $sedeId): Builder
    {
        return DB::table('facturas')
            ->where('facturas.tipo', TipoDocumentoDeVenta::Factura->value)
            ->whereBetween('facturas.fecha_operacion', [$desde, $hasta])
            ->when($sedeId !== null, fn (Builder $consulta): Builder => $consulta->where('facturas.sede_id', $sedeId));
    }

    /**
     * Las cuentas de las facturas emitidas del período: de ellas salen lo
     * cobrado y el costo.
     */
    private function cuentasFacturadas(string $desde, string $hasta, ?int $sedeId): Builder
    {
        return $this->facturas($desde, $hasta, $sedeId)
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->select('facturas.cuenta_id');
    }

    /**
     * Las formas de pago de los recibos vigentes de esas cuentas, sin
     * importar el día en que se recibieron.
     */
    private function medios(string $desde, string $hasta, ?int $sedeId): Builder
    {
        return DB::table('abono_medios')
            ->join('abonos', 'abonos.id', '=', 'abono_medios.abono_id')
            ->where('abonos.estado', EstadoAbono::Aplicado->value)
            ->whereIn('abonos.cuenta_id', $this->cuentasFacturadas($desde, $hasta, $sedeId));
    }

    /**
     * Un número de la base, con dos decimales y sin pasar por float.
     */
    private static function dinero(mixed $valor): string
    {
        $texto = is_numeric($valor) ? (string) $valor : '0';

        return Decimal::de($texto)->redondeado(2);
    }
}
