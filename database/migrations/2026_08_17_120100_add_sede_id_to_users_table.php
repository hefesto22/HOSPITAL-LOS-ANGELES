<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sede del usuario.
 *
 * NULLABLE a propósito, por dos razones distintas:
 *
 *  1. `super_admin` y `direccion` cruzan sedes (§9.L5); no pertenecen a
 *     una sola.
 *  2. Esta columna se agrega sobre una tabla que ya tiene filas. Un NOT
 *     NULL sin default rompería la migración, y ponerle un default 1 le
 *     inventaría una sede al administrador que ya existe. El §12 pide
 *     expand/contract: columnas nuevas nullable primero.
 *
 * Los usuarios operativos —caja, farmacia, enfermería, laboratorio— SÍ
 * deben tener sede. Eso se valida en el formulario y en el seeder de
 * roles, no con un NOT NULL que dejaría al sistema sin poder crear al
 * primer administrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->foreignId('sede_id')
                ->nullable()
                ->after('id')
                ->constrained('sedes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('sede_id');
        });
    }
};
