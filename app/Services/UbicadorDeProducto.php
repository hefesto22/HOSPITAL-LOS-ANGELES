<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Exceptions\UbicacionException;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\UbicacionDeProducto;
use App\Support\UsuarioAutenticado;
use Illuminate\Support\Facades\DB;

/**
 * En qué almacenes está un producto con stock infinito (13-oct-2026).
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
 * REGLAS
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Solo productos con stock infinito. Uno con existencia real está
 *     donde está su saldo, y se mueve con cantidad (`TrasladadorDeExistencias`).
 *   · Sacarlo de un lugar CIERRA la fila, con hora, motivo y quién; nunca
 *     la borra.
 *   · No se le quita el último lugar a mano: un producto sin ningún
 *     almacén no se sabría de dónde sale. Moverlo con «se llevó todo» sí
 *     puede vaciar el origen, porque deja el destino.
 *   · Un producto sin NINGÚN lugar anotado —los que se marcaron antes de
 *     esta regla— sale de cualquier almacén: antes que trabar un cargo a
 *     las tres de la mañana, se acepta y se anota de dónde salió.
 *
 * Todo bajo candado sobre la fila del producto: dos personas ubicándolo a
 * la vez no dejan dos filas abiertas (lo impide además un índice único).
 */
final class UbicadorDeProducto
{
    public const MOTIVO_DESDE_LA_FICHA = 'SE QUITÓ DESDE LA FICHA DEL PRODUCTO';

    public const MOTIVO_A_MANO = 'YA NO HAY EN ESTE ALMACÉN';

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
     * ¿Se le puede cargar a un paciente sacándolo de ese almacén?
     */
    public function sePuedeSacarDe(Item $item, Almacen $almacen): bool
    {
        $donde = $this->dondeEsta($item);

        return $donde === [] || in_array($almacen->id, $donde, true);
    }

    /**
     * Anota que hay del producto en ese almacén. Si ya estaba, no hace
     * nada. Devuelve si lo anotó.
     *
     * @throws UbicacionException
     */
    public function ubicar(Item $item, Almacen $almacen): bool
    {
        self::exigirInfinito($item);

        /** @var bool $anotado */
        $anotado = DB::transaction(function () use ($item, $almacen): bool {
            self::bloquear($item);

            $yaEsta = UbicacionDeProducto::query()
                ->deTodasLasSedes()
                ->abiertas()
                ->where('item_id', $item->id)
                ->where('almacen_id', $almacen->id)
                ->exists();

            if ($yaEsta) {
                return false;
            }

            UbicacionDeProducto::query()->create([
                'sede_id'    => $almacen->sede_id,
                'item_id'    => $item->id,
                'almacen_id' => $almacen->id,
                'desde'      => now(),
            ]);

            return true;
        });

        return $anotado;
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
     * «Sale de FARMACIA, entra al CARRITO ROJO». Con `$quedaEnOrigen`
     * —lo de siempre: se llevó una parte— el producto queda en los dos;
     * sin él, se llevó todo y el origen se cierra.
     *
     * @throws UbicacionException
     */
    public function mover(Item $item, Almacen $origen, Almacen $destino, bool $quedaEnOrigen): void
    {
        self::exigirInfinito($item);

        if ((int) $origen->id === (int) $destino->id) {
            throw UbicacionException::mismoAlmacen($origen->nombre);
        }

        if ((int) $origen->sede_id !== (int) $destino->sede_id) {
            throw UbicacionException::otraSede($destino->nombre);
        }

        DB::transaction(function () use ($item, $origen, $destino, $quedaEnOrigen): void {
            $this->ubicar($item, $destino);

            if (! $quedaEnOrigen) {
                $this->retirar($item, $origen, 'SE LLEVÓ TODO A '.$destino->nombre, aunqueSeaElUltimo: true);
            }
        });
    }

    /**
     * Deja el producto exactamente en esos almacenes: lo que eligió la
     * ficha del producto. Solo toca los almacenes que quien guarda puede
     * ver —los de otra sede quedan como estaban—.
     *
     * @param list<int> $almacenIds
     *
     * @throws UbicacionException
     */
    public function dejarEn(Item $item, array $almacenIds): void
    {
        self::exigirInfinito($item);

        DB::transaction(function () use ($item, $almacenIds): void {
            self::bloquear($item);

            $abiertas = UbicacionDeProducto::query()
                ->abiertas()
                ->where('item_id', $item->id)
                ->get();

            foreach ($abiertas as $ubicacion) {
                if (! in_array($ubicacion->almacen_id, $almacenIds, true)) {
                    self::cerrar($ubicacion, self::MOTIVO_DESDE_LA_FICHA);
                }
            }

            foreach (Almacen::query()->whereIn('id', $almacenIds)->orderBy('id')->get() as $almacen) {
                $this->ubicar($item, $almacen);
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
