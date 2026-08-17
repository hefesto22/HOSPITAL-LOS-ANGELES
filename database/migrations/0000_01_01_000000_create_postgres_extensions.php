<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensiones de PostgreSQL que el dominio da por sentadas.
 *
 * ¿Por qué una migración y no un `psql -c "CREATE EXTENSION"` a mano?
 *
 * Porque `pest --parallel` crea bases nuevas (hospital_los_angeles_test_1,
 * _test_2, ...) y sobre cada una corre las migraciones desde cero. Una
 * extensión creada a mano en la base de dev NO existe en esas bases: el
 * índice GiST o la búsqueda por trigrama falla ÚNICAMENTE cuando la suite
 * corre en paralelo, que es el peor test intermitente posible — verde en
 * local, rojo en CI, sin patrón aparente.
 *
 * Mismo argumento para el día que se levante la sede 2 o la primera
 * réplica en otra clínica: la base nueva queda lista sin un paso manual
 * que alguien va a olvidar (§1.1).
 *
 * - pg_trgm    → búsqueda tolerante de pacientes por nombre (§8.2). Sin
 *                esto, "Jose Perez" no encuentra a "José Pérez".
 * - btree_gist → combina columnas escalares con rangos en un mismo índice
 *                EXCLUDE. Es lo que hace cumplir "una cama, un paciente a
 *                la vez" y "un ítem, un precio vigente por convenio"
 *                dentro de la base y no en código (§8.5, §12).
 *
 * Va con prefijo 0000_ para correr ANTES de create_users_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // No-op deliberado. Un DROP EXTENSION falla si cualquier índice
        // creado después depende de ella, y dejaría el rollback a medias.
        // Las extensiones se eliminan destruyendo la base, no revirtiendo.
    }
};
