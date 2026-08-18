<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenes — dónde vive físicamente el producto (§8.1).
 *
 * ⚠️ `servicio_id` es NULLABLE, y ahí está toda la decisión de diseño.
 *
 * Un almacén puede:
 *   - NO pertenecer a ningún servicio → bodega central, farmacia de venta
 *   - pertenecer a uno              → carro de paro de emergencia,
 *                                     stock de piso de hospitalización
 *
 * Y un servicio puede no tener almacén y consumir del dispensario, que es
 * justo lo que hace el hospital hoy. Fusionar servicio y almacén en una
 * sola entidad no puede representar ninguno de los dos extremos: no hay
 * forma de decir "el dispensario surtió a emergencia" si emergencia *es*
 * el almacén.
 *
 * `sede_id` va además de `servicio_id` a propósito. Es redundante mientras
 * el servicio exista, pero:
 *   - la bodega central no tiene servicio y necesita sede igual
 *   - el scope de BelongsToSede filtra por una columna directa, sin join
 *   - §12 pide índice sobre lo que se consulta, y todo se consulta por sede
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')
                ->constrained('sedes')
                ->restrictOnDelete();

            $tabla->foreignId('servicio_id')
                ->nullable()
                ->constrained('servicios')
                ->restrictOnDelete();

            $tabla->string('codigo', 20);
            $tabla->string('nombre');
            $tabla->string('tipo', 30);

            /*
             * Un almacén de controlados exige recetario especial, libro con
             * saldo corrido y reporte mensual a ARSA. Es propiedad del
             * almacén, no del producto: el mismo medicamento controlado
             * puede estar bajo llave en uno y en anaquel en otro.
             */
            $tabla->boolean('maneja_controlados')->default(false);

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index(['sede_id', 'tipo'], 'almacenes_sede_tipo_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX almacenes_sede_codigo_unique
             ON almacenes (sede_id, codigo)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('almacenes');
    }
};
