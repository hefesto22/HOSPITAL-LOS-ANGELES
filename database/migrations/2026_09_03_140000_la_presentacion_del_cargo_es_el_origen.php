<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La presentación del cargo pasa a significar DE DÓNDE SALIÓ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIA Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta hoy la presentación del cargo significaba «se cobró por envase»,
 * y por eso `cargos_envase_completo` exigía que las dos columnas fueran
 * juntas: si había envase, había cuántos.
 *
 * Planteo de Mauricio (3-sep-2026): *«puede pasar que de la presentación
 * de 100 saquen 5 y de la de 10 saquen 5 — sacan en unidades; si la caja
 * trae 100 y en cantidad colocan 5, quedan 95»*. Tiene razón, y la propia
 * ficha del producto ya lo dice: **«se dispensa en TABLETA»**.
 *
 * Para lo que sale suelto —tabletas, cápsulas, ampollas— la cantidad va
 * SIEMPRE en la unidad de dispensación y la presentación deja de ser una
 * unidad de cobro: pasa a ser el ORIGEN, de qué envase se sacó. Eso es lo
 * que hace falta para contestar «de cuál se está vendiendo» y para que el
 * precio por envase —que ya existe en `tarifarios`— tenga a quién
 * aplicarse.
 *
 * ⚠️ El envase entero NO desaparece: para lo que se mide en volumen o
 * peso el frasco sigue siendo la unidad de cobro, porque un frasco
 * cerrado se entrega entero y de un frasco no se sacan «5» sin abrirlo.
 * Esa distinción vive en el código, mirando la MAGNITUD de la unidad de
 * dispensación.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE EL CHECK SIGUE PROHIBIENDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ahora se permite presentación SIN cantidad de envases —el origen— pero
 * NO al revés: una cantidad de envases sin envase es un número sin
 * unidad, y en un renglón de factura eso se lee como cualquier cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envase_completo');

        DB::statement(
            'ALTER TABLE cargos
             ADD CONSTRAINT cargos_envases_con_su_presentacion
             CHECK (cantidad_presentacion IS NULL OR item_presentacion_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_envases_con_su_presentacion');

        /*
         * El CHECK viejo NO se recrea: si ya hay cargos con la
         * presentación como origen —sin cantidad de envases—, la base los
         * rechazaría y la migración se caería a la mitad.
         */
    }
};
