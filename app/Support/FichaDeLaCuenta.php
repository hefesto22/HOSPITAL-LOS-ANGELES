<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Persona;
use App\Models\Servicio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LA FICHA DE UNA CUENTA, EN UNA SOLA HOJA (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «Todos los cargos no tiene sentido: en cada cuenta habrá
 * historial de todo lo que se hace… que tenga un acceso a ver bitácora y
 * mejorar el diseño». Antes «Ver cuenta» repartía lo mismo en cinco
 * pestañas —Identificación, Pagador, Dinero, Estado, Historial— y la
 * bitácora quedaba en la última.
 *
 * Ahora es una hoja: arriba de quién es y en qué etapa va, debajo las
 * cinco cifras que se vienen a buscar (total, seguro, paciente, abonado,
 * falta), las facturas, y después la bitácora abierta y los cargos.
 *
 * Esta clase arma los datos; la vista
 * (`filament.infolists.ficha-de-la-cuenta`) solo los pinta. Y es la única
 * fuente de cómo se dice el estado y lo que falta: la lista de «Todas las
 * cuentas» usa las mismas funciones.
 *
 * ⚠️ Recarga el encuentro, el convenio y el responsable COMPLETOS. La
 * consulta del Resource los trae con columnas nombradas, y una columna
 * que no está en la lista llega `null` sin error (§9.M6): la ficha de
 * antes mostraba «Se decide al facturar» en todos los seguros porque al
 * convenio le faltaba `cobertura_fraccion`, y el servicio nunca salía
 * porque al encuentro le faltaba `servicio_id`.
 */
final class FichaDeLaCuenta
{
    /**
     * @return array{
     *     paciente: string,
     *     tipo: TipoEncuentro,
     *     servicio: ?string,
     *     estado: EstadoCuenta,
     *     estadoEnPalabras: string,
     *     iconoDelEstado: string,
     *     queQuiereDecir: string,
     *     pagador: string,
     *     colorDelPagador: string,
     *     datos: list<array{rotulo: string, valor: string, mono: bool}>,
     *     cifras: list<array{rotulo: string, valor: string, nota: ?string, tono: string}>,
     *     desglose: list<array{rotulo: string, valor: string}>,
     *     facturas: list<array{numero: string, total: string, cuando: string, anulada: bool, enlace: ?string}>,
     *     anuladaPorque: ?string
     * }
     */
    public static function de(Cuenta $cuenta): array
    {
        $cuenta->load([
            'encuentro',
            'encuentro.persona',
            'encuentro.servicio:id,nombre',
            'convenio',
            'responsable',
            'cuentaAnterior:id,numero',
        ]);

        $encuentro = $cuenta->encuentro;
        $servicio = $encuentro->servicio;
        $abonado = $cuenta->abonado();
        $total = Decimal::de($cuenta->total);

        return [
            'paciente'         => $encuentro->persona->nombreCompleto(),
            'tipo'             => $encuentro->tipo,
            'servicio'         => $servicio instanceof Servicio ? $servicio->nombre : null,
            'estado'           => $cuenta->estado,
            'estadoEnPalabras' => self::estadoEnPalabras($cuenta->estado),
            'iconoDelEstado'   => self::iconoDelEstado($cuenta->estado),
            'queQuiereDecir'   => self::queQuiereDecir($cuenta->estado),
            'pagador'          => $cuenta->convenio->nombre,
            'colorDelPagador'  => $cuenta->convenio->tipo->color(),
            'datos'            => self::datos($cuenta),
            'cifras'           => self::cifras($cuenta, $total, $abonado),
            'desglose'         => [
                ['rotulo' => 'Bruto', 'valor' => self::lempiras($cuenta->total_bruto)],
                ['rotulo' => 'Descuentos', 'valor' => self::lempiras($cuenta->total_descuento)],
                ['rotulo' => 'Exento', 'valor' => self::lempiras($cuenta->total_exento)],
                ['rotulo' => 'Gravado', 'valor' => self::lempiras($cuenta->total_gravado)],
                ['rotulo' => 'ISV', 'valor' => self::lempiras($cuenta->total_isv)],
            ],
            'facturas'      => self::facturas($cuenta),
            'anuladaPorque' => $cuenta->estado === EstadoCuenta::Anulada
                ? (filled($cuenta->motivo_anulacion) ? $cuenta->motivo_anulacion : 'Sin motivo escrito.')
                : null,
        ];
    }

    // ── Cómo se dice cada cosa (también lo usa la lista) ─────────────

    /**
     * El estado como lo dice el mostrador. `Cerrada` solo la pone el
     * emisor al facturar, y anular la factura devuelve la cuenta a
     * `Abierta`: «Facturada» es exacto.
     */
    public static function estadoEnPalabras(EstadoCuenta $estado): string
    {
        return match ($estado) {
            EstadoCuenta::Abierta   => 'Abierta',
            EstadoCuenta::Congelada => 'En egreso',
            EstadoCuenta::Cerrada   => 'Facturada',
            EstadoCuenta::Anulada   => 'Anulada',
        };
    }

    public static function iconoDelEstado(EstadoCuenta $estado): string
    {
        return match ($estado) {
            EstadoCuenta::Abierta   => 'heroicon-m-lock-open',
            EstadoCuenta::Congelada => 'heroicon-m-pause-circle',
            EstadoCuenta::Cerrada   => 'heroicon-m-check-circle',
            EstadoCuenta::Anulada   => 'heroicon-m-x-circle',
        };
    }

    public static function queQuiereDecir(EstadoCuenta $estado): string
    {
        return match ($estado) {
            EstadoCuenta::Abierta   => 'Sigue recibiendo cargos.',
            EstadoCuenta::Congelada => 'Ya tiene alta: solo entran los cargos que faltaban, antes de facturar.',
            EstadoCuenta::Cerrada   => 'Ya se facturó. No admite cargos nuevos.',
            EstadoCuenta::Anulada   => 'Se anuló. No se cobra.',
        };
    }

    /**
     * Lo que falta, en una frase: el número, «Saldada», «Sin cargos» o la
     * plata que hay que devolver. La anulada no debe nada: null (raya).
     *
     * «Sin cargos» y no «Saldada» para la cuenta recién abierta: decir
     * que está pagada una cuenta a la que todavía no se le cargó nada
     * confunde a quien la lee.
     */
    public static function loQueFalta(EstadoCuenta $estado, Decimal $total, Decimal $abonado): ?string
    {
        if ($estado === EstadoCuenta::Anulada) {
            return null;
        }

        if ($total->esCero() && $abonado->esCero()) {
            return 'Sin cargos';
        }

        $falta = $total->restar($abonado);

        if ($falta->esCero()) {
            return 'Saldada';
        }

        if ($falta->esNegativo()) {
            return 'A favor '.Monto::de(Decimal::cero()->restar($falta))->formateado();
        }

        return Monto::de($falta)->formateado();
    }

    /**
     * Ámbar = se debe, verde = saldada, celeste = pagaron de más (plata a
     * devolver en el egreso), gris = no hay nada que cobrar.
     */
    public static function colorDeLoQueFalta(EstadoCuenta $estado, Decimal $total, Decimal $abonado): string
    {
        if ($estado === EstadoCuenta::Anulada || ($total->esCero() && $abonado->esCero())) {
            return 'gray';
        }

        $falta = $total->restar($abonado);

        if ($falta->esCero()) {
            return 'success';
        }

        return $falta->esNegativo() ? 'info' : 'warning';
    }

    // ── Las partes de la hoja ─────────────────────────────────────────

    /**
     * Solo los datos que tiene: una póliza vacía en una cuenta de contado
     * es un renglón que no dice nada.
     *
     * @return list<array{rotulo: string, valor: string, mono: bool}>
     */
    private static function datos(Cuenta $cuenta): array
    {
        $responsable = $cuenta->responsable;
        $anterior = $cuenta->cuentaAnterior;

        $datos = [
            ['rotulo' => 'Cuenta', 'valor' => $cuenta->numero, 'mono' => true],
            ['rotulo' => 'Encuentro', 'valor' => $cuenta->encuentro->numero, 'mono' => true],
            ['rotulo' => 'Ingreso', 'valor' => self::fecha($cuenta->encuentro->abierto_en), 'mono' => false],
            ['rotulo' => 'Alta', 'valor' => self::fecha($cuenta->congelada_en), 'mono' => false],
            ['rotulo' => 'Facturada', 'valor' => self::fecha($cuenta->cerrada_en), 'mono' => false],
            ['rotulo' => 'Anulada', 'valor' => self::fecha($cuenta->anulada_en), 'mono' => false],
            ['rotulo' => 'Póliza', 'valor' => (string) $cuenta->numero_poliza, 'mono' => true],
            ['rotulo' => 'Autorización', 'valor' => (string) $cuenta->numero_autorizacion, 'mono' => true],
            [
                'rotulo' => 'Responsable económico',
                'valor'  => $responsable instanceof Persona ? $responsable->nombreCompleto() : '',
                'mono'   => false,
            ],
            [
                'rotulo' => 'Viene de la cuenta',
                'valor'  => $anterior instanceof Cuenta ? $anterior->numero : '',
                'mono'   => true,
            ],
        ];

        return array_values(array_filter($datos, fn (array $dato): bool => $dato['valor'] !== ''));
    }

    /**
     * Las cinco cifras, siempre las cinco y en el mismo lugar: quien
     * revisa cuentas busca el número donde lo dejó la última vez.
     *
     * @return list<array{rotulo: string, valor: string, nota: ?string, tono: string}>
     */
    private static function cifras(Cuenta $cuenta, Decimal $total, Decimal $abonado): array
    {
        $seguro = $cuenta->saldoDeLaAseguradora();

        return [
            [
                'rotulo' => 'Total',
                'valor'  => $cuenta->saldo()->formateado(),
                'nota'   => $cuenta->lineas === 1 ? '1 renglón' : $cuenta->lineas.' renglones',
                'tono'   => 'neutro',
            ],
            [
                'rotulo' => 'Seguro paga',
                'valor'  => $seguro->esCero() ? '—' : $seguro->formateado(),
                'nota'   => $seguro->esCero() ? 'Lo paga el paciente' : null,
                'tono'   => 'neutro',
            ],
            [
                'rotulo' => 'Le toca al paciente',
                'valor'  => $cuenta->saldoDelPaciente()->formateado(),
                'nota'   => null,
                'tono'   => 'neutro',
            ],
            [
                'rotulo' => 'Abonado',
                'valor'  => $abonado->esCero() ? '—' : Monto::de($abonado)->formateado(),
                'nota'   => 'Recibos vivos',
                'tono'   => $abonado->esCero() ? 'neutro' : 'success',
            ],
            [
                'rotulo' => 'Falta',
                'valor'  => self::loQueFalta($cuenta->estado, $total, $abonado) ?? '—',
                'nota'   => 'Total menos lo abonado',
                'tono'   => self::colorDeLoQueFalta($cuenta->estado, $total, $abonado),
            ],
        ];
    }

    /**
     * Las facturas de la cuenta, con el enlace para imprimirlas solo si
     * quien mira puede ver facturas: el enlace sin permiso es un 403.
     *
     * @return list<array{numero: string, total: string, cuando: string, anulada: bool, enlace: ?string}>
     */
    private static function facturas(Cuenta $cuenta): array
    {
        $facturas = Factura::query()
            ->where('cuenta_id', $cuenta->id)
            ->orderBy('id')
            ->get(['id', 'sede_id', 'cuenta_id', 'numero', 'estado', 'total', 'emitida_en']);

        $lista = [];

        foreach ($facturas as $factura) {
            $lista[] = [
                'numero'  => $factura->numero,
                'total'   => self::lempiras($factura->total),
                'cuando'  => $factura->emitida_en->format('d/m/Y H:i'),
                'anulada' => $factura->estado === EstadoFactura::Anulada,
                'enlace'  => Gate::allows('view', $factura) ? route('facturas.imprimir', $factura) : null,
            ];
        }

        return $lista;
    }

    private static function fecha(?CarbonInterface $cuando): string
    {
        return $cuando === null ? '' : $cuando->format('d/m/Y · H:i');
    }

    private static function lempiras(string $monto): string
    {
        return Monto::de($monto)->formateado();
    }
}
