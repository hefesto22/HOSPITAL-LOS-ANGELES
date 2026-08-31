<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La reversa también niega los envases.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CHECK PEDÍA POSITIVO Y LA REVERSA NACE EN NEGATIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Quitar un renglón reventaba con `cargos_envase_completo`: la reversa
 * copiaba el envase pero no cuántos, porque «cuántos» va en el bloque de
 * columnas que se niegan y ahí no estaba. Al agregarla apareció el
 * problema de fondo: `cantidad_presentacion > 0` prohíbe justamente lo
 * que una reversa tiene que ser.
 *
 * Se corrige igual que `cantidad`, que puede ser negativa desde el
 * primer día y solo tiene prohibido el cero: el par original + reversa
 * suma cero envases, que es lo que hace que el renglón desaparezca de la
 * cuenta sin borrar nada.
 *
 * ⚠️ Cero sigue prohibido. Un cargo de cero envases no es una corrección
 * ni una venta: es una fila que no significa nada y que después nadie
 * sabe interpretar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envases_positivos');

        DB::statement(
            'ALTER TABLE cargos
             ADD CONSTRAINT cargos_envases_no_cero
             CHECK (cantidad_presentacion IS NULL OR cantidad_presentacion <> 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envases_no_cero');

        DB::statement(
            'ALTER TABLE cargos
             ADD CONSTRAINT cargos_envases_positivos
             CHECK (cantidad_presentacion IS NULL OR cantidad_presentacion > 0)'
        );
    }
};
