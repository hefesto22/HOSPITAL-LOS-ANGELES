<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\ValueObjects\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El resumen de un período: lo facturado, lo cobrado en caja y lo que
 * quedó a crédito de cada seguro (Mauricio, 6-nov-2026: «cuánto se
 * facturó ese mes, cuánto se recibió en transferencias, tarjetas y
 * efectivo, y cuánto se dio a cada seguro»).
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS RELOJES, Y NO SE MEZCLAN
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Lo FACTURADO va por la fecha de operación de la factura.
 *   · Lo COBRADO va por la fecha del recibo: es la plata que entró a caja
 *     en el período, sea de una factura de este mes, de una del pasado o
 *     un abono a una cuenta que todavía no se factura.
 *
 * Por eso «facturado» y «cobrado» no tienen por qué coincidir, y no es un
 * descuadre: lo que los separa es lo que quedó a crédito de los seguros
 * y lo que se cobró antes o se cobrará después.
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
     * @return array{
     *     facturado: array{cuantas: int, total: string, exento: string, gravado: string, isv: string, descuentos: string, seguros: string, pacientes: string},
     *     anuladas: array{cuantas: int, total: string},
     *     cobrado: array{total: string, recibos: int, formas: list<array{forma: string, etiqueta: string, monto: string, recibos: int, bancos: list<array{banco: string, monto: string}>}>},
     *     seguros: list<array{convenio_id: int, nombre: string, facturas: int, facturado: string, a_credito: string, copago: string, plazo: string}>
     * }
     */
    public function delPeriodo(string $desde, string $hasta, ?int $sedeId = null): array
    {
        return [
            'facturado' => $this->facturado($desde, $hasta, $sedeId),
            'anuladas'  => $this->anuladas($desde, $hasta, $sedeId),
            'cobrado'   => $this->cobrado($desde, $hasta, $sedeId),
            'seguros'   => $this->seguros($desde, $hasta, $sedeId),
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

    // ── Lo cobrado en caja ────────────────────────────────────────────

    /**
     * Por forma de pago, en el orden del enum, las tres siempre —una en
     * cero también dice algo—. El banco solo existe en la transferencia
     * (CHECK de `abono_medios`).
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
     * Las formas de pago de los recibos vigentes del período.
     */
    private function medios(string $desde, string $hasta, ?int $sedeId): Builder
    {
        return DB::table('abono_medios')
            ->join('abonos', 'abonos.id', '=', 'abono_medios.abono_id')
            ->where('abonos.estado', EstadoAbono::Aplicado->value)
            ->whereBetween('abonos.fecha_operacion', [$desde, $hasta])
            ->when($sedeId !== null, fn (Builder $consulta): Builder => $consulta->where('abonos.sede_id', $sedeId));
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
