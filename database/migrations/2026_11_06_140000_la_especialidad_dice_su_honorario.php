<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El honorario de cada especialidad.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ (Mauricio, 6-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cirugía general, ortopedia, esas ya tienen precio y se aplican a todos
 * los médicos del seguro según especialidad.» El Militar le paga L 1,000
 * la consulta de cirugía general a cualquier cirujano, y ese precio ya
 * vive en el tarifario del seguro, en el ítem CONSULTA EXTERNA CIRUGIA
 * GENERAL.
 *
 * Lo que faltaba es saber qué ítem es el de cada especialidad. Con esta
 * columna, al buscar a un cirujano por su nombre en la cuenta se carga
 * ese ítem —y su precio de ese seguro, con su vigencia (ADR-0003)— y no
 * el HONORARIO MÉDICO genérico.
 *
 * Es configuración y no código: la clínica siguiente elige sus propios
 * ítems desde la ficha de la especialidad.
 *
 * Nula se acepta: la especialidad sin ítem propio se cobra como HONORARIO
 * MÉDICO, con el monto escrito a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('especialidades', function (Blueprint $tabla): void {
            $tabla->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

            $tabla->index('item_id', 'especialidades_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('especialidades', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('item_id');
        });
    }
};
