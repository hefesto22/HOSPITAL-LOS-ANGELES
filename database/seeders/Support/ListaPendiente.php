<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Models\Item;
use App\Models\Tarifario;

/**
 * El precio de lista que TODAVÍA NO EXISTE, escrito de forma que se note.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ L 10 Y NO UN NÚMERO RAZONABLE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los documentos que el hospital tiene cargados —el tarifario de PALIG y
 * la propuesta al Hospital Militar— traen el precio de CADA PAGADOR. No
 * traen el precio de lista, que es lo que paga el paciente particular.
 *
 * La primera versión lo inventó: precio del convenio + 20 %. Ese número
 * es el peor de los mundos. Se ve razonable, nadie lo revisa, y en tres
 * meses es el precio real del hospital porque quedó ahí. Es exactamente
 * el default silencioso que el §9 del CLAUDE.md existe para impedir:
 * nadie audita un dato que ya parece decidido.
 *
 * L 10 no se puede confundir con una decisión. Una habitación en L 10 la
 * ve el primero que abra la pantalla.
 *
 * ⚠️ Y por eso mismo: **mientras un ítem esté así, no se le puede
 * facturar a un paciente de contado.** El centinela protege el catálogo
 * de un precio inventado, no protege la caja de un cobro mal hecho.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO SE ENCUENTRAN DESPUÉS — QUE ES TODO EL PUNTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El centinela sirve si es CONSULTABLE en bloque, no solo visible de a
 * uno. Por eso la marca va en `motivo`, que ya es columna obligatoria
 * del tarifario y sale impresa en cualquier auditoría de precios:
 *
 *     select i.codigo, i.nombre
 *     from tarifarios t join items i on i.id = t.item_id
 *     where t.convenio_id is null
 *       and t.motivo like 'PENDIENTE PRECIO DE LISTA%'
 *       and t.deleted_at is null
 *     order by i.codigo;
 *
 * O desde la aplicación: `ListaPendiente::cuantosFaltan()`.
 *
 * Reemplazar el centinela es cargar el precio real por pantalla o por
 * seeder sobre la MISMA fila: mismo ítem, `convenio_id` nulo. No hay que
 * borrar nada. Desde el código, `precioReal()`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA QUE ESTA CLASE HACE CUMPLIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * **Un ítem tiene como máximo UN precio de lista abierto, y el precio de
 * verdad siempre le gana al centinela.**
 *
 * No es una preferencia de estilo: `tarifarios_sin_traslape` —el EXCLUDE
 * USING gist— rechaza dos precios de lista vigentes del mismo ítem, y
 * rechazarlo es lo correcto (dos precios abiertos es no tener precio).
 *
 * ⚠️ La versión anterior daba por sentado que todos los seeders del
 * catálogo usaban la MISMA `vigencia_desde`, y no era cierto: los de
 * seguros arrancan el 2026-08-01 y la lista de rayos X el 2026-09-01. Un
 * ítem que sale en las dos —hay 39— terminaba con el precio real desde
 * septiembre y el `updateOrCreate` del centinela intentando abrir otra
 * fila desde agosto. La base lo frenó con un 23P01 en medio del seed.
 *
 * El arreglo no es unificar la fecha —eso solo esconde el problema hasta
 * que alguien agregue el tercer documento—: es que **estos dos métodos
 * miren si el ítem ya tiene un precio de lista abierto** y escriban
 * sobre esa fila en vez de abrir una nueva. Así el resultado es el mismo
 * corran en el orden que corran.
 */
final class ListaPendiente
{
    /**
     * El centinela.
     *
     * Texto y no float: entra directo a la columna NUMERIC(14,4) y a
     * bcmath si alguien lo compara (§8.6.2-1).
     */
    public const PRECIO = '10.0000';

    /**
     * Lo que se busca. Va al principio del motivo, sin excepción: si
     * alguien lo mueve al medio, el `like` de arriba deja de encontrarlo.
     */
    public const MARCA = 'PENDIENTE PRECIO DE LISTA';

    /** Cabe en el varchar(255) de `motivo` y pasa su CHECK de 10 caracteres. */
    public const MOTIVO = self::MARCA.' — L 10 es un centinela, no un precio. El hospital aún no fijó '
        .'cuánto cobra de contado por este ítem; lo cargado son los precios de convenio. '
        .'Reemplazar antes de facturar.';

    /**
     * Deja el ítem con precio de lista centinela — si no tiene uno de verdad.
     *
     * Devuelve `false` cuando NO lo puso porque el ítem ya tenía precio
     * real. Ese caso no es un error ni una omisión: es la regla. Un ítem
     * que sale en la lista de rayos X con su precio y también en la
     * propuesta de un seguro tiene precio de lista, y pisarlo con L 10
     * sería perder el único dato firme que hay.
     */
    public static function poner(Item $item, string $vigenciaDesde): bool
    {
        $abierta = self::precioDeListaAbierto($item);

        if ($abierta instanceof Tarifario) {
            if (! self::esCentinela($abierta)) {
                return false;
            }

            /*
             * Ya estaba puesto. Se refresca el texto sobre LA MISMA fila
             * —no se abre otra vigencia— para que volver a correr el
             * seeder sea inofensivo.
             */
            $abierta->update([
                'precio' => self::PRECIO,
                'motivo' => self::MOTIVO,
            ]);

            return true;
        }

        Tarifario::query()->create([
            'item_id'        => $item->id,
            'convenio_id'    => null,
            'sede_id'        => null,
            'vigencia_desde' => $vigenciaDesde,
            'precio'         => self::PRECIO,
            'motivo'         => self::MOTIVO,
        ]);

        return true;
    }

    /**
     * El precio de lista DE VERDAD. Reemplaza al centinela en su misma
     * fila, con lo cual la vigencia que ya tenía el ítem no se mueve y no
     * hay dos precios abiertos ni por un instante.
     *
     * @param numeric-string $precio
     */
    public static function precioReal(Item $item, string $precio, string $motivo, string $vigenciaDesde): void
    {
        $abierta = self::precioDeListaAbierto($item);

        if ($abierta instanceof Tarifario) {
            $abierta->update([
                'precio' => $precio,
                'motivo' => $motivo,
            ]);

            return;
        }

        Tarifario::query()->create([
            'item_id'        => $item->id,
            'convenio_id'    => null,
            'sede_id'        => null,
            'vigencia_desde' => $vigenciaDesde,
            'precio'         => $precio,
            'motivo'         => $motivo,
        ]);
    }

    /**
     * El precio de lista abierto del ítem, si tiene.
     *
     * Lista = `convenio_id` nulo. Y del ÍTEM, no de una presentación:
     * `item_presentacion_id` también nulo, porque una presentación tiene
     * su propia fila y su propio traslape.
     */
    private static function precioDeListaAbierto(Item $item): ?Tarifario
    {
        return Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->whereNull('sede_id')
            ->whereNull('item_presentacion_id')
            ->whereNull('vigencia_hasta')
            ->orderByDesc('vigencia_desde')
            ->first();
    }

    private static function esCentinela(Tarifario $fila): bool
    {
        return str_starts_with((string) $fila->motivo, self::MARCA);
    }

    /** Cuántos ítems siguen esperando su precio de lista de verdad. */
    public static function cuantosFaltan(): int
    {
        return Tarifario::query()
            ->whereNull('convenio_id')
            ->where('motivo', 'like', self::MARCA.'%')
            ->count();
    }
}
