<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * DÓNDE ESTÁ UN PRODUCTO CON STOCK INFINITO (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «Mover debería estar disponible; si no, ¿cómo se marca qué
 * productos están en el carrito rojo, en farmacia y en bodega? Si vamos
 * a llevar control, es de los productos que están en tales lugares. Al
 * crear un producto, por defecto va a farmacia, pero debe poder
 * seleccionar si se crea en farmacia y bodega. Así, al vender, saben de
 * dónde lo sacaron, y en el historial está claro».
 *
 * Un producto en infinito no tiene existencia —no se cuenta—, pero sí
 * tiene LUGARES. Esta tabla dice en qué almacenes está:
 *
 *   · Una fila por (producto, almacén) mientras esté ahí: `hasta` nulo.
 *   · Sacarlo de un lugar CIERRA la fila —`hasta` con la hora y quién—,
 *     no la borra. Así se puede contestar «¿estaba en el carrito rojo el
 *     sábado?», que es la pregunta que se hace cuando algo salió mal.
 *   · Nunca dos filas abiertas del mismo producto en el mismo almacén:
 *     lo impide un índice único parcial, no el código.
 *
 * No hay cantidad, a propósito: lo que no se cuenta no se finge contar.
 * Cuánto se usó y de dónde lo dice cada cargo (`cargos.almacen_id`).
 *
 * `sede_id` aunque se pueda deducir del almacén: toda tabla transaccional
 * lo lleva desde su primera migración (ADR-0002).
 *
 * Las horas las pone PHP (§7.5-1): ningún DEFAULT now().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicaciones_de_producto', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            $tabla->timestampTz('desde');
            $tabla->timestampTz('hasta')->nullable();
            $tabla->string('motivo_cierre', 200)->nullable();
            $tabla->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestamps();

            $tabla->index('sede_id');
            $tabla->index('item_id');
            $tabla->index('almacen_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX ubicaciones_de_producto_una_abierta
             ON ubicaciones_de_producto (item_id, almacen_id)
             WHERE hasta IS NULL'
        );

        /*
         * La pantalla de Existencias lee las abiertas por almacén: el
         * índice parcial cubre solo lo vigente, que es lo único que se
         * consulta a diario.
         */
        DB::statement(
            'CREATE INDEX ubicaciones_de_producto_abiertas_por_almacen
             ON ubicaciones_de_producto (almacen_id, item_id)
             WHERE hasta IS NULL'
        );

        DB::statement(
            'ALTER TABLE ubicaciones_de_producto
             ADD CONSTRAINT ubicaciones_de_producto_cierra_despues
             CHECK (hasta IS NULL OR hasta >= desde)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones_de_producto');
    }
};
