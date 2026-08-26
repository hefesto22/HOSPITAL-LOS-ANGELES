<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Armar la base de precios de un pagador nuevo a partir de otra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PROBLEMA QUE RESUELVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Firmar con una aseguradora nueva es fijar el precio de ciento treinta
 * ítems. A mano son ciento treinta pantallas, y a la mitad alguien se
 * cansa y quedan sesenta ítems sin precio — que en el mostrador se ven
 * como «este ítem no tiene precio para este pagador» a las once de la
 * noche.
 *
 * Con esto, firmar es: elegir de qué base se parte, escribir el
 * porcentaje pactado y confirmar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO PISA LO QUE YA TIENE PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si un ítem ya tenía un precio cargado para el pagador destino, se
 * respeta. Ese número lo puso alguien a mano, probablemente porque se
 * negoció aparte, y una copia masiva que lo borre destruye trabajo sin
 * avisar. Lo que devuelve el método es cuántos creó y cuántos respetó,
 * para poder decírselo a quien apretó el botón.
 *
 * Para reemplazar de verdad está el ajuste en masa, que es otra acción,
 * se llama distinto y pide confirmación.
 */
final class CopiadorDeBaseDePrecios
{
    /**
     * ─────────────────────────────────────────────────────────────────
     * LAS CLAVES DEL SELECTOR DE ORIGEN VIVEN ACÁ
     * ─────────────────────────────────────────────────────────────────
     *
     * Son el contrato entre DOS pantallas: la de bases de precios y el
     * alta de un pagador. Repetidas en cada una, el día que alguien
     * cambie una y no la otra la copia se hace en silencio desde el
     * lugar equivocado — y nadie lo nota hasta que la aseguradora
     * reclama por un precio que no firmó.
     *
     * ⚠️ El prefijo no es adorno: PHP convierte de vuelta a entero toda
     * clave de arreglo que parezca un número, así que un id pelado
     * rompería el tipo del arreglo de opciones.
     */
    public const ORIGEN_VACIO = 'vacio';

    public const ORIGEN_LISTA = 'lista';

    public const ORIGEN_CONVENIO = 'convenio:';

    public function __construct(
        private readonly FijadorDePrecio $fijador,
    ) {}

    /**
     * El convenio que representa una clave del selector.
     *
     * `null` puede significar dos cosas distintas —«el precio de lista»
     * o «no copiar nada»— así que quien llama pregunta primero con
     * `noCopiaNada()`.
     */
    public function origenDesde(mixed $clave): ?Convenio
    {
        if (! is_string($clave) || ! str_starts_with($clave, self::ORIGEN_CONVENIO)) {
            return null;
        }

        $convenio = Convenio::query()
            ->find((int) mb_substr($clave, mb_strlen(self::ORIGEN_CONVENIO)));

        return $convenio instanceof Convenio ? $convenio : null;
    }

    public function noCopiaNada(mixed $clave): bool
    {
        return ! is_string($clave) || $clave === '' || $clave === self::ORIGEN_VACIO;
    }

    /**
     * Las opciones del selector, listas para un `Select` de Filament.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 SOLO SE OFRECE LO QUE TIENE ALGO PARA COPIAR
     * ─────────────────────────────────────────────────────────────────
     *
     * Un pagador sin precios propios no es un origen: copiarlo deja el
     * destino igual de vacío, con el agravante de que quien apretó el
     * botón cree que hizo algo. El caso que lo hace evidente es el
     * paciente particular: su precio ES el precio de lista —no tiene
     * tarifario propio— así que aparecía DOS VECES en el selector, una
     * como «PRECIO DE LISTA DEL HOSPITAL» y otra con su nombre, y de
     * las dos solo una copiaba algo.
     *
     * El filtro es «tiene precios propios» y no «no es contado» a
     * propósito: si algún día alguien le carga a CONTADO una base
     * propia de verdad, esa base sí es un origen legítimo y aparece
     * sola. La regla describe el dato, no adivina la intención.
     *
     * El número al lado del nombre es lo que hace verificable la
     * elección: se ve qué se está por copiar antes de copiarlo.
     *
     * @return array<string, string>
     */
    public function opcionesDeOrigen(?int $excluyendo = null, bool $conVacio = false): array
    {
        $opciones = [];

        if ($conVacio) {
            $opciones[self::ORIGEN_VACIO] = 'Empezar sin precios cargados';
        }

        $opciones[self::ORIGEN_LISTA] = sprintf(
            'PRECIO DE LISTA DEL HOSPITAL — %d ítems',
            $this->cuantosTienenPrecio(null),
        );

        $conteos = $this->conteosPorPagador();

        $pagadores = Convenio::query()->orderBy('nombre')->get();

        foreach ($pagadores as $convenio) {
            if ($convenio->id === $excluyendo) {
                continue;
            }

            $cuantos = $conteos[$convenio->id] ?? 0;

            if ($cuantos === 0) {
                continue;
            }

            $opciones[self::ORIGEN_CONVENIO.$convenio->id] = sprintf(
                '%s — %d ítems',
                $convenio->nombre,
                $cuantos,
            );
        }

        return $opciones;
    }

    /**
     * Cuántos ítems tiene con precio propio cada pagador, en UNA consulta.
     *
     * Preguntárselo pagador por pagador es una consulta por fila del
     * selector, y esto se dibuja en cada pintada de la pantalla de
     * bases. Con veinte aseguradoras son veinte viajes a la base para
     * armar un desplegable.
     *
     * @return array<int, int> id del convenio => cuántos ítems
     */
    public function conteosPorPagador(?CarbonInterface $dia = null): array
    {
        $dia = ($dia ?? now())->copy()->startOfDay();

        $filas = Tarifario::query()
            ->selectRaw('tarifarios.convenio_id as convenio_id, count(*) as cuantos')
            ->whereNotNull('tarifarios.convenio_id')
            ->whereNull('tarifarios.sede_id')
            ->vigentesEn($dia)
            ->groupBy('tarifarios.convenio_id')
            ->get();

        $conteos = [];

        foreach ($filas as $fila) {
            $conteos[(int) $fila->getAttribute('convenio_id')] = (int) $fila->getAttribute('cuantos');
        }

        return $conteos;
    }

    /**
     * @param Convenio|null $origen nulo = partir del precio de lista
     * @param Decimal $factor 1 = el mismo precio; 0.85 = 15 % menos
     *
     * @return array{creados: int, respetados: int, sinPrecioEnElOrigen: int}
     */
    public function copiar(
        ?Convenio $origen,
        Convenio $destino,
        Decimal $factor,
        string $motivo,
        ?CarbonInterface $desde = null,
    ): array {
        $dia = ($desde ?? now())->copy()->startOfDay();

        $creados = 0;
        $respetados = 0;
        $sinPrecio = 0;

        /*
         * `chunkById` y no `get()`: el catálogo de un hospital son miles
         * de ítems y esto corre en una petición web. Traerlos todos a
         * memoria es el §12 en su forma más cara.
         */
        Item::query()
            ->vigentesEn($dia)
            ->orderBy('id')
            ->chunkById(200, function ($items) use (
                $origen,
                $destino,
                $factor,
                $motivo,
                $dia,
                &$creados,
                &$respetados,
                &$sinPrecio
            ): void {
                foreach ($items as $item) {
                    /*
                     * Farmacia no se copia a un convenio: su precio sale
                     * del costo y se recalcula con cada compra. Se saltea
                     * en silencio y no se cuenta como «sin precio en el
                     * origen» — no le falta nada, no le corresponde.
                     */
                    if ($item->se_almacena) {
                        continue;
                    }

                    if ($this->yaTienePrecio($item, $destino, $dia)) {
                        $respetados++;

                        continue;
                    }

                    $base = $this->precioDeOrigen($item, $origen, $dia);

                    if (! $base instanceof Monto) {
                        $sinPrecio++;

                        continue;
                    }

                    $this->fijador->fijar(
                        item: $item,
                        convenio: $destino,
                        sede: null,
                        precio: Monto::de($base->cantidad()->por($factor)->redondeado(4)),
                        motivo: $motivo,
                        desde: $dia,
                    );

                    $creados++;
                }
            });

        return [
            'creados'             => $creados,
            'respetados'          => $respetados,
            'sinPrecioEnElOrigen' => $sinPrecio,
        ];
    }

    /**
     * Cuántos ítems tienen precio para este pagador hoy. Lo usa la
     * pantalla para el número que va al lado de cada pestaña.
     */
    public function cuantosTienenPrecio(?Convenio $convenio, ?CarbonInterface $dia = null): int
    {
        $dia = ($dia ?? now())->copy()->startOfDay();

        return Tarifario::query()
            ->when(
                $convenio instanceof Convenio,
                /*
                 * Farmacia queda fuera del conteo de un convenio por la
                 * misma razón por la que no se lista: no se le pacta. Si
                 * quedó alguno de antes —copiado cuando esto se permitía—
                 * este número deja de contarlo, y la diferencia contra
                 * «en el catálogo» es la señal de que hay algo que
                 * limpiar.
                 */
                fn ($consulta) => $consulta
                    ->where('convenio_id', $convenio?->id)
                    ->whereHas('item', fn (Builder $item): Builder => $item->where('se_almacena', false)),
                fn ($consulta) => $consulta->whereNull('convenio_id'),
            )
            ->whereNull('sede_id')
            ->vigentesEn($dia)
            ->count();
    }

    private function yaTienePrecio(Item $item, Convenio $destino, CarbonInterface $dia): bool
    {
        return Tarifario::query()
            ->where('item_id', $item->id)
            ->where('convenio_id', $destino->id)
            ->whereNull('sede_id')
            ->vigentesEn($dia)
            ->exists();
    }

    private function precioDeOrigen(Item $item, ?Convenio $origen, CarbonInterface $dia): ?Monto
    {
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->when(
                $origen instanceof Convenio,
                fn ($consulta) => $consulta->where('convenio_id', $origen?->id),
                fn ($consulta) => $consulta->whereNull('convenio_id'),
            )
            ->whereNull('sede_id')
            ->vigentesEn($dia)
            ->first();

        return $fila instanceof Tarifario ? $fila->monto() : null;
    }
}
