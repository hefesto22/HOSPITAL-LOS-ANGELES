<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\MargenNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\MargenObjetivo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cambiar el margen es cerrar el anterior y abrir el nuevo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO ES UN `UPDATE`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Editar el porcentaje de la fila vigente sería un renglón menos de
 * código y borraría la única respuesta que importa: **por qué ese
 * producto se vendía a ese precio en marzo**. Un precio que no se puede
 * explicar es un precio que no se puede defender ante el SAR, ante un
 * paciente que reclama, o ante Mauricio mismo dos años después.
 *
 * Entonces el cambio son dos escrituras dentro de una transacción:
 *
 *   1. al margen vigente se le pone `vigencia_hasta` = el día anterior,
 *   2. se inserta el nuevo desde la fecha elegida.
 *
 * Los dos rangos quedan pegados y sin traslape, que es justo lo que la
 * restricción de exclusión de la tabla exige.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO HACIA ADELANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si ya existe un margen que arranca ese día o después, esto se niega.
 * Meter una fila en medio del historial obligaría a recortar rangos hacia
 * los dos lados, y el precio de una venta que ya ocurrió pasaría a
 * explicarse con una política que ese día no existía.
 */
final class FijadorDeMargenObjetivo
{
    /**
     * @param TipoItem|null $tipo nulo = el default de la instalación
     *
     * @throws MargenNoFijableException
     */
    public function fijar(
        ?TipoItem $tipo,
        Decimal $fraccion,
        string $motivo,
        CarbonInterface $desde,
    ): MargenObjetivo {
        $dia = $desde->copy()->startOfDay();

        /*
         * El `@var` no es decorado: `DB::transaction()` está declarado
         * devolviendo `mixed`, así que sin esto el analizador no puede
         * saber que lo que sale del cierre es el modelo.
         *
         * @var MargenObjetivo $creado
         */
        $creado = DB::transaction(function () use ($tipo, $fraccion, $motivo, $dia): MargenObjetivo {
            $posterior = MargenObjetivo::query()
                ->where(fn (Builder $sub): Builder => $this->delMismoTipo($sub, $tipo))
                ->whereDate('vigencia_desde', '>=', $dia->toDateString())
                ->exists();

            if ($posterior) {
                throw MargenNoFijableException::yaHayUnoPosterior(
                    $tipo?->etiqueta() ?? 'todos los tipos',
                    $dia->format('d/m/Y'),
                );
            }

            $vigente = MargenObjetivo::query()
                ->where(fn (Builder $sub): Builder => $this->delMismoTipo($sub, $tipo))
                ->vigentesEn($dia)
                ->first();

            /*
             * `subDay()` y no la misma fecha: `daterange(desde, hasta,
             * '[]')` incluye los dos extremos, así que cerrar el viejo el
             * mismo día en que arranca el nuevo los solaparía por 24
             * horas — y la restricción de exclusión rechazaría el insert.
             */
            $vigente?->update(['vigencia_hasta' => $dia->copy()->subDay()->toDateString()]);

            return MargenObjetivo::query()->create([
                'tipo_item'      => $tipo,
                'porcentaje'     => $fraccion->paraBase(4),
                'motivo'         => $motivo,
                'vigencia_desde' => $dia->toDateString(),
                'vigencia_hasta' => null,
            ]);
        });

        return $creado;
    }

    /**
     * El default de la instalación se identifica por el nulo, y en SQL un
     * `where tipo_item = null` no encuentra nada nunca.
     *
     * @param Builder<MargenObjetivo> $consulta
     *
     * @return Builder<MargenObjetivo>
     */
    private function delMismoTipo(Builder $consulta, ?TipoItem $tipo): Builder
    {
        return $tipo instanceof TipoItem
            ? $consulta->where('tipo_item', $tipo->value)
            : $consulta->whereNull('tipo_item');
    }
}
