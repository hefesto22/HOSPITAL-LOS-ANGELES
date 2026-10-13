<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\UbicacionException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\UbicacionDeProducto;
use App\Models\Unidad;
use App\Support\UsuarioAutenticado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * En qué almacenes está un producto con stock infinito, y qué hay en
 * cada uno (13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si vamos a llevar control, es de los productos que están en
 * tales lugares: al crear un producto, por defecto va a farmacia, pero se
 * debe poder seleccionar si se crea en farmacia y bodega. Así, al vender,
 * saben de dónde lo sacaron».
 *
 * El infinito no se cuenta, así que «mover» no puede ser pasar una
 * cantidad de un estante a otro. Es decir que AHORA TAMBIÉN hay en el
 * carrito rojo — y, si se llevó todo, que en farmacia ya no.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ HAY EN CADA LUGAR (13-oct-2026, misma tarde)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «puede ser que solo haya blíster, o cajas, o unidades, o de
 * todo. Si marca caja, por ende hay unidades y blíster».
 *
 *   · Cada lugar nace DE TODO: todos los envases del producto, también
 *     los que se le agreguen después a la ficha.
 *   · O dice qué envases hay. Un envase marcado trae lo que lleva
 *     adentro: la CAJA X 100 trae el BLISTER X 10 —la misma regla con la
 *     que un blíster sale de un lote de cajas, `FormasDeCobro`—.
 *   · Las unidades sueltas están siempre donde está el producto: de una
 *     caja se saca una tableta. Un lugar sin ningún envase marcado es
 *     «solo unidades».
 *
 * Lo marcado se guarda en su forma más corta —la caja sola, no la caja y
 * el blíster que ya trae—, y si alcanza para todo se guarda «de todo».
 * Así dos formas de decir lo mismo son la misma fila.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REGLAS
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Solo productos con stock infinito. Uno con existencia real está
 *     donde está su saldo, y se mueve con cantidad (`TrasladadorDeExistencias`).
 *   · Sacarlo de un lugar CIERRA la fila, con hora, motivo y quién; nunca
 *     la borra. Cambiar lo que hay en un lugar también: se cierra la fila
 *     y se abre otra.
 *   · No se le quita el último lugar a mano: un producto sin ningún
 *     almacén no se sabría de dónde sale. Moverlo con «se llevó todo» sí
 *     puede vaciar el origen, porque deja el destino.
 *   · Un producto sin NINGÚN lugar anotado —los que se marcaron antes de
 *     esta regla— sale de cualquier almacén, en cualquier envase: antes
 *     que trabar un cargo a las tres de la mañana, se acepta y se anota
 *     de dónde salió.
 *
 * Todo bajo candado sobre la fila del producto: dos personas ubicándolo a
 * la vez no dejan dos filas abiertas (lo impide además un índice único).
 */
final class UbicadorDeProducto
{
    public const MOTIVO_DESDE_LA_FICHA = 'SE QUITÓ DESDE LA FICHA DEL PRODUCTO';

    public const MOTIVO_A_MANO = 'YA NO HAY EN ESTE ALMACÉN';

    public const MOTIVO_CAMBIO_LO_QUE_HAY = 'CAMBIÓ LO QUE HAY EN ESTE ALMACÉN';

    public function __construct(
        private readonly FormasDeCobro $formas,
    ) {}

    /**
     * Los almacenes donde está hoy, de la sede de quien pregunta.
     *
     * @return list<int>
     */
    public function dondeEsta(Item $item): array
    {
        return array_values(UbicacionDeProducto::query()
            ->abiertas()
            ->where('item_id', $item->id)
            ->orderBy('almacen_id')
            ->pluck('almacen_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Qué hay en cada almacén donde está: nulo es «de todo»; una lista,
     * los envases marcados —vacía, solo unidades sueltas—.
     *
     * @return array<int, list<int>|null>
     */
    public function lugaresDe(Item $item): array
    {
        return $this->lugaresDeVarios([(int) $item->id])[(int) $item->id] ?? [];
    }

    /**
     * Lo mismo, para toda una página de productos: dos consultas.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, array<int, list<int>|null>>
     */
    public function lugaresDeVarios(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $abiertas = UbicacionDeProducto::query()
            ->abiertas()
            ->whereIn('item_id', $itemIds)
            ->orderBy('almacen_id')
            ->get(['id', 'item_id', 'almacen_id', 'de_todo']);

        $marcados = [];

        $pedidos = $abiertas->reject(fn (UbicacionDeProducto $lugar): bool => $lugar->de_todo)->pluck('id')->all();

        if ($pedidos !== []) {
            foreach (DB::table('ubicacion_envases')->whereIn('ubicacion_id', $pedidos)->orderBy('item_presentacion_id')->get(['ubicacion_id', 'item_presentacion_id']) as $fila) {
                $marcados[(int) $fila->ubicacion_id][] = (int) $fila->item_presentacion_id;
            }
        }

        $lugares = [];

        foreach ($abiertas as $lugar) {
            $lugares[$lugar->item_id][$lugar->almacen_id] = $lugar->de_todo ? null : ($marcados[$lugar->id] ?? []);
        }

        return $lugares;
    }

    /**
     * Los envases que se venden cerrados —los mismos que ofrece
     * `FormasDeCobro`: con más de una unidad adentro—, vigentes hoy y del
     * más grande al más chico. Con su unidad cargada: es la que dice si
     * un envase trae a otro.
     *
     * @return Collection<int, ItemPresentacion>
     */
    public function envasesDe(Item $item): Collection
    {
        return $this->envasesDeVarios([(int) $item->id])[(int) $item->id] ?? new Collection;
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, Collection<int, ItemPresentacion>>
     */
    public function envasesDeVarios(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        /** @var array<int, Collection<int, ItemPresentacion>> $porItem */
        $porItem = [];

        $presentaciones = ItemPresentacion::query()
            ->with('unidad')
            ->whereIn('item_id', $itemIds)
            ->vigentesEn(now())
            ->get()
            ->filter(fn (ItemPresentacion $presentacion): bool => self::contenido($presentacion)->mayorQue('1'))
            ->sort(fn (ItemPresentacion $una, ItemPresentacion $otra): int => self::contenido($otra)->comparar(self::contenido($una))
                ?: $una->id <=> $otra->id);

        foreach ($presentaciones as $presentacion) {
            $porItem[$presentacion->item_id] ??= new Collection;
            $porItem[$presentacion->item_id]->push($presentacion);
        }

        return $porItem;
    }

    /**
     * ¿Hay este envase, con lo marcado en el lugar? Nulo es «de todo».
     *
     * @param list<int>|null $marcados
     * @param Collection<int, ItemPresentacion> $envases los del producto
     */
    public function hayEnvase(?array $marcados, ItemPresentacion $envase, Collection $envases): bool
    {
        if ($marcados === null) {
            return true;
        }

        return array_intersect($marcados, $this->formas->envasesQueLoTraen($envase, $envases)) !== [];
    }

    /**
     * Los envases que hay en un lugar, contando lo que traen adentro los
     * marcados, en el orden de `envasesDe()`.
     *
     * @param list<int>|null $marcados
     * @param Collection<int, ItemPresentacion> $envases los del producto
     *
     * @return list<int>
     */
    public function disponibles(?array $marcados, Collection $envases): array
    {
        return array_values($envases
            ->filter(fn (ItemPresentacion $envase): bool => $this->hayEnvase($marcados, $envase, $envases))
            ->map(fn (ItemPresentacion $envase): int => (int) $envase->id)
            ->all());
    }

    /**
     * Los más grandes de lo que hay: lo que se dice en voz alta. Con caja
     * y blíster, «CAJA»; con solo blíster, «BLISTER».
     *
     * @param list<int>|null $marcados
     * @param Collection<int, ItemPresentacion> $envases los del producto
     *
     * @return list<int>
     */
    public function losMasGrandes(?array $marcados, Collection $envases): array
    {
        return $this->sinLoQueYaTraen($this->disponibles($marcados, $envases), $envases);
    }

    /**
     * ¿Se le puede cargar a un paciente sacándolo de ese almacén? Con un
     * envase sin abrir, además, ¿hay de ese envase ahí?
     */
    public function sePuedeSacarDe(Item $item, Almacen $almacen, ?ItemPresentacion $envase = null): bool
    {
        $lugares = $this->lugaresDe($item);

        if ($lugares === []) {
            return true;
        }

        if (! array_key_exists((int) $almacen->id, $lugares)) {
            return false;
        }

        return ! $envase instanceof ItemPresentacion
            || $this->hayEnvase($lugares[(int) $almacen->id], $envase, $this->envasesDe($item));
    }

    /**
     * Anota que hay del producto en ese almacén. Devuelve si cambió algo.
     *
     *   · Sin `$envases`: si ya estaba, no lo toca; si no, entra de todo.
     *   · Con `$envases`: queda exactamente con esos —una lista vacía es
     *     «solo unidades sueltas»—.
     *
     * @param list<int>|null $envases
     *
     * @throws UbicacionException
     */
    public function ubicar(Item $item, Almacen $almacen, ?array $envases = null): bool
    {
        self::exigirInfinito($item);

        /** @var bool $cambio */
        $cambio = DB::transaction(function () use ($item, $almacen, $envases): bool {
            self::bloquear($item);

            $abierta = self::abiertaEn($item, $almacen);

            if ($envases === null) {
                if ($abierta instanceof UbicacionDeProducto) {
                    return false;
                }

                self::abrir($item, $almacen, null);

                return true;
            }

            return $this->reemplazar($item, $almacen, $abierta, $this->normalizar($item, $envases));
        });

        return $cambio;
    }

    /**
     * Suma envases a lo que ya hay en ese almacén —o lo anota con esos, si
     * no estaba—. Nulo suma todo. Devuelve si cambió algo: sumar el blíster
     * donde ya hay cajas no cambia nada.
     *
     * @param list<int>|null $envases
     *
     * @throws UbicacionException
     */
    public function agregarEnvases(Item $item, Almacen $almacen, ?array $envases): bool
    {
        self::exigirInfinito($item);

        /** @var bool $cambio */
        $cambio = DB::transaction(function () use ($item, $almacen, $envases): bool {
            self::bloquear($item);

            $abierta = self::abiertaEn($item, $almacen);

            if (! $abierta instanceof UbicacionDeProducto) {
                self::abrir($item, $almacen, $envases === null ? null : $this->normalizar($item, $envases));

                return true;
            }

            $antes = self::loQueHay($abierta);

            if ($antes === null) {
                return false;
            }

            $despues = $envases === null ? null : $this->normalizar($item, array_merge($antes, $envases));

            return $this->reemplazar($item, $almacen, $abierta, $despues);
        });

        return $cambio;
    }

    /**
     * Ya no hay del producto en ese almacén. Devuelve si estaba.
     *
     * @throws UbicacionException
     */
    public function retirar(Item $item, Almacen $almacen, string $motivo, bool $aunqueSeaElUltimo = false): bool
    {
        /** @var bool $estaba */
        $estaba = DB::transaction(function () use ($item, $almacen, $motivo, $aunqueSeaElUltimo): bool {
            self::bloquear($item);

            $abiertas = UbicacionDeProducto::query()
                ->deTodasLasSedes()
                ->abiertas()
                ->where('item_id', $item->id)
                ->get();

            $esta = $abiertas->firstWhere('almacen_id', $almacen->id);

            if (! $esta instanceof UbicacionDeProducto) {
                return false;
            }

            if (! $aunqueSeaElUltimo && $abiertas->count() === 1) {
                throw UbicacionException::esElUnicoLugar($item->nombre, $almacen->nombre);
            }

            self::cerrar($esta, $motivo);

            return true;
        });

        return $estaba;
    }

    /**
     * «Sale de FARMACIA, entra al CARRITO ROJO». Devuelve si cambió algo.
     *
     *   · Con `$quedaEnOrigen` —lo de siempre: se llevó una parte— el
     *     producto queda en los dos, y el destino suma lo que se llevó:
     *     `$envase`, o unidades sueltas si es nulo. Lo que se llevó tiene
     *     que haber en el origen.
     *   · Sin él, se llevó TODO: el destino suma lo que había en el
     *     origen, y el origen se cierra.
     *
     * @throws UbicacionException
     */
    public function mover(Item $item, Almacen $origen, Almacen $destino, bool $quedaEnOrigen, ?ItemPresentacion $envase = null): bool
    {
        self::exigirInfinito($item);

        if ((int) $origen->id === (int) $destino->id) {
            throw UbicacionException::mismoAlmacen($origen->nombre);
        }

        if ((int) $origen->sede_id !== (int) $destino->sede_id) {
            throw UbicacionException::otraSede($destino->nombre);
        }

        /** @var bool $cambio */
        $cambio = DB::transaction(function () use ($item, $origen, $destino, $quedaEnOrigen, $envase): bool {
            self::bloquear($item);

            $lugares = $this->lugaresDe($item);
            $enOrigen = array_key_exists((int) $origen->id, $lugares) ? $lugares[(int) $origen->id] : null;

            if (! $quedaEnOrigen) {
                $this->agregarEnvases($item, $destino, $enOrigen);
                $this->retirar($item, $origen, 'SE LLEVÓ TODO A '.$destino->nombre, aunqueSeaElUltimo: true);

                return true;
            }

            if ($envase instanceof ItemPresentacion) {
                if ($envase->item_id !== $item->id) {
                    throw UbicacionException::noHayEseEnvase($item->nombre, self::comoSeLlama($envase), $origen->nombre);
                }

                if (array_key_exists((int) $origen->id, $lugares)
                    && ! $this->hayEnvase($enOrigen, $envase, $this->envasesDe($item))) {
                    throw UbicacionException::noHayEseEnvase($item->nombre, self::comoSeLlama($envase), $origen->nombre);
                }
            }

            return $this->agregarEnvases($item, $destino, $envase instanceof ItemPresentacion ? [(int) $envase->id] : []);
        });

        return $cambio;
    }

    /**
     * Deja el producto exactamente en esos almacenes: lo que eligió la
     * ficha del producto. Lo que ya estaba conserva lo que había; lo nuevo
     * entra de todo. Solo toca los almacenes que quien guarda puede ver
     * —los de otra sede quedan como estaban—.
     *
     * @param list<int> $almacenIds
     *
     * @throws UbicacionException
     */
    public function dejarEn(Item $item, array $almacenIds): void
    {
        $lugares = [];

        foreach ($almacenIds as $id) {
            $lugares[$id] = null;
        }

        $this->dejarConEnvases($item, $lugares);
    }

    /**
     * Lo mismo, diciendo qué hay en cada uno: «Lugares», en Existencias.
     * Nulo deja lo que había —o de todo, si es nuevo—.
     *
     * @param array<int, list<int>|null> $lugares almacén => envases
     *
     * @throws UbicacionException
     */
    public function dejarConEnvases(Item $item, array $lugares): void
    {
        self::exigirInfinito($item);

        DB::transaction(function () use ($item, $lugares): void {
            self::bloquear($item);

            $abiertas = UbicacionDeProducto::query()
                ->abiertas()
                ->where('item_id', $item->id)
                ->get();

            foreach ($abiertas as $ubicacion) {
                if (! array_key_exists($ubicacion->almacen_id, $lugares)) {
                    self::cerrar($ubicacion, self::MOTIVO_DESDE_LA_FICHA);
                }
            }

            foreach (Almacen::query()->whereIn('id', array_keys($lugares))->orderBy('id')->get() as $almacen) {
                $this->ubicar($item, $almacen, $lugares[(int) $almacen->id] ?? null);
            }
        });
    }

    /**
     * Cierra todos sus lugares, en todas las sedes: el producto pasó a
     * stock real y desde ahí está donde esté su saldo.
     */
    public function cerrarTodas(Item $item, string $motivo): int
    {
        /** @var int $cerradas */
        $cerradas = DB::transaction(function () use ($item, $motivo): int {
            self::bloquear($item);

            $abiertas = UbicacionDeProducto::query()
                ->deTodasLasSedes()
                ->abiertas()
                ->where('item_id', $item->id)
                ->get();

            foreach ($abiertas as $ubicacion) {
                self::cerrar($ubicacion, $motivo);
            }

            return $abiertas->count();
        });

        return $cerradas;
    }

    /**
     * Dónde nace un producto nuevo si no se elige otra cosa: las
     * farmacias de la sede de quien lo crea (Mauricio: «por defecto va a
     * farmacia»).
     *
     * @return list<int>
     */
    public function porDefecto(): array
    {
        return array_values(Almacen::query()
            ->vigentes()
            ->whereIn('tipo', [
                TipoAlmacen::FarmaciaVenta->value,
                TipoAlmacen::FarmaciaInterna->value,
                TipoAlmacen::AlmacenUnico->value,
            ])
            ->orderBy('nombre')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * «CAJA X 100», «BLISTER X 10»: el envase dicho corto, como se lee en
     * un chip o en un aviso.
     */
    public static function comoSeLlama(ItemPresentacion $envase): string
    {
        /*
         * Explícito y no perezoso, como en `FormasDeCobro`: quien ya la
         * trajo no paga nada, y quien no, paga una consulta en vez de
         * reventar con la carga perezosa prohibida (§13.2).
         */
        if (! $envase->relationLoaded('unidad')) {
            $envase->load('unidad');
        }

        $unidad = $envase->unidad;
        $codigo = $unidad instanceof Unidad ? $unidad->codigo : $envase->envase();

        return $codigo.' X '.ItemPresentacion::sinCerosDeMas($envase->unidades_por_presentacion);
    }

    /**
     * Los almacenes que marcó la casilla «Dónde hay» del formulario. El
     * navegador los manda como texto («3»); acá se vuelven enteros y se
     * descarta lo que no lo sea.
     *
     * @return list<int>
     */
    public static function idsDelFormulario(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $ids = [];

        foreach ($valor as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Lo que manda «Lugares»: una lista de `{almacen, envases}`. Se
     * descarta lo que no sea un id.
     *
     * @return array<int, list<int>>
     */
    public static function lugaresDelFormulario(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $lugares = [];

        foreach ($valor as $lugar) {
            if (! is_array($lugar)) {
                continue;
            }

            $almacen = self::idsDelFormulario([$lugar['almacen'] ?? null]);

            if ($almacen === []) {
                continue;
            }

            $lugares[$almacen[0]] = self::idsDelFormulario($lugar['envases'] ?? []);
        }

        return $lugares;
    }

    /**
     * Lo marcado, en su forma más corta: solo envases de este producto,
     * sin los que ya trae otro marcado, y «de todo» (nulo) si con eso hay
     * de todo.
     *
     * @param list<int> $ids
     *
     * @return list<int>|null
     */
    private function normalizar(Item $item, array $ids): ?array
    {
        $envases = $this->envasesDe($item);

        $validos = array_values(array_intersect(
            $envases->map(fn (ItemPresentacion $envase): int => (int) $envase->id)->all(),
            array_map('intval', $ids),
        ));

        $marcados = $this->sinLoQueYaTraen($validos, $envases);
        sort($marcados);

        return count($this->disponibles($marcados, $envases)) === $envases->count() ? null : $marcados;
    }

    /**
     * Saca de la lista los envases que ya vienen adentro de otro de la
     * lista: con la caja, el blíster sobra.
     *
     * @param list<int> $ids
     * @param Collection<int, ItemPresentacion> $envases
     *
     * @return list<int>
     */
    private function sinLoQueYaTraen(array $ids, Collection $envases): array
    {
        $quedan = [];

        foreach ($ids as $id) {
            $envase = $envases->firstWhere('id', $id);

            if (! $envase instanceof ItemPresentacion) {
                continue;
            }

            $loTrae = array_diff($this->formas->envasesQueLoTraen($envase, $envases), [$id]);

            if (array_intersect($loTrae, $ids) === []) {
                $quedan[] = $id;
            }
        }

        return $quedan;
    }

    /**
     * Deja el lugar con `$marcados`: si ya decía eso no lo toca; si decía
     * otra cosa, lo cierra y abre otro.
     *
     * @param list<int>|null $marcados
     */
    private function reemplazar(Item $item, Almacen $almacen, ?UbicacionDeProducto $abierta, ?array $marcados): bool
    {
        if ($abierta instanceof UbicacionDeProducto) {
            if (self::loQueHay($abierta) === $marcados) {
                return false;
            }

            self::cerrar($abierta, self::MOTIVO_CAMBIO_LO_QUE_HAY);
        }

        self::abrir($item, $almacen, $marcados);

        return true;
    }

    private static function abiertaEn(Item $item, Almacen $almacen): ?UbicacionDeProducto
    {
        $abierta = UbicacionDeProducto::query()
            ->deTodasLasSedes()
            ->abiertas()
            ->where('item_id', $item->id)
            ->where('almacen_id', $almacen->id)
            ->first();

        return $abierta instanceof UbicacionDeProducto ? $abierta : null;
    }

    /**
     * @param list<int>|null $marcados
     */
    private static function abrir(Item $item, Almacen $almacen, ?array $marcados): void
    {
        $nueva = UbicacionDeProducto::query()->create([
            'sede_id'    => $almacen->sede_id,
            'item_id'    => $item->id,
            'almacen_id' => $almacen->id,
            'desde'      => now(),
            'de_todo'    => $marcados === null,
        ]);

        if ($marcados !== null && $marcados !== []) {
            $nueva->envases()->attach($marcados);
        }
    }

    /**
     * @return list<int>|null
     */
    private static function loQueHay(UbicacionDeProducto $ubicacion): ?array
    {
        if ($ubicacion->de_todo) {
            return null;
        }

        return array_values(DB::table('ubicacion_envases')
            ->where('ubicacion_id', $ubicacion->id)
            ->orderBy('item_presentacion_id')
            ->pluck('item_presentacion_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    private static function contenido(ItemPresentacion $presentacion): Decimal
    {
        $contenido = $presentacion->unidades_por_presentacion;

        return is_numeric($contenido) ? Decimal::de((string) $contenido) : Decimal::cero();
    }

    /**
     * @throws UbicacionException
     */
    private static function exigirInfinito(Item $item): void
    {
        if (! $item->tieneStockInfinito()) {
            throw UbicacionException::noTieneStockInfinito($item->nombre);
        }
    }

    private static function bloquear(Item $item): void
    {
        Item::query()->whereKey($item->id)->lockForUpdate()->first(['id']);
    }

    private static function cerrar(UbicacionDeProducto $ubicacion, string $motivo): void
    {
        $ubicacion->forceFill([
            'hasta'         => now(),
            'motivo_cierre' => mb_substr($motivo, 0, 200),
            'cerrada_por'   => UsuarioAutenticado::id(),
        ])->save();
    }
}
