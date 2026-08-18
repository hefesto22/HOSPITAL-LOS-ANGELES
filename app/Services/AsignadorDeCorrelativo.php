<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\SihlaException;
use App\Models\Correlativo;
use App\Models\Sede;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Entrega el siguiente número de una secuencia, sin repetir jamás.
 *
 * Única puerta de escritura de `correlativos` (§11: los Services son la
 * única puerta de escritura del dominio).
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO SE GARANTIZA QUE DOS ADMISIONES SIMULTÁNEAS NO SAQUEN EL MISMO
 * NÚMERO DE EXPEDIENTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Son las 3 de la mañana, entran dos pacientes a la vez y dos personas de
 * admisión aprietan "registrar" en el mismo segundo. Si los dos obtienen
 * `EXP-HLA-2026-00000042`, el hospital tiene dos personas distintas con el
 * mismo expediente — y eso no se arregla después, se arrastra.
 *
 * La serialización se logra con `lockForUpdate()` sobre la FILA del
 * contador, dentro de una transacción:
 *
 *   1. `SELECT ... FOR UPDATE` bloquea esa fila. La segunda transacción
 *      queda esperando en el `SELECT`, no lee un valor viejo.
 *   2. Se incrementa y se guarda.
 *   3. Al hacer COMMIT se suelta, y la segunda lee el valor YA
 *      incrementado.
 *
 * El bloqueo es de UNA fila: `(sede, tipo, año)`. Emergencia sacando
 * expedientes no bloquea a laboratorio sacando órdenes, ni a la sede 2
 * (§9.H7: nunca locks globales).
 *
 * ⚠️ El número se consume aunque la operación que lo pidió falle después.
 * Eso es DELIBERADO: un correlativo con huecos es normal y explicable; uno
 * con repetidos es un problema de identidad. Si hiciera falta justificar
 * los huecos, se registran aparte — nunca se reutiliza el número.
 */
final class AsignadorDeCorrelativo
{
    /**
     * Devuelve el siguiente número formateado: `EXP-HLA-2026-00000042`.
     *
     * ⚠️ Debe llamarse DENTRO de la transacción de la operación que lo
     * consume. Si se llama fuera y la operación falla, el número se pierde
     * igual — que es aceptable — pero se pierde la atomicidad entre el
     * número y el registro que lo lleva.
     */
    public function siguiente(Sede $sede, TipoCorrelativo $tipo): string
    {
        $anio = $tipo->reiniciaAnualmente() ? (int) now()->year : null;

        $numero = DB::transaction(function () use ($sede, $tipo, $anio): int {
            $contador = Correlativo::query()
                ->where('sede_id', $sede->getKey())
                ->where('tipo', $tipo->value)
                ->when(
                    $anio === null,
                    fn ($q) => $q->whereNull('anio'),
                    fn ($q) => $q->where('anio', $anio),
                )
                ->lockForUpdate()
                ->first();

            /*
             * Si el contador no existe todavía, se crea. El `create` puede
             * chocar con otra transacción que lo esté creando al mismo
             * tiempo; el índice único lo impide y reintentamos leyendo con
             * lock. Sin esto, el PRIMER registro de cada sede sería
             * exactamente el caso que se duplica.
             */
            if (! $contador instanceof Correlativo) {
                try {
                    $contador = Correlativo::query()->create([
                        'sede_id'       => $sede->getKey(),
                        'tipo'          => $tipo->value,
                        'anio'          => $anio,
                        'ultimo_numero' => 0,
                    ]);
                } catch (QueryException) {
                    $contador = Correlativo::query()
                        ->where('sede_id', $sede->getKey())
                        ->where('tipo', $tipo->value)
                        ->when(
                            $anio === null,
                            fn ($q) => $q->whereNull('anio'),
                            fn ($q) => $q->where('anio', $anio),
                        )
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (! $contador instanceof Correlativo) {
                throw new SihlaException(
                    "No se pudo obtener el contador de {$tipo->etiqueta()} para la sede {$sede->codigo}."
                );
            }

            $contador->ultimo_numero++;
            $contador->save();

            return $contador->ultimo_numero;
        });

        return $this->formatear($sede, $tipo, $anio, $numero);
    }

    /**
     * Formato del §10.3: `{PREFIJO}-{SEDE}-{AÑO}-{#####}`.
     *
     * Las secuencias que no reinician omiten el año, porque incluirlo
     * sugeriría que el contador vuelve a empezar — y el expediente no
     * vuelve a empezar nunca.
     */
    public function formatear(Sede $sede, TipoCorrelativo $tipo, ?int $anio, int $numero): string
    {
        $partes = array_filter([
            $tipo->prefijo(),
            $sede->codigo,
            $anio === null ? null : (string) $anio,
            str_pad((string) $numero, $tipo->longitud(), '0', STR_PAD_LEFT),
        ]);

        return implode('-', $partes);
    }

    /**
     * Cuántos números lleva consumidos esta secuencia. Solo lectura: sirve
     * para reportes y para el tab "Estado" de los Resources.
     */
    public function consumidos(Sede $sede, TipoCorrelativo $tipo, ?int $anio = null): int
    {
        $anio ??= $tipo->reiniciaAnualmente() ? (int) now()->year : null;

        return (int) Correlativo::query()
            ->where('sede_id', $sede->getKey())
            ->where('tipo', $tipo->value)
            ->when(
                $anio === null,
                fn ($q) => $q->whereNull('anio'),
                fn ($q) => $q->where('anio', $anio),
            )
            ->value('ultimo_numero');
    }
}
