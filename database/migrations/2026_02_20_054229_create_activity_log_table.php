<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de bitácora administrativa — esquema de activitylog 5.x.
 *
 * Reemplaza a las TRES migraciones que publicaba la v4 (create + columna
 * event + columna batch_uuid). Se consolidan en una sola porque el
 * esquema de la v5 cambió y ninguna de las tres se había ejecutado nunca
 * contra una base real.
 *
 * Qué cambió respecto de la v4:
 *
 *  - `attribute_changes` es NUEVA y OBLIGATORIA. La v5 sacó el diff de
 *    atributos de `properties` y le dio columna propia; ActivityLogger
 *    escribe ahí. Sin esta columna, el primer UPDATE sobre un modelo con
 *    LogsActivity revienta con "column attribute_changes does not exist".
 *  - `batch_uuid` DESAPARECE. Cero referencias en el código de la v5.
 *  - `table_name` y `database_connection` YA NO son claves del config de
 *    la v5, así que los nombres van literales. La v4 los leía con config()
 *    y en la v5 devolverían null.
 *
 * Dos desviaciones deliberadas del stub del paquete:
 *
 *  - `jsonb` en vez de `json` (§12). En PostgreSQL, jsonb es más compacto,
 *    se indexa con GIN y se consulta mucho más rápido. El cast 'collection'
 *    del modelo funciona igual con los dos.
 *  - Índice BRIN sobre created_at (§12). La bitácora solo crece y se
 *    inserta en orden cronológico: es el caso ideal de BRIN, que ocupa
 *    kilobytes donde un btree ocuparía cientos de megas a los dos años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX activity_log_created_at_brin ON activity_log USING brin (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
