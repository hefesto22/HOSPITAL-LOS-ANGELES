<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cambiar un precio es cerrar el anterior y abrir el nuevo.
 *
 * Es la misma ceremonia que `FijadorDeMargenObjetivo`, y la repetición es
 * deliberada: las llaves son distintas —allá el tipo de ítem, acá la
 * terna ítem + pagador + sede— y una abstracción común terminaría
 * recibiendo la llave por parámetro, que es una forma elegante de no
 * poder leer ninguno de los dos. Si aparece un tercer fijador con la
 * misma forma, ahí sí conviene extraerla.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES UN `UPDATE`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Editar el precio de la fila vigente borraría la respuesta a «¿por qué
 * esta factura de marzo dice L 29.33?». Una factura que no se puede
 * explicar es un problema ante el SAR, no una fila de menos.
 *
 * Entonces son dos escrituras en una transacción: al precio vigente se le
 * pone `vigencia_hasta` el día anterior, y el nuevo arranca en la fecha
 * elegida. Los rangos quedan pegados y sin traslape, que es lo que la
 * restricción de exclusión exige.
 */
final class FijadorDePrecio
{
    /**
     * @param Convenio|null $convenio nulo = el precio de lista
     * @param Sede|null $sede nulo = vale para todas las sedes
     *
     * @throws PrecioNoFijableException
     */
    public function fijar(
        Item $item,
        ?Convenio $convenio,
        ?Sede $sede,
        Monto $precio,
        string $motivo,
        CarbonInterface $desde,
        ?ItemPresentacion $presentacion = null,
    ): Tarifario {
        /*
         * 🔴 La regla vive acá y no en la pantalla: `BasesDePrecios` ya
         * no ofrece farmacia cuando hay un convenio elegido, pero
         * esconder una opción no es impedirla. El copiador de bases, un
         * seeder o un comando de consola entran por esta puerta sin pasar
         * por ningún formulario.
         */
        if ($convenio instanceof Convenio && $item->se_almacena) {
            throw PrecioNoFijableException::esDeFarmacia($item->nombre, $convenio->nombre);
        }

        $dia = $desde->copy()->startOfDay();
        $convenioId = $convenio?->id;
        $sedeId = $sede?->id;

        /*
         * El envase es parte de la LLAVE del precio, no un dato al
         * costado: el frasco de 60 ML y el de 80 ML tienen su propia
         * línea de tiempo. Cerrar el vigente del uno no puede cerrar el
         * del otro.
         */
        $presentacionId = $presentacion?->id;

        /*
         * `DB::transaction()` está declarado devolviendo `mixed`, así que
         * sin el `@var` el analizador no puede saber que lo que sale del
         * cierre es el modelo.
         *
         * @var Tarifario $creado
         */
        $creado = DB::transaction(function () use (
            $item,
            $convenio,
            $convenioId,
            $sedeId,
            $presentacionId,
            $precio,
            $motivo,
            $dia,
        ): Tarifario {
            $posterior = Tarifario::query()
                ->where('item_id', $item->id)
                ->where(fn (Builder $sub): Builder => $this->deLaMismaLlave($sub, $convenioId, $sedeId, $presentacionId))
                ->whereDate('vigencia_desde', '>=', $dia->toDateString())
                ->exists();

            if ($posterior) {
                /*
                 * `->` y no `?->` a la izquierda de `??`: el operador de
                 * fusión de nulos ya usa semántica de `isset()`, así que
                 * `$convenio->nombre` sobre un nulo no revienta — devuelve
                 * el valor de la derecha. El `?->` ahí es ruido que además
                 * sugiere que sin él habría un error, y no lo hay.
                 */
                throw PrecioNoFijableException::yaHayUnoPosterior(
                    $item->codigo,
                    $convenio->nombre ?? 'el precio de lista',
                    $dia->format('d/m/Y'),
                );
            }

            $vigente = Tarifario::query()
                ->where('item_id', $item->id)
                ->where(fn (Builder $sub): Builder => $this->deLaMismaLlave($sub, $convenioId, $sedeId, $presentacionId))
                ->vigentesEn($dia)
                ->first();

            /*
             * `subDay()` y no la misma fecha: `daterange(desde, hasta,
             * '[]')` incluye los dos extremos, así que cerrar el viejo el
             * mismo día en que arranca el nuevo los solaparía por 24
             * horas y el insert sería rechazado.
             */
            $vigente?->update(['vigencia_hasta' => $dia->copy()->subDay()->toDateString()]);

            return Tarifario::query()->create([
                'item_id'              => $item->id,
                'item_presentacion_id' => $presentacionId,
                'convenio_id'          => $convenioId,
                'sede_id'              => $sedeId,
                'precio'               => $precio->cantidad()->paraBase(4),
                'motivo'               => $motivo,
                'vigencia_desde'       => $dia->toDateString(),
                'vigencia_hasta'       => null,
            ]);
        });

        return $creado;
    }

    /**
     * El precio de lista se identifica por los nulos, y en SQL un
     * `where convenio_id = null` no encuentra nada nunca.
     *
     * @param Builder<Tarifario> $consulta
     *
     * @return Builder<Tarifario>
     */
    private function deLaMismaLlave(
        Builder $consulta,
        ?int $convenioId,
        ?int $sedeId,
        ?int $presentacionId = null,
    ): Builder {
        $consulta = $convenioId === null
            ? $consulta->whereNull('convenio_id')
            : $consulta->where('convenio_id', $convenioId);

        $consulta = $presentacionId === null
            ? $consulta->whereNull('item_presentacion_id')
            : $consulta->where('item_presentacion_id', $presentacionId);

        return $sedeId === null
            ? $consulta->whereNull('sede_id')
            : $consulta->where('sede_id', $sedeId);
    }
}
