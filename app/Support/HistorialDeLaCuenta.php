<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\TipoDeMovimiento;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL HISTORIAL DE LA CUENTA, CONTADO COMO PASÓ (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La bitácora de antes pintaba las filas de `cargos` tal cual: cambiar
 * el descuento del paquete dejaba CUATRO renglones —«Reversa −1,000»,
 * el viejo tachado, «Reversa −925.60», el nuevo— con «· -1 · Reversa»
 * de detalle. Todo era cierto y nada se entendía.
 *
 * Acá se cuentan como lo que fueron para quien mira:
 *
 *   · AGREGÓ — un cargo que se asentó. Si después se quitó o se cambió,
 *     el monto queda tachado y una nota dice cuándo.
 *   · QUITÓ — una reversa sola, con su motivo.
 *   · CAMBIÓ — una reversa seguida, en el mismo momento y por la misma
 *     persona, de un renglón nuevo del mismo ítem: es lo que deja
 *     «rehacer» (anular + asentar) al aplicar un descuento o al
 *     actualizar el paquete. Se lee «de L 1,000.00 a L 925.60».
 *   · COBRÓ / FACTURÓ / ANULÓ — la caja, que antes iba en un bloque
 *     aparte y ahora va en el mismo hilo, en el orden en que pasó.
 *
 * 🔴 Es solo lectura. Las filas de la base no se tocan ni se agrupan:
 * un «cambió» es la reversa y el renglón nuevo, y los dos siguen en
 * «Cargos» con su id para quien los quiera ver de a uno.
 */
final class HistorialDeLaCuenta
{
    /**
     * Cuánto pueden separarse la reversa y el renglón nuevo para leerse
     * como UN cambio.
     *
     * Rehacer corre en una sola transacción y las dos filas salen con
     * milisegundos de diferencia; cinco segundos dan aire sin que dos
     * acciones de verdad distintas se confundan (quitar algo y volver a
     * escanearlo un minuto después sigue saliendo como quitó + agregó).
     */
    private const SEGUNDOS_DE_UN_CAMBIO = 5;

    /**
     * Cuántos cargos se leen. Una cuenta de hospitalización larga pasa de
     * mil renglones; el historial es para el cambio de turno, no para la
     * auditoría completa, que vive en «Cargos».
     */
    private const TOPE = 300;

    /**
     * Todo lo que pasó en la cuenta, lo más reciente primero.
     *
     * @return list<MovimientoDelHistorial>
     */
    public function de(Cuenta $cuenta): array
    {
        $movimientos = [...$this->deLosCargos($cuenta), ...$this->deLaCaja($cuenta)];

        usort(
            $movimientos,
            static fn (MovimientoDelHistorial $uno, MovimientoDelHistorial $otro): int => ($otro->cuando <=> $uno->cuando)
                ?: ($otro->orden <=> $uno->orden),
        );

        return $movimientos;
    }

    /**
     * Los movimientos agrupados por día, con la marca de cuáles quedan
     * escondidos detrás de «Ver los anteriores».
     *
     * @param list<MovimientoDelHistorial> $movimientos ya ordenados, el más reciente primero
     *
     * @return list<array{dia: string, oculto: bool, movimientos: list<array{movimiento: MovimientoDelHistorial, oculto: bool}>}>
     */
    public function porDia(array $movimientos, int $visibles): array
    {
        $dias = [];

        /*
         * Vienen ordenados, así que los del mismo día ya están juntos: el
         * arreglo por fecha conserva el orden de llegada.
         */
        foreach ($movimientos as $posicion => $movimiento) {
            $clave = $movimiento->cuando->toDateString();

            if (! isset($dias[$clave])) {
                $dias[$clave] = [
                    'dia'         => self::nombreDelDia($movimiento->cuando),
                    'oculto'      => $posicion >= $visibles,
                    'movimientos' => [],
                ];
            }

            $dias[$clave]['movimientos'][] = ['movimiento' => $movimiento, 'oculto' => $posicion >= $visibles];
        }

        return array_values($dias);
    }

    // ── Los cargos ────────────────────────────────────────────────────

    /**
     * @return list<MovimientoDelHistorial>
     */
    private function deLosCargos(Cuenta $cuenta): array
    {
        $cargos = $this->ultimosCargos($cuenta);

        /*
         * Cada reversa con el renglón que la siguió, si lo hubo: eso es
         * un cambio. Se recorre en orden y cada renglón nuevo se usa una
         * sola vez.
         *
         *   $nuevoDe: id de la reversa ⇒ el renglón nuevo
         *   $usados:  id de los renglones que ya son parte de un cambio
         */
        $nuevoDe = [];
        $usados = [];

        foreach ($cargos as $reversa) {
            if ($reversa->estado !== EstadoCargo::Anulacion) {
                continue;
            }

            foreach ($cargos as $nuevo) {
                if ($nuevo->id <= $reversa->id
                    || isset($usados[$nuevo->id])
                    || $nuevo->estado === EstadoCargo::Anulacion
                    || $nuevo->item_id !== $reversa->item_id
                    || $nuevo->created_by !== $reversa->created_by
                    || abs($nuevo->registrado_en->diffInSeconds($reversa->registrado_en)) > self::SEGUNDOS_DE_UN_CAMBIO
                ) {
                    continue;
                }

                $nuevoDe[$reversa->id] = $nuevo;
                $usados[$nuevo->id] = true;

                break;
            }
        }

        /*
         * Qué le pasó después a cada renglón que dejó de contar: se quitó
         * o se cambió, y cuándo. Es la nota que va debajo del tachado
         * (id del original ⇒ nota).
         */
        $despues = [];

        foreach ($cargos as $reversa) {
            if ($reversa->estado === EstadoCargo::Anulacion && $reversa->revierte_a_id !== null) {
                $verbo = isset($nuevoDe[$reversa->id]) ? 'Se cambió' : 'Se quitó';
                $despues[$reversa->revierte_a_id] = $verbo.' '.self::cuando($reversa->registrado_en);
            }
        }

        $movimientos = [];

        foreach ($cargos as $cargo) {
            if ($cargo->estado === EstadoCargo::Anulacion) {
                $nuevo = $nuevoDe[$cargo->id] ?? null;

                $movimientos[] = $nuevo instanceof Cargo
                    ? $this->cambio($cargo, $nuevo, $despues)
                    : $this->quito($cargo);

                continue;
            }

            if (isset($usados[$cargo->id])) {
                continue;
            }

            $movimientos[] = $this->agrego($cargo, $despues);
        }

        return $movimientos;
    }

    /**
     * Los últimos trescientos, del más viejo al más nuevo (el orden en que
     * hay que leerlos para encontrar los cambios).
     *
     * ⚠️ Los ÚLTIMOS, no los primeros: `Cuenta::bitacora()` corta por el
     * principio, y en una hospitalización larga el historial se quedaba
     * sin lo de hoy, que es justo lo que se viene a buscar.
     *
     * @return list<Cargo>
     */
    private function ultimosCargos(Cuenta $cuenta): array
    {
        return array_values($cuenta->cargos()
            ->with(['createdBy:id,name'])
            ->orderByDesc('registrado_en')
            ->orderByDesc('id')
            ->limit(self::TOPE)
            ->get()
            ->reverse()
            ->all());
    }

    /**
     * @param array<int, string> $despues
     */
    private function agrego(Cargo $cargo, array $despues): MovimientoDelHistorial
    {
        $detalle = [];

        $cantidad = self::cantidad($cargo->cantidad);

        if ($cantidad !== '1') {
            $detalle[] = $cantidad.' × '.Monto::de(Decimal::de($cargo->precio_unitario)->redondeado(2))->formateado();
        }

        $descuento = Decimal::de($cargo->descuento_legal)->sumar($cargo->descuento_comercial);

        if (! $descuento->esCero()) {
            $detalle[] = 'descuento '.Monto::de($descuento->redondeado(2))->formateado();
        }

        if ($cargo->es_tardio) {
            $detalle[] = 'cargo tardío';
        }

        return new MovimientoDelHistorial(
            cuando: $cargo->registrado_en,
            tipo: TipoDeMovimiento::Agrego,
            titulo: $cargo->texto,
            quien: self::autor($cargo),
            detalle: $detalle === [] ? null : implode(' · ', $detalle),
            monto: $cargo->totalParaMostrar(),
            yaNoCuenta: $cargo->estado === EstadoCargo::Anulado || $cargo->estado === EstadoCargo::Trasladado,
            nota: self::notaDe($cargo, $despues),
            orden: $cargo->id,
        );
    }

    private function quito(Cargo $reversa): MovimientoDelHistorial
    {
        return new MovimientoDelHistorial(
            cuando: $reversa->registrado_en,
            tipo: TipoDeMovimiento::Quito,
            titulo: $reversa->texto,
            quien: self::autor($reversa),
            motivo: $reversa->motivo_anulacion,
            monto: $reversa->totalParaMostrar(),
            orden: $reversa->id,
        );
    }

    /**
     * @param array<int, string> $despues
     */
    private function cambio(Cargo $reversa, Cargo $nuevo, array $despues): MovimientoDelHistorial
    {
        $detalle = 'de '.$reversa->montoTotal()->formateado().' a '.$nuevo->montoTotal()->formateado();

        $antes = self::cantidad(Decimal::de($reversa->cantidad)->por('-1')->exacto());
        $ahora = self::cantidad($nuevo->cantidad);

        if ($antes !== $ahora) {
            $detalle .= ' · cantidad de '.$antes.' a '.$ahora;
        }

        return new MovimientoDelHistorial(
            cuando: $reversa->registrado_en,
            tipo: TipoDeMovimiento::Cambio,
            titulo: $nuevo->texto,
            quien: self::autor($reversa),
            detalle: $detalle,
            motivo: $reversa->motivo_anulacion,
            monto: $nuevo->totalParaMostrar(),
            yaNoCuenta: $nuevo->estado === EstadoCargo::Anulado || $nuevo->estado === EstadoCargo::Trasladado,
            nota: self::notaDe($nuevo, $despues),
            orden: $reversa->id,
        );
    }

    /**
     * @param array<int, string> $despues
     */
    private static function notaDe(Cargo $cargo, array $despues): ?string
    {
        return match ($cargo->estado) {
            EstadoCargo::Anulado    => $despues[$cargo->id] ?? 'Se quitó después',
            EstadoCargo::Trasladado => 'Pasó a otra cuenta',
            EstadoCargo::Facturado  => 'Facturado',
            default                 => null,
        };
    }

    // ── La caja ───────────────────────────────────────────────────────

    /**
     * @return list<MovimientoDelHistorial>
     */
    private function deLaCaja(Cuenta $cuenta): array
    {
        $movimientos = [];

        foreach ($cuenta->bitacoraDeCaja() as $posicion => $linea) {
            $detalle = array_values(array_filter(
                [$linea['medios'], $linea['nota']],
                static fn (?string $parte): bool => $parte !== null && trim($parte) !== '',
            ));

            $movimientos[] = new MovimientoDelHistorial(
                cuando: $linea['cuando'],
                tipo: $linea['tipo'],
                titulo: $linea['titulo'],
                quien: $linea['quien'],
                detalle: $detalle === [] ? null : implode(' · ', $detalle),
                motivo: $linea['motivo'],
                monto: $linea['monto'] === '' ? null : $linea['monto'],
                yaNoCuenta: $linea['tachado'],
                nota: $linea['tachado'] ? 'Se anuló después' : null,
                orden: $posicion,
            );
        }

        return $movimientos;
    }

    // ── Formato ───────────────────────────────────────────────────────

    /**
     * Quien asentó la fila. `getRelationValue` y no la propiedad: un
     * cargo sembrado por un import no tiene autor, y la relación viene
     * cargada desde `bitacora()`.
     */
    private static function autor(Cargo $cargo): string
    {
        $autor = $cargo->getRelationValue('createdBy');

        return $autor instanceof User ? $autor->name : 'Sistema';
    }

    /**
     * «1», «2.5», «-1» — sin los ceros de la escala de la base.
     */
    private static function cantidad(string $cantidad): string
    {
        return str_contains($cantidad, '.') ? rtrim(rtrim($cantidad, '0'), '.') : $cantidad;
    }

    /**
     * «a las 03:18» si fue hoy; «el 10/10 a las 03:18» si no.
     */
    private static function cuando(CarbonInterface $momento): string
    {
        return $momento->isToday()
            ? 'a las '.$momento->format('H:i')
            : 'el '.$momento->format('d/m').' a las '.$momento->format('H:i');
    }

    private static function nombreDelDia(CarbonInterface $dia): string
    {
        if ($dia->isToday()) {
            return 'Hoy';
        }

        if ($dia->isYesterday()) {
            return 'Ayer';
        }

        $texto = $dia->translatedFormat($dia->isCurrentYear() ? 'l j \d\e F' : 'l j \d\e F \d\e Y');

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
