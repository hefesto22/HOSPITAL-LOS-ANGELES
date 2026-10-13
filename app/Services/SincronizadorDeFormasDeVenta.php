<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\FormasDeVentaException;
use App\Domain\ValueObjects\CambiosDeFormasDeVenta;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Tarifario;
use App\Models\Unidad;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las formas de venta de un producto, guardadas de una vez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 TODAS JUNTAS, EN LA FICHA (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Al agregar blíster dirá de cuánto es el blíster, y caja pues es el
 * total de pastillas; así en automático se crean como se vende, y es
 * más fácil marcar qué mercadería entra.»
 *
 * Antes cada envase se cargaba en su propio modal, con nombre, vigencia
 * y fecha, después de haber guardado el producto. Ahora la ficha dice
 * «TABLETA suelta · BLÍSTER de 10 · CAJA de 100» y al guardar quedan
 * creadas las presentaciones: la recepción ofrece exactamente esas, y el
 * cobro también (`FormasDeCobro`).
 *
 * La unidad suelta no es una fila: es «Se dispensa en», que ya está en
 * la ficha. Acá solo van los envases.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UN ENVASE QUE YA SE USÓ NO SE CAMBIA: SE REEMPLAZA (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «no me deja editar el blíster o la caja; debería poder. Si
 * cambio el blíster a 12 tabletas, que actualice el precio también».
 *
 * Un envase por el que ya entró mercadería —tiene lotes, líneas de
 * recepción, cargos, préstamos o precio propio— no cambia de tamaño EN
 * SU FILA: si la CAJA X 100 pasara a decir 50, cada lote que entró en
 * esa caja se leería con la mitad, y el kardex no avisa (§9.G11).
 *
 * Así que cambiarlo en la ficha lo REEMPLAZA desde hoy:
 *
 *   · el viejo se retira —borrado lógico, con su vigencia cerrada ayer—,
 *     y todo lo que pasó con él lo sigue nombrando como era: el lote de
 *     BLISTER X 10 sigue trayendo 10, el cargo sigue diciendo «1
 *     BLISTER X 10» (las relaciones lo leen con `withTrashed()`);
 *   · sus precios se cierran, y los lugares del stock infinito que lo
 *     tenían marcado pasan a tener el nuevo;
 *   · nace el nuevo con lo que dice la ficha.
 *
 * Lo que devuelve dice qué cambió, para que la ficha ajuste los precios
 * con ese porqué (`ReajustadorDePrecios`).
 *
 * Uno usado que se QUITA de la ficha sigue sin poder quitarse: eso no se
 * pidió, y un envase que desaparece sin reemplazo deja los lotes que
 * entraron así sin forma de venderse cerrados.
 *
 * Uno que nunca se usó se corrige en su fila o se quita de verdad
 * (borrado lógico): no explica ningún lote ni ninguna factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PRINCIPAL LA ELIGE QUIEN CARGA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es `es_predeterminada`: la que el listado y los presupuestos muestran
 * primero, y el envase en que se piensa el precio. La elige la estrella
 * de la ficha:
 *
 *   · una fila marcada → esa presentación;
 *   · la suelta marcada → ninguna presentación, porque la principal es
 *     la unidad misma (la bolsa de suero que llega en CAJA X 12);
 *   · nadie marcó nada → la más grande, que acierta con las tabletas.
 */
final class SincronizadorDeFormasDeVenta
{
    /**
     * Las tablas que guardan de qué envase salió algo. Con una fila en
     * cualquiera, el envase ya explica algo que pasó.
     */
    private const DONDE_SE_USA = ['lotes', 'recepcion_lineas', 'cargos', 'prestamos', 'tarifarios'];

    public function __construct(
        private readonly UbicadorDeProducto $ubicador,
    ) {}

    /**
     * @param list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal?: bool}> $formas
     * @param bool $sueltaPrincipal la principal es la unidad suelta, no un envase
     *
     * @throws FormasDeVentaException
     */
    public function sincronizar(Item $item, array $formas, bool $sueltaPrincipal = false): CambiosDeFormasDeVenta
    {
        /*
         * `DB::transaction()` está declarado devolviendo `mixed`.
         *
         * @var CambiosDeFormasDeVenta $resultado
         */
        $resultado = DB::transaction(function () use ($item, $formas, $sueltaPrincipal): CambiosDeFormasDeVenta {
            $hoy = now()->startOfDay();

            $actuales = ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->get();

            $principalAntes = $actuales->first(fn (ItemPresentacion $presentacion): bool => $presentacion->es_predeterminada);

            $usadas = $this->usadas(array_map(
                static fn (ItemPresentacion $presentacion): int => $presentacion->id,
                $actuales->all(),
            ));
            $limpias = $this->validadas($formas);

            /*
             * La más grande primero: es la que queda habitual al nacer.
             */
            usort(
                $limpias,
                static fn (array $una, array $otra): int => $otra['contenido']->comparar($una['contenido']),
            );

            $referidas = array_values(array_filter(array_map(
                static fn (array $forma): ?int => $forma['presentacion_id'],
                $limpias,
            )));

            $quedan = [];
            $principal = null;
            $cambios = [];
            $avisos = [];

            /** @var array<int, int> $reemplazadaPor la vieja => la nueva */
            $reemplazadaPor = [];

            /*
             * ─────────────────────────────────────────────────────────
             * 1 · LAS USADAS QUE CAMBIAN: SE RETIRAN
             * ─────────────────────────────────────────────────────────
             *
             * Antes que nada, para que el índice único (producto, envase,
             * contenido) ya no las vea cuando nazcan las nuevas.
             */
            $reemplazos = [];

            foreach ($limpias as $forma) {
                $presentacion = $this->deEsteProducto($actuales, $forma['presentacion_id']);

                if (! $presentacion instanceof ItemPresentacion
                    || $this->esLaMisma($presentacion, $forma['unidad'], $forma['contenido'])
                    || ! in_array($presentacion->id, $usadas, true)) {
                    continue;
                }

                $this->retirar($presentacion, $hoy);
                $reemplazos[] = ['vieja' => $presentacion, 'forma' => $forma];
            }

            $retiradas = array_map(static fn (array $reemplazo): int => $reemplazo['vieja']->id, $reemplazos);

            /*
             * ─────────────────────────────────────────────────────────
             * 2 · LAS QUE YA EXISTÍAN Y SIGUEN
             * ─────────────────────────────────────────────────────────
             *
             * Igual que antes: se quedan. Una que nunca se usó y cambia,
             * se corrige en su misma fila.
             */
            foreach ($limpias as $forma) {
                $presentacion = $this->deEsteProducto($actuales, $forma['presentacion_id']);

                if (! $presentacion instanceof ItemPresentacion || in_array($presentacion->id, $retiradas, true)) {
                    continue;
                }

                if (! $this->esLaMisma($presentacion, $forma['unidad'], $forma['contenido'])) {
                    $antes = $presentacion->envase();

                    $presentacion->forceFill([
                        'unidad_id'                 => $forma['unidad']->id,
                        'unidades_por_presentacion' => $forma['contenido']->paraBase(),
                        'nombre'                    => $this->nombre($item, $forma['unidad'], $forma['contenido']),
                    ])->save();

                    $cambios[] = sprintf('%s PASÓ A %s', $antes, $presentacion->envase());
                }

                $quedan[] = $presentacion->id;

                if ($forma['principal']) {
                    $principal = $presentacion->id;
                }
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 3 · LAS QUE REEMPLAZAN A UNA USADA
             * ─────────────────────────────────────────────────────────
             */
            foreach ($reemplazos as ['vieja' => $vieja, 'forma' => $forma]) {
                $nueva = $this->crear($item, $forma['unidad'], $forma['contenido']);
                $cambio = sprintf('%s PASÓ A %s', $vieja->envase(), $nueva->envase());

                $this->ubicador->cambiarEnvase($item, $vieja->id, $nueva->id, $cambio);

                $reemplazadaPor[$vieja->id] = $nueva->id;
                $quedan[] = $nueva->id;
                $cambios[] = $cambio;

                if ($forma['principal']) {
                    $principal = $nueva->id;
                }

                if ($this->quedaEnElEstante($vieja)) {
                    $avisos[] = sprintf(
                        'Lo que ya está en el estante como %s sigue igual en su lote y se vende suelto; %s sale de lo que entre así de ahora en adelante.',
                        $vieja->envase(),
                        $nueva->envase(),
                    );
                }
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 4 · LAS NUEVAS
             * ─────────────────────────────────────────────────────────
             *
             * Si ya hay una igual que nadie reclamó —se quitó la fila y
             * se volvió a agregar—, es esa: se queda, no se duplica.
             */
            foreach ($limpias as $forma) {
                if ($this->deEsteProducto($actuales, $forma['presentacion_id']) instanceof ItemPresentacion) {
                    continue;
                }

                $igual = $actuales->first(
                    fn (ItemPresentacion $existente): bool => ! in_array($existente->id, $referidas, true)
                        && ! in_array($existente->id, $quedan, true)
                        && ! in_array($existente->id, $retiradas, true)
                        && $this->esLaMisma($existente, $forma['unidad'], $forma['contenido']),
                );

                if ($igual instanceof ItemPresentacion) {
                    $id = $igual->id;
                } else {
                    $nueva = $this->crear($item, $forma['unidad'], $forma['contenido']);
                    $id = $nueva->id;
                    $cambios[] = 'SE AGREGÓ '.$nueva->envase();
                }

                $quedan[] = $id;

                if ($forma['principal']) {
                    $principal = $id;
                }
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 5 · LAS QUE SE QUITARON
             * ─────────────────────────────────────────────────────────
             */
            foreach ($actuales as $presentacion) {
                if (in_array($presentacion->id, $quedan, true) || in_array($presentacion->id, $retiradas, true)) {
                    continue;
                }

                if (in_array($presentacion->id, $usadas, true)) {
                    throw FormasDeVentaException::noSeQuita($presentacion->nombre);
                }

                $presentacion->delete();
                $cambios[] = 'SE QUITÓ '.$presentacion->envase();
            }

            $this->marcarLaPrincipal($item, $principal, $sueltaPrincipal);

            $cambioDePrincipal = $this->cambioDePrincipal($item, $principalAntes, $reemplazadaPor);

            if ($cambioDePrincipal !== null) {
                $cambios[] = $cambioDePrincipal;
            }

            return new CambiosDeFormasDeVenta($cambios, $avisos);
        });

        return $resultado;
    }

    /**
     * De estas presentaciones, las que ya explican algo que pasó: un
     * lote, una recepción, un cargo, un préstamo o un precio propio.
     *
     * Una consulta por tabla, no una por presentación.
     *
     * @param array<int, int> $ids
     *
     * @return list<int>
     */
    public function usadas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $usadas = [];

        foreach (self::DONDE_SE_USA as $tabla) {
            foreach (DB::table($tabla)->whereIn('item_presentacion_id', $ids)->distinct()->pluck('item_presentacion_id') as $id) {
                $usadas[] = (int) $id;
            }
        }

        return array_values(array_unique($usadas));
    }

    /**
     * Lo que manda la ficha, comprobado: envase que existe, contenido
     * positivo y ninguna repetida.
     *
     * @param list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal?: bool}> $formas
     *
     * @return list<array{presentacion_id: int|null, unidad: Unidad, contenido: Decimal, principal: bool}>
     *
     * @throws FormasDeVentaException
     */
    private function validadas(array $formas): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $forma): ?int => $forma['unidad_id'],
            $formas,
        )));

        $unidades = Unidad::query()->whereIn('id', $ids)->get();

        $limpias = [];
        $vistas = [];

        foreach ($formas as $forma) {
            $unidad = $forma['unidad_id'] === null ? null : $unidades->firstWhere('id', $forma['unidad_id']);

            if (! $unidad instanceof Unidad) {
                throw FormasDeVentaException::sinEnvase();
            }

            $texto = $forma['unidades_por_presentacion'];
            $contenido = is_string($texto) && is_numeric($texto) ? Decimal::de($texto) : null;

            if (! $contenido instanceof Decimal || ! $contenido->mayorQue('0')) {
                throw FormasDeVentaException::sinContenido($unidad->codigo);
            }

            $clave = $unidad->id.':'.$contenido->paraBase();

            if (isset($vistas[$clave])) {
                throw FormasDeVentaException::repetida(
                    $unidad->codigo.' X '.ItemPresentacion::sinCerosDeMas($contenido->paraBase()),
                );
            }

            $vistas[$clave] = true;

            $limpias[] = [
                'presentacion_id' => $forma['presentacion_id'],
                'unidad'          => $unidad,
                'contenido'       => $contenido,
                'principal'       => $forma['principal'] ?? false,
            ];
        }

        return $limpias;
    }

    private function crear(Item $item, Unidad $unidad, Decimal $contenido): ItemPresentacion
    {
        return ItemPresentacion::query()->create([
            'item_id'                   => $item->id,
            'unidad_id'                 => $unidad->id,
            'unidades_por_presentacion' => $contenido->paraBase(),
            'nombre'                    => $this->nombre($item, $unidad, $contenido),
            'vigencia_desde'            => now()->toDateString(),
        ]);
    }

    /**
     * Saca de circulación un envase que ya explica algo que pasó, sin
     * borrar nada de lo que pasó.
     *
     *   · Sus precios se cierran ayer. Uno que arrancaba hoy y con el que
     *     nadie cobró se retira; si ya se cobró con él, rige hasta hoy.
     *   · La presentación queda con su vigencia cerrada y borrada
     *     lógicamente: deja de ofrecerse en la recepción, el cobro y la
     *     ficha, y sigue leyéndose desde los lotes, los cargos y los
     *     precios viejos.
     *
     * El código de barras se queda con ella: una etiqueta impresa es para
     * siempre (ver la migración del 21-ago-2026).
     */
    private function retirar(ItemPresentacion $vieja, CarbonInterface $hoy): void
    {
        $precios = Tarifario::query()
            ->where('item_presentacion_id', $vieja->id)
            ->where(fn ($sub) => $sub->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $hoy->toDateString()))
            ->lockForUpdate()
            ->get();

        foreach ($precios as $fila) {
            $arrancaHoyOMasTarde = ! $fila->vigencia_desde->copy()->startOfDay()->lessThan($hoy);

            if ($arrancaHoyOMasTarde && ! $fila->yaSeUso()) {
                $fila->delete();

                continue;
            }

            $fila->update([
                'vigencia_hasta' => $arrancaHoyOMasTarde
                    ? $fila->vigencia_desde->toDateString()
                    : $hoy->copy()->subDay()->toDateString(),
            ]);
        }

        $vieja->forceFill([
            'es_predeterminada' => false,
            'vigencia_hasta'    => $vieja->vigencia_desde->copy()->startOfDay()->lessThan($hoy)
                ? $hoy->copy()->subDay()->toDateString()
                : $vieja->vigencia_desde->toDateString(),
        ])->save();

        $vieja->delete();
    }

    /**
     * ¿Quedan existencias en lotes que entraron en este envase? Solo para
     * avisarlo: siguen en su lote como entraron.
     */
    private function quedaEnElEstante(ItemPresentacion $envase): bool
    {
        return DB::table('existencias')
            ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
            ->where('lotes.item_presentacion_id', $envase->id)
            ->where('existencias.cantidad', '>', 0)
            ->exists();
    }

    /**
     * «LA PRINCIPAL PASÓ DE CAJA X 100 TAB A BLISTER X 10 TAB», o nada si
     * la principal sigue siendo la misma —también si es la misma que se
     * reemplazó por otro tamaño: eso ya lo dice el reemplazo—.
     *
     * @param array<int, int> $reemplazadaPor
     */
    private function cambioDePrincipal(Item $item, ?ItemPresentacion $antes, array $reemplazadaPor): ?string
    {
        $ahora = ItemPresentacion::query()
            ->where('item_id', $item->id)
            ->where('es_predeterminada', true)
            ->first();

        $eraId = $antes instanceof ItemPresentacion ? ($reemplazadaPor[$antes->id] ?? $antes->id) : null;
        $esId = $ahora instanceof ItemPresentacion ? $ahora->id : null;

        if ($eraId === $esId) {
            return null;
        }

        return sprintf('LA PRINCIPAL PASÓ DE %s A %s', $this->comoSeLlama($item, $antes), $this->comoSeLlama($item, $ahora));
    }

    private function comoSeLlama(Item $item, ?ItemPresentacion $principal): string
    {
        if ($principal instanceof ItemPresentacion) {
            return $principal->envase();
        }

        $suelta = $item->unidadDispensacion;

        return ($suelta instanceof Unidad ? $suelta->nombre : 'LA UNIDAD').' SUELTA';
    }

    /**
     * La presentación que la fila dice ser, solo si es de este producto.
     * Un id de otro producto —un formulario manipulado— no se toca: se
     * trata como fila nueva.
     *
     * @param Collection<int, ItemPresentacion> $actuales
     */
    private function deEsteProducto(Collection $actuales, ?int $id): ?ItemPresentacion
    {
        if ($id === null) {
            return null;
        }

        $presentacion = $actuales->firstWhere('id', $id);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    private function esLaMisma(ItemPresentacion $presentacion, Unidad $unidad, Decimal $contenido): bool
    {
        return $presentacion->unidad_id === $unidad->id
            && is_numeric($presentacion->unidades_por_presentacion)
            && Decimal::de($presentacion->unidades_por_presentacion)->igualA($contenido);
    }

    /**
     * «CAJA X 100 TAB», con la misma regla que usaba el modal de antes
     * (`ItemPresentacion::comoSeEnvasa()`): el producto no se repite
     * adelante, se le pega al mostrar.
     */
    private function nombre(Item $item, Unidad $unidad, Decimal $contenido): string
    {
        $dispensacion = $item->unidadDispensacion;

        return ItemPresentacion::comoSeEnvasa(
            $unidad->codigo,
            ItemPresentacion::sinCerosDeMas($contenido->paraBase()),
            $dispensacion instanceof Unidad ? $dispensacion->codigo : 'UND',
        );
    }

    /**
     * Deja la estrella donde quedó en la ficha.
     *
     * Las presentaciones nuevas nacen marcando la primera como habitual
     * (`ItemPresentacion::booted()`), así que esto corre al final y pisa
     * lo que haya hecho ese automático.
     */
    private function marcarLaPrincipal(Item $item, ?int $principal, bool $sueltaPrincipal): void
    {
        if ($principal === null && $sueltaPrincipal) {
            /*
             * `update()` masivo: no dispara eventos de modelo, y no hay
             * ninguna presentación que tenga que quedar marcada.
             */
            ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->where('es_predeterminada', true)
                ->update(['es_predeterminada' => false]);

            return;
        }

        $elegida = $principal === null
            ? ItemPresentacion::query()
                ->where('item_id', $item->id)
                ->orderByDesc('unidades_por_presentacion')
                ->orderBy('id')
                ->first()
            : ItemPresentacion::query()->find($principal);

        /*
         * `save()` y no un `update()` masivo: el `saving` del modelo es
         * el que desmarca a la anterior, y la base además impide dos.
         */
        if ($elegida instanceof ItemPresentacion && ! $elegida->es_predeterminada) {
            $elegida->es_predeterminada = true;
            $elegida->save();
        }
    }
}
