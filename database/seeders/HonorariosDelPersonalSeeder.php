<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\Enums\TipoItem;
use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Los honorarios de este hospital que son del personal y no de un médico.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Si es honorario por enfermería, solo que salga así: en el reporte
 * pertenece a X turno, sin enfermeras.» Las enfermeras no se registran:
 * el honorario cuenta para el turno o el área que dice el nombre del
 * ítem. Ver `QuienCobraElHonorario`.
 *
 * Son los siete del tarifario del hospital (los códigos que sembró
 * `CatalogoPaligSeeder`). La clínica siguiente marca los suyos desde la
 * ficha del servicio, sin este seeder.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO SE CORRE
 * ─────────────────────────────────────────────────────────────────────
 *
 *     php artisan db:seed --class=HonorariosDelPersonalSeeder
 *
 * No va en `DatabaseSeeder`: son datos de ESTE hospital. Lo llama
 * `CatalogoPaligSeeder` al terminar, así que una base nueva con el
 * catálogo del hospital ya queda marcada.
 *
 * ⚠️ Solo MARCA, nunca desmarca: correrlo dos veces no hace nada, y si
 * alguien marcó otro honorario desde la pantalla, se respeta. Lo cargado
 * antes no hay que tocarlo: el reporte agrupa por el ítem, así que los
 * turnos ya facturados aparecen solos en su turno.
 */
class HonorariosDelPersonalSeeder extends Seeder
{
    /**
     * Código => nombre, solo para que el mensaje diga cuáles marcó.
     *
     * @var array<string, string>
     */
    public const HONORARIOS = [
        'HOS-021' => 'HONORARIOS ENFERMERIA TURNO A',
        'HOS-022' => 'HONORARIOS ENFERMERIA TURNO B',
        'HOS-023' => 'HONORARIOS ENFERMERIA TURNO C',
        'HOS-024' => 'HONORARIOS ENFERMERIA LABOR Y PARTO',
        'HOS-025' => 'HONORARIOS ENFERMERIA RECUPERACION',
        'HOS-026' => 'HONORARIOS CIRCULANTE 2 HORAS',
        'HOS-027' => 'HONORARIOS TECNICO INSTRUMENTISTA 2H',
    ];

    public function run(): void
    {
        $marcados = 0;

        /*
         * Uno por uno y con `save()`, no con un `update()` en bloque: así
         * queda en el registro de actividad quién y cuándo cambió el
         * catálogo, igual que si se hubiera hecho desde la ficha.
         */
        $items = Item::withTrashed()
            ->whereIn('codigo', array_keys(self::HONORARIOS))
            ->where('tipo', TipoItem::Honorario->value)
            ->where('cobra_el_honorario', QuienCobraElHonorario::Medico->value)
            ->orderBy('codigo')
            ->get();

        foreach ($items as $item) {
            $item->cobra_el_honorario = QuienCobraElHonorario::Personal;
            $item->save();
            $marcados++;

            $this->command?->line("  · {$item->codigo} {$item->nombre}");
        }

        $this->command?->info("✓ {$marcados} honorarios marcados como del personal (turno o área).");
    }
}
