<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Especialidad;
use App\Models\Item;
use App\Models\Medico;
use App\Support\Periodo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Los honorarios facturados de un período: cuánto le toca a cada médico y
 * a cada turno o área del personal, de qué pagadores, y renglón por
 * renglón.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA FUENTE PARA LA PANTALLA Y EL PAPEL (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio pidió poder imprimir el reporte de un médico y uno general
 * con todos, de la A a la Z. La consulta vivía adentro de la página
 * «Honorarios por médico»; si los reportes armaran la suya, el día que
 * una de las dos cambie la pantalla diría L 14,200 y el papel que se le
 * entrega al médico L 13,200. Por eso la consulta vive acá y la usan la
 * página y las rutas de impresión.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CUENTA LO FACTURADO EN EL PERÍODO, Y NADA MÁS (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «solo debería guardarse lo que sí se facturó en ese mes, no
 * mezclarse mes a mes. Si hizo 10 honorarios de 1000 se guardan esos 10;
 * si se anula una factura en la que estaba un honorario, se le restan
 * 1000».
 *
 * La fuente son las FACTURAS EMITIDAS del período —por su fecha de
 * operación— y, de cada una, sus renglones de honorario:
 *
 *   · Un honorario cargado pero sin facturar todavía no cuenta.
 *   · Cuenta en el mes en que se facturó, no en el que se cargó.
 *   · Si la factura se anula, sale entera del mes al que pertenecía y
 *     nunca aparece un negativo en otro.
 *
 * Un cargo anulado o su reversa nunca entran a una factura (el emisor
 * solo toma lo pendiente y cobrable), así que acá no hay negativos.
 *
 * ES LO FACTURADO, NO LO COBRADO NI LO QUE SE LE PAGA A NADIE. Si algún
 * día el hospital retiene una parte o paga cuando el seguro paga, eso es
 * una columna más, calculada sobre esto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 TRES DESTINOS, Y ENTRE LOS TRES ESTÁ TODO (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada renglón va a uno solo:
 *
 *   · A UN MÉDICO: tiene médico y su ítem no es del personal.
 *   · A UN TURNO O ÁREA: el ítem es un honorario del personal —turnos de
 *     enfermería, circulante, instrumentista—. Se agrupa por el ÍTEM,
 *     que es el que dice el turno («pertenece a X turno, sin
 *     enfermeras», Mauricio). Si por error trae un médico, igual va al
 *     turno: así lo ya cargado aparece solo donde corresponde.
 *   · SIN MÉDICO: un honorario de médico que se cargó sin elegirlo —antes
 *     de que fuera obligatorio—. No es de nadie y no se inventa a quién;
 *     se muestra aparte para que el total cuadre y alguien lo revise.
 *
 * Lo demás —un servicio o un medicamento sin médico— no es honorario y no
 * entra.
 *
 * @phpstan-type Cifras array{cuantos: int, importe: Decimal}
 * @phpstan-type FichaDelMedico array{id: int, nombre: string, especialidad: string, colegiacion: string, cuantos: int, importe: Decimal}
 * @phpstan-type GrupoDelPersonal array{id: int, nombre: string, item: string, cuantos: int, importe: Decimal}
 * @phpstan-type Pagador array{nombre: string, cuantos: int, importe: Decimal}
 * @phpstan-type Renglon array{fecha: string, factura: string, paciente: string, cuenta: string, honorario: string, pagador: string, cantidad: string, importe: string}
 * @phpstan-type FilaDelResumen array{nombre: string, especialidad: string, cuantos: int, total: string, parte: string}
 * @phpstan-type PagadorImpreso array{nombre: string, cuantos: int, total: string}
 * @phpstan-type Hoja array{tipo: string, nombre: string, especialidad: string, colegiacion: string, cuantos: int, total: string, pagadores: list<PagadorImpreso>, renglones: list<Renglon>}
 */
final class ReporteDeHonorarios
{
    /**
     * Lo más largo que imprime: un año, que es lo más que se liquida de
     * una vez. Un enlace con «del 2026 al 2036» arma un papel de decenas
     * de miles de renglones y se cae el navegador de quien lo abre.
     */
    public const DIAS_MAXIMOS = 366;

    /**
     * Los renglones de honorario de las facturas EMITIDAS en el período,
     * de los tres destinos (ver el encabezado). `soloDeMedicos()`,
     * `soloDelPersonal()` y `soloSinMedico()` los separan.
     *
     * ⚠️ El cruce con la factura va también por `cuenta_id`: `cargos`
     * está particionada y `factura_lineas.cargo_id` no tiene FK, así que
     * un id repetido de otra partición podría colarse (la misma guarda
     * que usa el emisor al anular).
     *
     * ⚠️ `encabezado = false`: el renglón que nombra una cirugía
     * desglosada no cobra nada y apunta a un cargo sin médico, pero se
     * excluye explícito.
     *
     * ⚠️ El ítem se cruza sin su scope de borrado: un honorario que se dio
     * de baja del catálogo sigue explicando lo que ya se facturó.
     *
     * @return Builder<Cargo>
     */
    public function facturados(string $desde, string $hasta): Builder
    {
        return Cargo::query()
            ->join('factura_lineas', 'factura_lineas.cargo_id', '=', 'cargos.id')
            ->join('facturas', function (JoinClause $union): void {
                $union->on('facturas.id', '=', 'factura_lineas.factura_id')
                    ->on('facturas.cuenta_id', '=', 'cargos.cuenta_id');
            })
            ->join('items', 'items.id', '=', 'cargos.item_id')
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->where('factura_lineas.encabezado', false)
            ->whereBetween('facturas.fecha_operacion', [$desde, $hasta])
            ->where(function ($consulta): void {
                $consulta->whereNotNull('cargos.medico_id')
                    ->orWhere('items.tipo', TipoItem::Honorario->value);
            });
    }

    /**
     * Los de un médico: tienen médico y su ítem no es del personal.
     *
     * @param Builder<Cargo> $consulta
     *
     * @return Builder<Cargo>
     */
    public static function soloDeMedicos(Builder $consulta): Builder
    {
        return $consulta
            ->whereNotNull('cargos.medico_id')
            ->where('items.cobra_el_honorario', QuienCobraElHonorario::Medico->value);
    }

    /**
     * Los de un turno o área: el ítem es un honorario del personal, con o
     * sin médico puesto.
     *
     * @param Builder<Cargo> $consulta
     *
     * @return Builder<Cargo>
     */
    public static function soloDelPersonal(Builder $consulta): Builder
    {
        return $consulta
            ->where('items.tipo', TipoItem::Honorario->value)
            ->where('items.cobra_el_honorario', QuienCobraElHonorario::Personal->value);
    }

    /**
     * Los honorarios de médico que se cargaron sin médico.
     *
     * @param Builder<Cargo> $consulta
     *
     * @return Builder<Cargo>
     */
    public static function soloSinMedico(Builder $consulta): Builder
    {
        return $consulta
            ->whereNull('cargos.medico_id')
            ->where('items.tipo', TipoItem::Honorario->value)
            ->where('items.cobra_el_honorario', QuienCobraElHonorario::Medico->value);
    }

    // ── Cifras ────────────────────────────────────────────────────────

    /**
     * Por médico: cuántos honorarios facturados y cuánto suman. Una sola
     * consulta agrupada.
     *
     * @return array<int, Cifras>
     */
    public function totalesPorMedico(string $desde, string $hasta): array
    {
        return self::agrupados(self::soloDeMedicos($this->facturados($desde, $hasta)), 'cargos.medico_id');
    }

    /**
     * Por turno o área del personal —por ítem—: cuántos y cuánto.
     *
     * @return array<int, Cifras>
     */
    public function totalesDelPersonal(string $desde, string $hasta): array
    {
        return self::agrupados(self::soloDelPersonal($this->facturados($desde, $hasta)), 'cargos.item_id');
    }

    /**
     * Los honorarios de médico sin médico del período.
     *
     * @return Cifras
     */
    public function sinMedico(string $desde, string $hasta): array
    {
        $fila = self::soloSinMedico($this->facturados($desde, $hasta))
            ->selectRaw('count(*) as cuantos, sum(cargos.total) as importe')
            ->toBase()
            ->first();

        return [
            'cuantos' => $fila === null ? 0 : (int) $fila->cuantos,
            'importe' => $fila === null ? Decimal::cero() : self::comoDecimal($fila->importe),
        ];
    }

    // ── Quiénes ───────────────────────────────────────────────────────

    /**
     * Los médicos de esos totales, de la A a la Z.
     *
     * 🔴 `withTrashed()`: un médico que se dio de baja después de cobrar
     * sigue teniendo sus honorarios en el período. Sin esto su plata
     * entraba al total de arriba y su tarjeta no aparecía: el total no
     * cuadraba con la suma de los médicos y nadie sabía por qué (§9.M33).
     *
     * @param array<int, Cifras> $totales
     *
     * @return list<FichaDelMedico>
     */
    public function medicos(array $totales): array
    {
        if ($totales === []) {
            return [];
        }

        $fichas = [];

        $medicos = Medico::withTrashed()
            ->with(['especialidad' => fn ($relacion) => $relacion->withTrashed()])
            ->whereIn('id', array_keys($totales))
            ->orderBy('nombre')
            ->orderBy('id')
            ->get();

        foreach ($medicos as $medico) {
            $id = (int) $medico->getKey();
            $especialidad = $medico->especialidad;

            $fichas[] = [
                'id'           => $id,
                'nombre'       => $medico->nombre,
                'especialidad' => $especialidad instanceof Especialidad ? $especialidad->nombre : '',
                'colegiacion'  => (string) $medico->colegiacion,
                'cuantos'      => $totales[$id]['cuantos'] ?? 0,
                'importe'      => $totales[$id]['importe'] ?? Decimal::cero(),
            ];
        }

        return $fichas;
    }

    /**
     * Los turnos y áreas del personal de esos totales, por nombre.
     *
     * `nombre` es como se lee —«ENFERMERIA TURNO C»— e `item` el nombre
     * entero del ítem, que es el que salió en la factura.
     *
     * @param array<int, Cifras> $totales
     *
     * @return list<GrupoDelPersonal>
     */
    public function delPersonal(array $totales): array
    {
        if ($totales === []) {
            return [];
        }

        $grupos = [];

        $items = Item::withTrashed()
            ->whereIn('id', array_keys($totales))
            ->orderBy('nombre')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $id = (int) $item->getKey();

            $grupos[] = [
                'id'      => $id,
                'nombre'  => self::nombreDelGrupo($item->nombre),
                'item'    => $item->nombre,
                'cuantos' => $totales[$id]['cuantos'] ?? 0,
                'importe' => $totales[$id]['importe'] ?? Decimal::cero(),
            ];
        }

        return $grupos;
    }

    // ── De qué pagadores ──────────────────────────────────────────────

    /**
     * De qué pagadores salieron los honorarios de cada médico, del que
     * más pagó al que menos.
     *
     * @return array<int, list<Pagador>>
     */
    public function porPagador(string $desde, string $hasta, ?int $medicoId = null): array
    {
        $consulta = self::soloDeMedicos($this->facturados($desde, $hasta));

        if ($medicoId !== null) {
            $consulta->where('cargos.medico_id', $medicoId);
        }

        return self::pagadores($consulta, 'cargos.medico_id');
    }

    /**
     * Lo mismo, por turno o área del personal.
     *
     * @return array<int, list<Pagador>>
     */
    public function porPagadorDelPersonal(string $desde, string $hasta, ?int $itemId = null): array
    {
        $consulta = self::soloDelPersonal($this->facturados($desde, $hasta));

        if ($itemId !== null) {
            $consulta->where('cargos.item_id', $itemId);
        }

        return self::pagadores($consulta, 'cargos.item_id');
    }

    // ── Renglón por renglón ───────────────────────────────────────────

    /**
     * Los renglones de cada médico, en el orden en que se facturaron.
     *
     * @return array<int, list<Renglon>>
     */
    public function renglones(string $desde, string $hasta, ?int $medicoId = null): array
    {
        $consulta = self::soloDeMedicos($this->facturados($desde, $hasta));

        if ($medicoId !== null) {
            $consulta->where('cargos.medico_id', $medicoId);
        }

        return self::renglonesDe($consulta, 'medico_id');
    }

    /**
     * Los renglones de cada turno o área del personal.
     *
     * @return array<int, list<Renglon>>
     */
    public function renglonesDelPersonal(string $desde, string $hasta, ?int $itemId = null): array
    {
        $consulta = self::soloDelPersonal($this->facturados($desde, $hasta));

        if ($itemId !== null) {
            $consulta->where('cargos.item_id', $itemId);
        }

        return self::renglonesDe($consulta, 'item_id');
    }

    // ── Para el papel ─────────────────────────────────────────────────

    /**
     * Todo lo que lleva el reporte impreso.
     *
     * Con un médico o un turno: su hoja, aunque no tenga honorarios en el
     * período —el papel dice «sin honorarios» en vez de dar un error—.
     * Sin ninguno: el general, con los médicos de la A a la Z, después los
     * turnos y áreas del personal, y lo que quedó sin médico; con el
     * detalle, una hoja por médico y por turno, en ese orden.
     *
     * @return array{
     *     etiqueta: string,
     *     total: string,
     *     cuantos: int,
     *     resumen: list<FilaDelResumen>,
     *     totalMedicos: string,
     *     cuantosMedicos: int,
     *     personal: list<FilaDelResumen>,
     *     totalPersonal: string,
     *     cuantosPersonal: int,
     *     sinMedico: array{cuantos: int, total: string}|null,
     *     hojas: list<Hoja>
     * }
     */
    public function paraImprimir(string $desde, string $hasta, ?Medico $medico, bool $conDetalle, ?Item $turno = null): array
    {
        $deUnaHoja = $medico instanceof Medico || $turno instanceof Item;

        $porMedico = $turno instanceof Item ? [] : $this->totalesPorMedico($desde, $hasta);
        $porTurno = $medico instanceof Medico ? [] : $this->totalesDelPersonal($desde, $hasta);
        $sinMedico = $deUnaHoja ? ['cuantos' => 0, 'importe' => Decimal::cero()] : $this->sinMedico($desde, $hasta);

        if ($medico instanceof Medico) {
            $porMedico = self::soloEste($porMedico, (int) $medico->getKey());
        }

        if ($turno instanceof Item) {
            $porTurno = self::soloEste($porTurno, (int) $turno->getKey());
        }

        $fichas = $this->medicos($porMedico);
        $grupos = $this->delPersonal($porTurno);

        $totalMedicos = self::suma($porMedico);
        $totalPersonal = self::suma($porTurno);
        $total = $totalMedicos['importe']->sumar($totalPersonal['importe'])->sumar($sinMedico['importe']);

        $resumen = [];

        foreach ($fichas as $ficha) {
            $resumen[] = [
                'nombre'       => $ficha['nombre'],
                'especialidad' => $ficha['especialidad'],
                'cuantos'      => $ficha['cuantos'],
                'total'        => self::formatear($ficha['importe']),
                'parte'        => self::parte($ficha['importe'], $total),
            ];
        }

        $personal = [];

        foreach ($grupos as $grupo) {
            $personal[] = [
                'nombre'       => $grupo['nombre'],
                'especialidad' => $grupo['item'],
                'cuantos'      => $grupo['cuantos'],
                'total'        => self::formatear($grupo['importe']),
                'parte'        => self::parte($grupo['importe'], $total),
            ];
        }

        $hojas = [];

        if ($deUnaHoja || $conDetalle) {
            $soloElMedico = $medico instanceof Medico ? (int) $medico->getKey() : null;
            $soloElTurno = $turno instanceof Item ? (int) $turno->getKey() : null;

            if ($fichas !== []) {
                $pagadores = $this->porPagador($desde, $hasta, $soloElMedico);
                $renglones = $this->renglones($desde, $hasta, $soloElMedico);

                foreach ($fichas as $ficha) {
                    $suyos = [];

                    foreach ($renglones[$ficha['id']] ?? [] as $renglon) {
                        $renglon['honorario'] = self::sinElNombre($renglon['honorario'], $ficha['nombre']);
                        $suyos[] = $renglon;
                    }

                    $hojas[] = [
                        'tipo'         => 'medico',
                        'nombre'       => $ficha['nombre'],
                        'especialidad' => $ficha['especialidad'],
                        'colegiacion'  => $ficha['colegiacion'],
                        'cuantos'      => $ficha['cuantos'],
                        'total'        => self::formatear($ficha['importe']),
                        'pagadores'    => self::pagadoresImpresos($pagadores[$ficha['id']] ?? []),
                        'renglones'    => $suyos,
                    ];
                }
            }

            if ($grupos !== []) {
                $pagadores = $this->porPagadorDelPersonal($desde, $hasta, $soloElTurno);
                $renglones = $this->renglonesDelPersonal($desde, $hasta, $soloElTurno);

                foreach ($grupos as $grupo) {
                    $hojas[] = [
                        'tipo'         => 'personal',
                        'nombre'       => $grupo['nombre'],
                        'especialidad' => $grupo['item'],
                        'colegiacion'  => '',
                        'cuantos'      => $grupo['cuantos'],
                        'total'        => self::formatear($grupo['importe']),
                        'pagadores'    => self::pagadoresImpresos($pagadores[$grupo['id']] ?? []),
                        'renglones'    => $renglones[$grupo['id']] ?? [],
                    ];
                }
            }
        }

        return [
            'etiqueta'        => Periodo::etiqueta($desde, $hasta),
            'total'           => self::formatear($total),
            'cuantos'         => $totalMedicos['cuantos'] + $totalPersonal['cuantos'] + $sinMedico['cuantos'],
            'resumen'         => $resumen,
            'totalMedicos'    => self::formatear($totalMedicos['importe']),
            'cuantosMedicos'  => $totalMedicos['cuantos'],
            'personal'        => $personal,
            'totalPersonal'   => self::formatear($totalPersonal['importe']),
            'cuantosPersonal' => $totalPersonal['cuantos'],
            'sinMedico'       => $sinMedico['cuantos'] > 0
                ? ['cuantos' => $sinMedico['cuantos'], 'total' => self::formatear($sinMedico['importe'])]
                : null,
            'hojas' => $hojas,
        ];
    }

    // ── Formato ───────────────────────────────────────────────────────

    /**
     * Una suma lista para mostrar.
     *
     * No puede dar negativa —ni un cargo anulado ni su reversa entran a
     * una factura—, pero si algún día diera, sale con signo en vez de
     * reventar: `Monto` no admite negativos.
     */
    public static function formatear(Decimal $valor): string
    {
        if ($valor->esNegativo()) {
            return '− '.Monto::de(Decimal::cero()->restar($valor)->redondeado(2))->formateado();
        }

        return Monto::de($valor->redondeado(2))->formateado();
    }

    /**
     * Qué parte del total es: «29 %». Con el total en cero, una raya: un
     * porcentaje de nada no dice nada.
     */
    public static function parte(Decimal $importe, Decimal $total): string
    {
        if (! $total->mayorQue('0')) {
            return '—';
        }

        return $importe->entre($total)->comoPorcentaje(0);
    }

    /**
     * Cómo se lee un turno o área: el nombre del ítem sin el «HONORARIOS»
     * de adelante, que en una tarjeta que ya dice «Enfermería y personal»
     * sobra. «HONORARIOS ENFERMERIA TURNO C» es ENFERMERIA TURNO C.
     */
    public static function nombreDelGrupo(string $nombreDelItem): string
    {
        $corto = trim((string) preg_replace('/^HONORARIOS?\s+/u', '', mb_strtoupper(trim($nombreDelItem))));

        return $corto === '' ? $nombreDelItem : $corto;
    }

    /**
     * `sum()` devuelve lo que le da el driver —int, float o string—.
     */
    public static function comoDecimal(mixed $suma): Decimal
    {
        if ($suma instanceof Decimal) {
            return $suma;
        }

        if (is_string($suma) && is_numeric($suma)) {
            return Decimal::de($suma);
        }

        if (is_int($suma)) {
            return Decimal::de($suma);
        }

        if (is_float($suma)) {
            return Decimal::deFloat($suma);
        }

        return Decimal::cero();
    }

    /**
     * El texto del honorario sin el nombre del médico. En SU hoja,
     * «CONSULTA EXTERNA GINECOLOGÍA · ANA MEJÍA» repetía su nombre en cada
     * renglón y cada renglón ocupaba tres líneas. Solo se quita el pedazo
     * que es exactamente su nombre; el texto congelado del cargo no se
     * toca, y si no queda nada se deja como estaba.
     */
    public static function sinElNombre(string $texto, string $nombre): string
    {
        $nombre = mb_strtoupper(trim($nombre));

        $partes = array_filter(
            array_map(trim(...), explode('·', $texto)),
            fn (string $parte): bool => $parte !== '' && mb_strtoupper($parte) !== $nombre,
        );

        return $partes === [] ? $texto : implode(' · ', $partes);
    }

    public static function fechaCorta(mixed $fecha): string
    {
        $limpia = is_string($fecha) ? Periodo::fechaValida(mb_substr($fecha, 0, 10)) : null;

        return $limpia === null ? '' : substr($limpia, 8, 2).'/'.substr($limpia, 5, 2).'/'.substr($limpia, 0, 4);
    }

    // ── Por dentro ────────────────────────────────────────────────────

    /**
     * Cuántos y cuánto, agrupado por una columna.
     *
     * @param Builder<Cargo> $consulta
     *
     * @return array<int, Cifras>
     */
    private static function agrupados(Builder $consulta, string $columna): array
    {
        /*
         * La columna va por `select()` y lo demás por `selectRaw()`: lo que
         * entra crudo al SQL tiene que ser un texto fijo del código, nunca
         * uno armado (Larastan lo exige, y es lo que cierra la puerta a una
         * inyección el día que la columna venga de otro lado).
         */
        $filas = $consulta
            ->select([$columna.' as clave'])
            ->selectRaw('count(*) as cuantos, sum(cargos.total) as importe')
            ->groupBy($columna)
            ->toBase()
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $totales[(int) $fila->clave] = [
                'cuantos' => (int) $fila->cuantos,
                'importe' => self::comoDecimal($fila->importe),
            ];
        }

        return $totales;
    }

    /**
     * @param Builder<Cargo> $consulta
     *
     * @return array<int, list<Pagador>>
     */
    private static function pagadores(Builder $consulta, string $columna): array
    {
        $filas = $consulta
            ->leftJoin('convenios', 'convenios.id', '=', 'cargos.convenio_id')
            ->select([$columna.' as clave'])
            ->selectRaw("coalesce(max(convenios.nombre), '—') as pagador, count(*) as cuantos, sum(cargos.total) as importe")
            ->groupBy($columna, 'cargos.convenio_id')
            ->orderByDesc('importe')
            ->orderBy('pagador')
            ->toBase()
            ->get();

        $pagadores = [];

        foreach ($filas as $fila) {
            $pagadores[(int) $fila->clave][] = [
                'nombre'  => (string) $fila->pagador,
                'cuantos' => (int) $fila->cuantos,
                'importe' => self::comoDecimal($fila->importe),
            ];
        }

        return $pagadores;
    }

    /**
     * Renglón por renglón, agrupado por un atributo del cargo, en el orden
     * en que se facturó.
     *
     * Sin columnas nombradas en el `with`: una que falte no da error,
     * llega NULL y el papel miente en silencio (§9.M6). Y con
     * `withTrashed()`: un convenio o un paciente dado de baja después no
     * borra el renglón de un honorario que sí se facturó.
     *
     * Por tandas de 500 (`lazy`): un año entero son miles de cargos con su
     * cuenta y su paciente.
     *
     * @param Builder<Cargo> $consulta
     *
     * @return array<int, list<Renglon>>
     */
    private static function renglonesDe(Builder $consulta, string $atributo): array
    {
        $consulta
            ->select([
                'cargos.*',
                'facturas.numero as factura_numero',
                'facturas.fecha_operacion as facturada_el',
            ])
            ->with([
                'convenio'                 => fn ($relacion) => $relacion->withTrashed(),
                'cuenta.encuentro.persona' => fn ($relacion) => $relacion->withTrashed(),
            ])
            ->orderBy('facturas.fecha_operacion')
            ->orderBy('facturas.numero')
            ->orderBy('cargos.id');

        $renglones = [];

        foreach ($consulta->lazy(500) as $cargo) {
            $cuenta = $cargo->cuenta;
            $convenio = $cargo->convenio;

            $renglones[(int) $cargo->getAttribute($atributo)][] = [
                'fecha'     => self::fechaCorta($cargo->getAttribute('facturada_el')),
                'factura'   => (string) $cargo->getAttribute('factura_numero'),
                'paciente'  => $cuenta instanceof Cuenta ? $cuenta->encuentro->persona->nombreCompleto() : '—',
                'cuenta'    => $cuenta instanceof Cuenta ? $cuenta->numero : '',
                'honorario' => $cargo->texto,
                'pagador'   => $convenio instanceof Convenio ? $convenio->nombre : '—',
                'cantidad'  => rtrim(rtrim($cargo->cantidad, '0'), '.'),
                'importe'   => $cargo->totalParaMostrar(),
            ];
        }

        return $renglones;
    }

    /**
     * @param list<Pagador> $pagadores
     *
     * @return list<PagadorImpreso>
     */
    private static function pagadoresImpresos(array $pagadores): array
    {
        $impresos = [];

        foreach ($pagadores as $pagador) {
            $impresos[] = [
                'nombre'  => $pagador['nombre'],
                'cuantos' => $pagador['cuantos'],
                'total'   => self::formatear($pagador['importe']),
            ];
        }

        return $impresos;
    }

    /**
     * Las cifras de uno solo; en cero si no tiene nada en el período.
     *
     * @param array<int, Cifras> $totales
     *
     * @return array<int, Cifras>
     */
    private static function soloEste(array $totales, int $id): array
    {
        return [$id => $totales[$id] ?? ['cuantos' => 0, 'importe' => Decimal::cero()]];
    }

    /**
     * @param array<int, Cifras> $filas
     *
     * @return Cifras
     */
    private static function suma(array $filas): array
    {
        $importe = Decimal::cero();
        $cuantos = 0;

        foreach ($filas as $fila) {
            $importe = $importe->sumar($fila['importe']);
            $cuantos += $fila['cuantos'];
        }

        return ['cuantos' => $cuantos, 'importe' => $importe];
    }
}
