<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\TipoItem;
use App\Models\MargenObjetivo;
use Illuminate\Database\Seeder;

/**
 * El margen con el que arranca el hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO SE SIEMBRA LO QUE ESTÁ DECIDIDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hay exactamente una decisión tomada, y está fechada: **el margen nunca
 * baja del 120 % en medicamentos, sin importar la edad del paciente ni
 * el descuento legal que le corresponda** (Mauricio, 17-ago-2026).
 *
 * El resto —insumos, material de curación, cada categoría comercial— es
 * el pendiente #2 del §7 de `docs/dominio-inventario-y-precios.md` y
 * sigue sin respuesta. Inventar un número para llenar la tabla sería
 * peor que no tenerlo: nadie revisa un dato que ya parece decidido.
 *
 * Por eso se siembra el DEFAULT de la instalación con el mismo 120 %,
 * tomado de `config/sihla.php`. No es lo mismo que la decisión de
 * medicamentos aunque hoy coincidan en el número: el día que Mauricio
 * defina que los insumos van al 80 %, el default cambia y medicamentos
 * se queda donde está. Los motivos de cada fila dicen cuál es cuál.
 *
 * ⚠️ Para registrar un CAMBIO de margen no se edita este archivo: se le
 * pone `vigencia_hasta` a la fila vieja y se inserta la nueva. Cuando en
 * 2028 alguien pregunte por qué un producto se vendía a ese precio en
 * 2026, la respuesta tiene que ser una fila con fecha.
 */
class MargenesObjetivoSeeder extends Seeder
{
    /**
     * El día que Mauricio fijó la política.
     */
    private const DESDE = '2026-08-17';

    public function run(): void
    {
        $porDefecto = number_format(
            (float) config('sihla.precios.margen_objetivo_por_defecto', 1.20),
            4,
            '.',
            '',
        );

        MargenObjetivo::query()->updateOrCreate(
            ['tipo_item' => null, 'vigencia_desde' => self::DESDE],
            [
                'porcentaje' => $porDefecto,
                'motivo'     => 'Default de la instalación mientras no se defina el margen por '
                                    .'categoría de producto (pendiente #2 del §7 del documento de dominio).',
                'vigencia_hasta' => null,
            ],
        );

        MargenObjetivo::query()->updateOrCreate(
            ['tipo_item' => TipoItem::Medicamento, 'vigencia_desde' => self::DESDE],
            [
                'porcentaje' => '1.2000',
                'motivo'     => 'Decisión de Mauricio del 17-ago-2026: el margen nunca baja del '
                                    .'120 % en medicamentos, sin importar la edad del paciente.',
                'vigencia_hasta' => null,
            ],
        );

        $this->command?->info('✓ Márgenes objetivo sembrados: default y medicamentos, ambos al 120 %.');
        $this->command?->comment('  El margen por categoría de producto sigue pendiente (#2 del §7).');
    }
}
