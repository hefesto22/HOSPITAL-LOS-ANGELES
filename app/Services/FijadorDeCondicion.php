<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Renegociar con un convenio es cerrar lo anterior y abrir lo nuevo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOBRE LA REPETICIÓN, QUE YA VA POR LA TERCERA
 * ─────────────────────────────────────────────────────────────────────
 *
 * En `FijadorDePrecio` escribí que si aparecía un tercer fijador con esta
 * forma convenía extraer la parte común. Este es el tercero, así que miré
 * la extracción en serio — y decido no hacerla, con el argumento a la
 * vista para que el próximo lo pueda contradecir.
 *
 * Lo compartido son unas quince líneas: la transacción, el «¿hay uno
 * posterior? entonces no», el cierre con `subDay()` y el insert. Lo que
 * cambia es la LLAVE de cada historial —tipo de ítem, la terna ítem +
 * pagador + sede, el convenio—, los atributos que se escriben y el
 * mensaje del error. Una clase base tendría que recibir la llave como
 * cierre y los atributos como arreglo, y ninguno de los tres fijadores se
 * podría leer de corrido: habría que saltar a la base para entender qué
 * hace cada uno.
 *
 * Lo que sí compensaría extraer, si esto crece a un cuarto o quinto, es
 * la regla del `subDay()`, que es la única parte realmente sutil y hoy
 * está repetida con su explicación en cada archivo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO HACIA ADELANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Meter una condición en medio del historial haría que las facturas de la
 * renovación anterior pasen a calcularse con un porcentaje que ese día
 * todavía no se había firmado.
 */
final class FijadorDeCondicion
{
    /**
     * @param Decimal $factor fracción de la lista que el pagador paga: 0.85 = lista menos 15 %
     *
     * @throws PrecioNoFijableException
     */
    public function fijar(
        Convenio $convenio,
        Decimal $factor,
        string $motivo,
        CarbonInterface $desde,
    ): ConvenioCondicion {
        $dia = $desde->copy()->startOfDay();

        /*
         * `DB::transaction()` está declarado devolviendo `mixed`, así que
         * sin el `@var` el analizador no puede saber que lo que sale del
         * cierre es el modelo.
         *
         * @var ConvenioCondicion $creada
         */
        $creada = DB::transaction(function () use ($convenio, $factor, $motivo, $dia): ConvenioCondicion {
            $posterior = ConvenioCondicion::query()
                ->where('convenio_id', $convenio->id)
                ->whereDate('vigencia_desde', '>=', $dia->toDateString())
                ->exists();

            if ($posterior) {
                throw PrecioNoFijableException::yaHayCondicionPosterior(
                    $convenio->nombre,
                    $dia->format('d/m/Y'),
                );
            }

            $vigente = ConvenioCondicion::query()
                ->where('convenio_id', $convenio->id)
                ->vigentesEn($dia)
                ->first();

            /*
             * `subDay()` y no la misma fecha: `daterange(desde, hasta,
             * '[]')` incluye los dos extremos, así que cerrar la vieja el
             * mismo día en que arranca la nueva las solaparía por 24 horas
             * y el insert sería rechazado.
             */
            $vigente?->update(['vigencia_hasta' => $dia->copy()->subDay()->toDateString()]);

            return ConvenioCondicion::query()->create([
                'convenio_id'        => $convenio->id,
                'factor_sobre_lista' => $factor->paraBase(4),
                'motivo'             => $motivo,
                'vigencia_desde'     => $dia->toDateString(),
                'vigencia_hasta'     => null,
            ]);
        });

        return $creada;
    }
}
