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
 * seeder sobre la MISMA fila: mismo ítem, `convenio_id` nulo y la misma
 * `vigencia_desde`. No hay que borrar nada.
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
     * Deja el ítem con precio de lista centinela.
     *
     * `updateOrCreate` sobre la misma vigencia y no una fila nueva: el
     * EXCLUDE de traslape de `tarifarios` rechaza dos precios de lista
     * vigentes del mismo ítem, y volver a correr el seeder tiene que
     * poder pasar.
     */
    public static function poner(Item $item, string $vigenciaDesde): void
    {
        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => null,
                'sede_id'        => null,
                'vigencia_desde' => $vigenciaDesde,
            ],
            [
                'precio' => self::PRECIO,
                'motivo' => self::MOTIVO,
            ],
        );
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
